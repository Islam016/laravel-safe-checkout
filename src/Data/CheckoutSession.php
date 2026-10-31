<?php

declare(strict_types=1);

namespace IslamGaber\SafeCheckout\Data;

/** What a gateway returns after creating a hosted checkout. */
final class CheckoutSession
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly string $reference,
        public readonly string $redirectUrl,
        public readonly array $raw = [],
    ) {
    }
}
