<?php

declare(strict_types=1);

namespace IslamGaber\SafeCheckout\Facades;

use Illuminate\Support\Facades\Facade;
use IslamGaber\SafeCheckout\CheckoutManager;

/**
 * @method static \IslamGaber\SafeCheckout\Models\Payment start(\IslamGaber\SafeCheckout\Money $amount, ?string $idempotencyKey = null, ?\Illuminate\Database\Eloquent\Model $payable = null, array $options = [])
 * @method static \IslamGaber\SafeCheckout\Models\Payment reconcile(\IslamGaber\SafeCheckout\Models\Payment $payment)
 * @method static string handleWebhook(string $gateway, \Illuminate\Http\Request $request)
 * @method static \IslamGaber\SafeCheckout\Contracts\Gateway gateway(?string $name = null)
 * @method static CheckoutManager extend(string $driver, \Closure $factory)
 *
 * @see CheckoutManager
 */
final class Checkout extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return CheckoutManager::class;
    }
}
