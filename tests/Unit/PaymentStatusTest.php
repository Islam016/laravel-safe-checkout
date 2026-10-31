<?php

declare(strict_types=1);

namespace IslamGaber\SafeCheckout\Tests\Unit;

use IslamGaber\SafeCheckout\PaymentStatus;
use PHPUnit\Framework\TestCase;

final class PaymentStatusTest extends TestCase
{
    public function test_paid_is_final(): void
    {
        foreach (PaymentStatus::cases() as $to) {
            self::assertFalse(PaymentStatus::Paid->canTransitionTo($to), "paid -> {$to->value}");
        }
    }

    public function test_pending_can_move_anywhere_else(): void
    {
        self::assertTrue(PaymentStatus::Pending->canTransitionTo(PaymentStatus::Paid));
        self::assertTrue(PaymentStatus::Pending->canTransitionTo(PaymentStatus::Failed));
        self::assertTrue(PaymentStatus::Pending->canTransitionTo(PaymentStatus::Expired));
        self::assertFalse(PaymentStatus::Pending->canTransitionTo(PaymentStatus::Pending));
    }

    public function test_failed_can_still_be_paid_after_a_retry(): void
    {
        self::assertTrue(PaymentStatus::Failed->canTransitionTo(PaymentStatus::Paid));
        self::assertFalse(PaymentStatus::Failed->canTransitionTo(PaymentStatus::Pending));
    }

    public function test_expired_is_final(): void
    {
        self::assertFalse(PaymentStatus::Expired->canTransitionTo(PaymentStatus::Paid));
        self::assertTrue(PaymentStatus::Expired->isFinal());
    }
}
