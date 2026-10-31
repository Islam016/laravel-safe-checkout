<?php

declare(strict_types=1);

namespace IslamGaber\SafeCheckout\Exceptions;

use IslamGaber\SafeCheckout\Models\Payment;

/** The same idempotency key was reused for a different amount, currency or gateway. */
final class IdempotencyConflict extends SafeCheckoutException
{
    public static function for(Payment $existing, string $key): self
    {
        return new self(sprintf(
            'Idempotency key [%s] already belongs to payment %s (%s via %s).',
            $key,
            $existing->uuid,
            (string) $existing->money(),
            $existing->gateway,
        ));
    }
}
