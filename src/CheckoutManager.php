<?php

declare(strict_types=1);

namespace IslamGaber\SafeCheckout;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use IslamGaber\SafeCheckout\Contracts\Gateway;
use IslamGaber\SafeCheckout\Events\PaymentFailed;
use IslamGaber\SafeCheckout\Events\PaymentSucceeded;
use IslamGaber\SafeCheckout\Exceptions\IdempotencyConflict;
use IslamGaber\SafeCheckout\Exceptions\UnknownGateway;
use IslamGaber\SafeCheckout\Gateways\MoyasarGateway;
use IslamGaber\SafeCheckout\Gateways\TapGateway;
use IslamGaber\SafeCheckout\Jobs\ReconcilePayment;
use IslamGaber\SafeCheckout\Models\Payment;
use IslamGaber\SafeCheckout\Models\ProcessedWebhook;

class CheckoutManager
{
    /** @var array<string, Gateway> */
    private array $gateways = [];

    /** @var array<string, Closure(string, array<string, mixed>, Container): Gateway> */
    private array $customDrivers = [];

    public function __construct(private readonly Container $container)
    {
    }

    /**
     * Start a checkout and return the payment; send the customer to $payment->checkout_url.
     *
     * Calling it again with the same idempotency key (a double click, a retried request,
     * two tabs) returns the same payment and the same checkout page instead of a new charge.
     *
     * Options: gateway, description, success_url, back_url, metadata, customer (Tap), order,
     * and anything a custom driver understands.
     *
     * @param  array<string, mixed>  $options
     */
    public function start(Money $amount, ?string $idempotencyKey = null, ?Model $payable = null, array $options = []): Payment
    {
        $gateway = $this->gateway($options['gateway'] ?? null);

        if ($amount->minor === 0) {
            throw new InvalidArgumentException('Cannot start a checkout for a zero amount.');
        }

        $payment = $idempotencyKey !== null ? $this->findForKey($idempotencyKey, $amount, $gateway) : null;

        if ($payment === null) {
            try {
                $payment = Payment::create([
                    'gateway' => $gateway->name(),
                    'idempotency_key' => $idempotencyKey,
                    'amount' => $amount->minor,
                    'currency' => $amount->currency,
                    'description' => $options['description'] ?? null,
                    'metadata' => $options['metadata'] ?? null,
                    'payable_type' => $payable?->getMorphClass(),
                    'payable_id' => $payable?->getKey(),
                ]);
            } catch (UniqueConstraintViolationException $e) {
                // Another request with the same key won the race; use its payment.
                $payment = $idempotencyKey !== null ? $this->findForKey($idempotencyKey, $amount, $gateway) : null;

                if ($payment === null) {
                    throw $e;
                }
            }
        }

        if ($payment->checkout_url !== null || $payment->status !== PaymentStatus::Pending) {
            return $payment;
        }

        $session = $gateway->createCheckout($payment, $options);

        $payment->forceFill([
            'gateway_reference' => $session->reference,
            'checkout_url' => $session->redirectUrl,
        ])->save();

        return $payment;
    }

    /**
     * Fetch the payment from the gateway and apply its status. The amount and currency the
     * gateway reports must match what we asked for, or the payment is never marked paid.
     */
    public function reconcile(Payment $payment): Payment
    {
        if ($payment->gateway_reference === null) {
            return $payment;
        }

        $remote = $this->gateway($payment->gateway)->fetch($payment->gateway_reference);

        $event = null;

        $payment = DB::transaction(function () use ($payment, $remote, &$event): Payment {
            /** @var Payment $locked */
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->getKey());

            $target = $remote->status;
            $reason = $target === PaymentStatus::Failed ? ($remote->message ?? 'failed') : null;

            if ($target === PaymentStatus::Paid && ! $remote->amount->equals($locked->money())) {
                $target = PaymentStatus::Failed;
                $reason = sprintf('amount_mismatch: expected %s, gateway reported %s', $locked->money(), $remote->amount);
            }

            if ($target === $locked->status || ! $locked->status->canTransitionTo($target)) {
                return $locked;
            }

            $locked->forceFill([
                'status' => $target,
                'failure_reason' => $target === PaymentStatus::Paid ? null : $reason,
                'paid_at' => $target === PaymentStatus::Paid ? now() : $locked->paid_at,
            ])->save();

            $event = match ($target) {
                PaymentStatus::Paid => new PaymentSucceeded($locked),
                PaymentStatus::Failed, PaymentStatus::Expired => new PaymentFailed($locked),
                default => null,
            };

            return $locked;
        });

