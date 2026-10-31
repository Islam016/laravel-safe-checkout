<?php

declare(strict_types=1);

namespace IslamGaber\SafeCheckout\Console;

use Illuminate\Console\Command;
use IslamGaber\SafeCheckout\CheckoutManager;
use IslamGaber\SafeCheckout\Models\Payment;
use Throwable;

/** Safety net for lost webhooks: schedule it every few minutes. */
final class ReconcilePendingCommand extends Command
{
    protected $signature = 'safe-checkout:reconcile
        {--older-than=10 : Only payments created at least this many minutes ago}
        {--limit=100 : Maximum payments to check in one run}';

    protected $description = 'Check pending payments with the gateway and update their status';

    public function handle(CheckoutManager $checkout): int
    {
        $payments = Payment::query()
            ->pending()
            ->whereNotNull('gateway_reference')
            ->where('created_at', '<=', now()->subMinutes((int) $this->option('older-than')))
            ->oldest()
            ->limit((int) $this->option('limit'))
            ->get();

        $changed = 0;
        $failed = 0;

        foreach ($payments as $payment) {
            try {
                if ($checkout->reconcile($payment)->status !== $payment->status) {
                    $changed++;
                }
            } catch (Throwable $e) {
                $failed++;
                report($e);
            }
        }

        $this->info("Checked {$payments->count()} payment(s): {$changed} updated, {$failed} error(s).");

        return self::SUCCESS;
    }
}
