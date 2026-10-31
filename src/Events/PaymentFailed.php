<?php

declare(strict_types=1);

namespace IslamGaber\SafeCheckout\Events;

use Illuminate\Foundation\Events\Dispatchable;
use IslamGaber\SafeCheckout\Models\Payment;

/** Fired when a payment fails, expires, or the gateway reports a different amount. */
final class PaymentFailed
{
    use Dispatchable;

    public function __construct(public readonly Payment $payment)
    {
    }
}
