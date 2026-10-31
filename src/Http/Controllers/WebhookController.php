<?php

declare(strict_types=1);

namespace IslamGaber\SafeCheckout\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use IslamGaber\SafeCheckout\CheckoutManager;
use IslamGaber\SafeCheckout\Exceptions\GatewayRequestFailed;
use IslamGaber\SafeCheckout\Exceptions\InvalidWebhook;
use IslamGaber\SafeCheckout\Exceptions\UnknownGateway;

final class WebhookController
{
    public function __invoke(Request $request, string $gateway, CheckoutManager $checkout): JsonResponse
    {
        try {
            $result = $checkout->handleWebhook($gateway, $request);
        } catch (UnknownGateway) {
            return new JsonResponse(['error' => 'unknown_gateway'], 404);
        } catch (InvalidWebhook $e) {
            report($e);

            return new JsonResponse(['error' => 'invalid_webhook'], 403);
        } catch (GatewayRequestFailed $e) {
            report($e);

            // A 5xx makes the gateway deliver the webhook again later.
            return new JsonResponse(['error' => 'gateway_unavailable'], 503);
        }

        return new JsonResponse(['status' => $result]);
    }
}
