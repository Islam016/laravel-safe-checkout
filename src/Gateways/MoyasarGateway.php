<?php

declare(strict_types=1);

namespace IslamGaber\SafeCheckout\Gateways;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use IslamGaber\SafeCheckout\Data\CheckoutSession;
use IslamGaber\SafeCheckout\Data\GatewayPayment;
use IslamGaber\SafeCheckout\Data\WebhookEvent;
use IslamGaber\SafeCheckout\Exceptions\GatewayRequestFailed;
use IslamGaber\SafeCheckout\Exceptions\InvalidWebhook;
use IslamGaber\SafeCheckout\Models\Payment;
use IslamGaber\SafeCheckout\Money;
use IslamGaber\SafeCheckout\PaymentStatus;

/**
 * Moyasar hosted invoices (mada, Visa/Mastercard, Apple Pay, STC Pay on one page).
 * Amounts are sent in halalas; the invoice id is the reference we reconcile against.
 */
class MoyasarGateway extends HttpGateway
{
    protected function defaultBaseUrl(): string
    {
        return 'https://api.moyasar.com/v1';
    }

    protected function authenticate(PendingRequest $request): PendingRequest
    {
        // Moyasar uses HTTP Basic auth with the secret key as the username and an empty password.
        return $request->withBasicAuth($this->secretKey(), '');
    }

    public function createCheckout(Payment $payment, array $options = []): CheckoutSession
    {
        $payload = array_filter([
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'description' => $options['description'] ?? $payment->description ?? "Payment {$payment->uuid}",
            'success_url' => $this->successUrl($options),
            'back_url' => $options['back_url'] ?? config('safe-checkout.back_url'),
            'expired_at' => $options['expired_at'] ?? null,
            'metadata' => array_merge($options['metadata'] ?? [], ['payment_uuid' => $payment->uuid]),
        ], fn ($value) => $value !== null);

        $data = $this->post('invoices', $payload, 'create invoice')->json();

        if (empty($data['id']) || empty($data['url'])) {
            throw GatewayRequestFailed::invalidResponse($this->name, 'create invoice', empty($data['id']) ? 'id' : 'url');
        }

        return new CheckoutSession((string) $data['id'], (string) $data['url'], $data);
    }

    public function fetch(string $reference): GatewayPayment
    {
        $data = $this->get('invoices/'.rawurlencode($reference), 'fetch invoice')->json();

        if (! isset($data['status'], $data['amount'], $data['currency'])) {
            throw GatewayRequestFailed::invalidResponse($this->name, 'fetch invoice', 'status/amount/currency');
        }

        $status = match ($data['status']) {
            'paid' => PaymentStatus::Paid,
            'expired' => PaymentStatus::Expired,
            'canceled', 'cancelled', 'failed' => PaymentStatus::Failed,
            default => PaymentStatus::Pending, // initiated: the customer can still pay or retry
        };

        $lastPayment = is_array($data['payments'] ?? null) ? end($data['payments']) : null;

        return new GatewayPayment(
            reference: (string) $data['id'],
            status: $status,
            amount: Money::of((int) $data['amount'], (string) $data['currency']),
            message: is_array($lastPayment) ? ($lastPayment['source']['message'] ?? null) : null,
            raw: $data,
        );
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        $payload = $request->json()->all();
        $secret = (string) ($this->config['webhook_secret'] ?? '');

        if ($secret === '') {
            throw new InvalidWebhook("Set webhook_secret for the [{$this->name}] gateway before accepting webhooks.");
        }

        if (! hash_equals($secret, (string) ($payload['secret_token'] ?? ''))) {
            throw new InvalidWebhook('Moyasar webhook secret token does not match.');
        }

        $payment = $payload['data'] ?? [];
        $reference = $payment['invoice_id'] ?? $payment['id'] ?? null;

        if (empty($payload['id']) || empty($reference)) {
            throw new InvalidWebhook('Moyasar webhook is missing the event id or the payment reference.');
        }

        return new WebhookEvent((string) $payload['id'], (string) $reference, $payload);
    }
}
