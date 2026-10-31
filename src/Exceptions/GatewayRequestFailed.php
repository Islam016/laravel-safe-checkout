<?php

declare(strict_types=1);

namespace IslamGaber\SafeCheckout\Exceptions;

use Illuminate\Http\Client\Response;

final class GatewayRequestFailed extends SafeCheckoutException
{
    public static function fromResponse(string $gateway, string $action, Response $response): self
    {
        return new self(sprintf('%s %s failed with HTTP %d: %s', $gateway, $action, $response->status(), mb_substr($response->body(), 0, 300)));
    }

    public static function invalidResponse(string $gateway, string $action, string $missing): self
    {
        return new self(sprintf('%s %s returned a response without [%s].', $gateway, $action, $missing));
    }
}
