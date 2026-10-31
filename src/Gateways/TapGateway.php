<?php

declare(strict_types=1);

namespace IslamGaber\SafeCheckout\Gateways;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use InvalidArgumentException;
use IslamGaber\SafeCheckout\Data\CheckoutSession;
use IslamGaber\SafeCheckout\Data\GatewayPayment;
use IslamGaber\SafeCheckout\Data\WebhookEvent;
use IslamGaber\SafeCheckout\Exceptions\GatewayRequestFailed;
use IslamGaber\SafeCheckout\Exceptions\InvalidWebhook;
use IslamGaber\SafeCheckout\Models\Payment;
use IslamGaber\SafeCheckout\Money;
use IslamGaber\SafeCheckout\PaymentStatus;

/**
 * Tap charges with a hosted page. Amounts are sent in major units ("150.75");
 * the charge id is the reference we reconcile against.
 */
class TapGateway extends HttpGateway
{
    private const FAILED = ['ABANDONED', 'CANCELLED', 'FAILED', 'DECLINED', 'RESTRICTED', 'VOID', 'TIMEDOUT', 'UNKNOWN'];

    protected function defaultBaseUrl(): string
    {
        return 'https://api.tap.company/v2';
    }

    protected function authenticate(PendingRequest $request): PendingRequest
    {
        return $request->withToken($this->secretKey());
    }

    public function createCheckout(Payment $payment, array $options = []): CheckoutSession
    {
        $customer = $options['customer'] ?? null;

        if (! is_array($customer) || empty($customer['first_name']) || (empty($customer['email']) && empty($customer['phone']))) {
            throw new InvalidArgumentException("Tap needs a 'customer' option with first_name and an email or phone.");
        }

        $payload = array_filter([
            'amount' => (float) $payment->money()->toMajorString(),
            'currency' => $payment->currency,
            'description' => $options['description'] ?? $payment->description,
            'customer' => $customer,
            'source' => ['id' => $options['source'] ?? $this->config['source'] ?? 'src_all'],
            'redirect' => ['url' => $this->successUrl($options) ?? url('/')],
            'post' => ['url' => $options['webhook_url'] ?? route('safe-checkout.webhook', ['gateway' => $this->name])],
            'reference' => [
                'transaction' => $payment->uuid,
                'order' => (string) ($options['order'] ?? $payment->uuid),
            ],
            'metadata' => array_merge($options['metadata'] ?? [], ['payment_uuid' => $payment->uuid]),
        ], fn ($value) => $value !== null);

        $data = $this->post('charges', $payload, 'create charge')->json();

        if (empty($data['id']) || empty($data['transaction']['url'])) {
            throw GatewayRequestFailed::invalidResponse($this->name, 'create charge', empty($data['id']) ? 'id' : 'transaction.url');
        }

        return new CheckoutSession((string) $data['id'], (string) $data['transaction']['url'], $data);
    }

    public function fetch(string $reference): GatewayPayment
    {
        $data = $this->get('charges/'.rawurlencode($reference), 'retrieve charge')->json();

        if (! isset($data['status'], $data['amount'], $data['currency'])) {
            throw GatewayRequestFailed::invalidResponse($this->name, 'retrieve charge', 'status/amount/currency');
        }

        $status = strtoupper((string) $data['status']);

        return new GatewayPayment(
            reference: (string) $data['id'],
            status: match (true) {
                $status === 'CAPTURED' => PaymentStatus::Paid,
                in_array($status, self::FAILED, true) => PaymentStatus::Failed,
                default => PaymentStatus::Pending, // INITIATED, IN_PROGRESS
            },
            amount: Money::fromMajor(is_string($data['amount']) ? $data['amount'] : (float) $data['amount'], (string) $data['currency']),
            message: $data['response']['message'] ?? null,
            raw: $data,
        );
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        $charge = $request->json()->all();
        $received = (string) $request->header('hashstring', '');

        if ($received === '' || empty($charge['id']) || ! isset($charge['amount'], $charge['currency'], $charge['status'])) {
            throw new InvalidWebhook('Tap webhook is missing the hashstring header or charge fields.');
        }

        if (! hash_equals(self::hashString($charge, $this->secretKey()), $received)) {
            throw new InvalidWebhook('Tap webhook hashstring does not match.');
        }

        // Tap posts the charge itself; the same charge in the same status is a duplicate delivery.
        return new WebhookEvent($charge['id'].':'.strtoupper((string) $charge['status']), (string) $charge['id'], $charge);
    }

    /**
     * Tap signs charge webhooks with HMAC-SHA256 (key: your secret key) over
     * x_id, x_amount (rounded to the currency's decimals), x_currency, x_gateway_reference,
     * x_payment_reference, x_status and x_created, concatenated in that order.
     *
     * @param  array<string, mixed>  $charge
     */
    public static function hashString(array $charge, string $secretKey): string
    {
        $currency = (string) $charge['currency'];
        $amount = number_format((float) $charge['amount'], Money::decimalsFor($currency), '.', '');

        $toHash = 'x_id'.$charge['id']
            .'x_amount'.$amount
            .'x_currency'.$currency
            .'x_gateway_reference'.($charge['reference']['gateway'] ?? '')
            .'x_payment_reference'.($charge['reference']['payment'] ?? '')
            .'x_status'.$charge['status']
            .'x_created'.($charge['transaction']['created'] ?? '');

        return hash_hmac('sha256', $toHash, $secretKey);
    }
}
