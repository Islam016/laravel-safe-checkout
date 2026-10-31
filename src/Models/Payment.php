<?php

declare(strict_types=1);

namespace IslamGaber\SafeCheckout\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use IslamGaber\SafeCheckout\Money;
use IslamGaber\SafeCheckout\PaymentStatus;

/**
 * @property int $id
 * @property string $uuid
 * @property string $gateway
 * @property string|null $gateway_reference
 * @property string|null $idempotency_key
 * @property int $amount
 * @property string $currency
 * @property PaymentStatus $status
 * @property string|null $checkout_url
 * @property string|null $description
 * @property string|null $failure_reason
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $paid_at
 * @property Carbon $created_at
 */
class Payment extends Model
{
    protected $guarded = ['id'];

    protected $attributes = [
        'status' => 'pending',
    ];

    public function getTable(): string
    {
        return config('safe-checkout.tables.payments', 'checkout_payments');
    }

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'status' => PaymentStatus::class,
            'metadata' => 'array',
            'paid_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $payment): void {
            $payment->uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function money(): Money
    {
        return Money::of($this->amount, $this->currency);
    }

    public function isPaid(): bool
    {
        return $this->status === PaymentStatus::Paid;
    }

    /** @param Builder<self> $query */
    public function scopePending(Builder $query): void
    {
        $query->where('status', PaymentStatus::Pending->value);
    }
}
