<?php

declare(strict_types=1);

namespace IslamGaber\SafeCheckout\Models;

use Illuminate\Database\Eloquent\Model;

/** One row per webhook delivery we have already handled, so replays are ignored. */
class ProcessedWebhook extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    public function getTable(): string
    {
        return config('safe-checkout.tables.webhooks', 'checkout_webhook_events');
    }
}