        if ($event !== null) {
            event($event);
        }

        return $payment;
    }

    /**
     * Verify and handle a webhook. Returns what happened: processed, queued, duplicate or unknown_payment.
     */
    public function handleWebhook(string $gatewayName, Request $request): string
    {
        $gateway = $this->gateway($gatewayName);
        $event = $gateway->parseWebhook($request);

        $seen = ProcessedWebhook::query()
            ->where('gateway', $gateway->name())
            ->where('event_id', $event->eventId)
            ->exists();

        if ($seen) {
            return 'duplicate';
        }

        $payment = Payment::query()
            ->where('gateway', $gateway->name())
            ->where('gateway_reference', $event->reference)
            ->first();

        if ($payment === null) {
            $this->remember($gateway->name(), $event->eventId, $event->reference);

            return 'unknown_payment';
        }

        if (config('safe-checkout.queue.enabled')) {
            $this->remember($gateway->name(), $event->eventId, $event->reference);

            ReconcilePayment::dispatch($payment->getKey())
                ->onConnection(config('safe-checkout.queue.connection'))
                ->onQueue(config('safe-checkout.queue.name'));

            return 'queued';
        }

        // Reconcile first and record afterwards: if the gateway API is down we throw,
        // the webhook gets a 5xx and the gateway delivers it again later.
        $this->reconcile($payment);

        return $this->remember($gateway->name(), $event->eventId, $event->reference) ? 'processed' : 'duplicate';
    }

    public function gateway(?string $name = null): Gateway
    {
        $name ??= (string) config('safe-checkout.default');

        return $this->gateways[$name] ??= $this->resolve($name);
    }

    /**
     * Register a custom driver:
     * Checkout::extend('hyperpay', fn (string $name, array $config) => new HyperPayGateway($name, $config));
     *
     * @param  Closure(string, array<string, mixed>, Container): Gateway  $factory
     */
    public function extend(string $driver, Closure $factory): static
    {
        $this->customDrivers[$driver] = $factory;

        return $this;
    }

    private function resolve(string $name): Gateway
    {
        $config = config("safe-checkout.gateways.{$name}");

        if (! is_array($config)) {
            throw UnknownGateway::named($name);
        }

        $driver = (string) ($config['driver'] ?? $name);

        if (isset($this->customDrivers[$driver])) {
            return ($this->customDrivers[$driver])($name, $config, $this->container);
        }

        return match ($driver) {
            'moyasar' => new MoyasarGateway($name, $config),
            'tap' => new TapGateway($name, $config),
            default => throw UnknownGateway::named($name),
        };
    }

    private function findForKey(string $key, Money $amount, Gateway $gateway): ?Payment
    {
        $payment = Payment::query()->where('idempotency_key', $key)->first();

        if ($payment !== null && (! $payment->money()->equals($amount) || $payment->gateway !== $gateway->name())) {
            throw IdempotencyConflict::for($payment, $key);
        }

        return $payment;
    }

    private function remember(string $gateway, string $eventId, string $reference): bool
    {
        try {
            ProcessedWebhook::query()->create([
                'gateway' => $gateway,
                'event_id' => $eventId,
                'reference' => $reference,
            ]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }
}
