<?php

declare(strict_types=1);

namespace IslamGaber\SafeCheckout\Data;

/** A verified webhook: we only keep who sent it and which payment it is about. */
final class WebhookEvent
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly string $eventId,
        public readonly string $reference,
        public readonly array $raw = [],
    ) {
    }
}
