<?php

declare(strict_types=1);

namespace IslamGaber\SafeCheckout\Events;

use Illuminate\Foundation\Events\Dispatchable;
use IslamGaber\SafeCheckout\Models\Payment;

/** Fired exactly once, when a payment is confirmed by the gateway API with the right amount. */
final class PaymentSucceeded
{
    use Dispatchable;

    public function __construct(public readonly Payment $payment)
    {
    }
}
