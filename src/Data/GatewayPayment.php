<?php

declare(strict_types=1);

namespace IslamGaber\SafeCheckout\Data;

use IslamGaber\SafeCheckout\Money;
use IslamGaber\SafeCheckout\PaymentStatus;

/** The payment as the gateway reports it, fetched directly from the gateway's API. */
final class GatewayPayment
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly string $reference,
        public readonly PaymentStatus $status,
        public readonly Money $amount,
        public readonly ?string $message = null,
        public readonly array $raw = [],
    ) {
    }
}
