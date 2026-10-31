<?php

declare(strict_types=1);

namespace IslamGaber\SafeCheckout;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Expired = 'expired';

    /**
     * Allowed moves. A paid or expired payment never changes again, so a late,
     * replayed or out-of-order webhook can't undo a payment or reopen an old one.
     * Failed can still become paid, because hosted pages let the customer retry.
     */
    public function canTransitionTo(self $to): bool
    {
        return match ($this) {
            self::Pending => $to !== self::Pending,
            self::Failed => in_array($to, [self::Paid, self::Expired], true),
            self::Paid, self::Expired => false,
        };
    }

    public function isFinal(): bool
    {
        return $this === self::Paid || $this === self::Expired;
    }
}
