<?php

declare(strict_types=1);

namespace IslamGaber\SafeCheckout\Contracts;

use Illuminate\Http\Request;
use IslamGaber\SafeCheckout\Data\CheckoutSession;
use IslamGaber\SafeCheckout\Data\GatewayPayment;
use IslamGaber\SafeCheckout\Data\WebhookEvent;
use IslamGaber\SafeCheckout\Exceptions\GatewayRequestFailed;
use IslamGaber\SafeCheckout\Exceptions\InvalidWebhook;
use IslamGaber\SafeCheckout\Models\Payment;

interface Gateway
{
    public function name(): string;

    /**
     * Create a hosted checkout for the payment and return where to send the customer.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws GatewayRequestFailed
     */
    public function createCheckout(Payment $payment, array $options = []): CheckoutSession;

    /**
     * Read the payment's real status and amount from the gateway's API.
     *
     * @throws GatewayRequestFailed
     */
    public function fetch(string $reference): GatewayPayment;

    /**
     * Verify the webhook really came from the gateway and extract the payment reference.
     * The payload is never trusted for status or amount; those are always fetched.
     *
     * @throws InvalidWebhook
     */
    public function parseWebhook(Request $request): WebhookEvent;
}
