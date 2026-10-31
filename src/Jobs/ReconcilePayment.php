<?php

declare(strict_types=1);

namespace IslamGaber\SafeCheckout\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use IslamGaber\SafeCheckout\CheckoutManager;
use IslamGaber\SafeCheckout\Models\Payment;

final class ReconcilePayment implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300, 900];

    public function __construct(public readonly int $paymentId)
    {
    }

    public function handle(CheckoutManager $checkout): void
    {
        $payment = Payment::query()->find($this->paymentId);

        if ($payment !== null) {
            $checkout->reconcile($payment);
        }
    }
}
