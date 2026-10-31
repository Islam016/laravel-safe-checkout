# Laravel Safe Checkout

[![tests](https://github.com/Islam016/laravel-safe-checkout/actions/workflows/tests.yml/badge.svg)](https://github.com/Islam016/laravel-safe-checkout/actions/workflows/tests.yml)
![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4)
![Laravel](https://img.shields.io/badge/Laravel-12%2B-FF2D20)
![License](https://img.shields.io/badge/license-MIT-green)

Payments for Laravel with **Moyasar** and **Tap** that are hard to get wrong.

Calling a payment API is the easy part. The bugs that cost money live around it:

| What goes wrong | What this package does |
|---|---|
| The customer double-clicks "Pay", or a request is retried, and two checkouts are opened | `Checkout::start()` takes an **idempotency key**. The same key returns the same payment and the same checkout page. A unique index protects against races. |
| Someone posts a fake "paid" webhook to your endpoint | Webhooks are **verified** (Moyasar secret token, Tap HMAC-SHA256 `hashstring`) and rejected with 403 otherwise. |
| The webhook body is trusted for the status or the amount | The payload is only used to find the payment. Status and amount are always **fetched from the gateway API**. |
| A payment for 1 SAR is accepted for a 1,500 SAR order | The amount and currency the gateway reports must **match** the order exactly, or the payment is failed with `amount_mismatch`. |
| The gateway delivers the same webhook 3 times and the order is fulfilled 3 times | Each delivery is recorded once. `PaymentSucceeded` fires **exactly once**. |
| A late or out-of-order webhook turns a paid order back into failed | A **state machine** makes `paid` and `expired` final. |
| Your server or the gateway API was down when the webhook came | On gateway errors the webhook returns 503 so it is **retried**, and `safe-checkout:reconcile` catches anything that was missed. |
| `0.1 + 0.2` and float rounding | Money is stored as an **integer in halalas** (or fils for KWD/BHD/OMR, with 3 decimals). |

## Installation

```bash
composer require islam016/laravel-safe-checkout
php artisan migrate
```

```dotenv
CHECKOUT_GATEWAY=moyasar
CHECKOUT_SUCCESS_URL=https://your-shop.com/checkout/done

MOYASAR_SECRET_KEY=sk_test_...
MOYASAR_WEBHOOK_SECRET=the-secret-token-you-set-in-the-moyasar-dashboard

TAP_SECRET_KEY=sk_test_...
```

Point the gateway's webhook to:

```
POST https://your-shop.com/checkout/webhooks/moyasar
POST https://your-shop.com/checkout/webhooks/tap     (sent automatically as the charge's post URL)
```

To change the prefix, middleware or table names: `php artisan vendor:publish --tag=safe-checkout-config`.

## Usage

### 1. Start a checkout

```php
use IslamGaber\SafeCheckout\Facades\Checkout;
use IslamGaber\SafeCheckout\Money;

public function pay(Order $order)
{
    $payment = Checkout::start(
        Money::fromMajor($order->total, 'SAR'),   // "150.75" -> 15075 halalas
        idempotencyKey: "order-{$order->id}",     // same order = same checkout, never two
        payable: $order,
        options: ['description' => "Order #{$order->id}"],
    );

    return redirect()->away($payment->checkout_url);
}
```

Tap needs a customer:

```php
Checkout::start(Money::of(15075), "order-{$order->id}", $order, [
    'gateway' => 'tap',
    'customer' => ['first_name' => $user->name, 'email' => $user->email],
]);
```

### 2. Fulfil the order when, and only when, it is really paid

```php
use IslamGaber\SafeCheckout\Events\PaymentSucceeded;
use IslamGaber\SafeCheckout\Events\PaymentFailed;

Event::listen(function (PaymentSucceeded $event) {
    $event->payment->payable->markAsPaid();   // fired exactly once per payment
});

Event::listen(function (PaymentFailed $event) {
    // failed, expired, or amount_mismatch (see $event->payment->failure_reason)
});
```

### 3. On the page the customer returns to

The redirect itself proves nothing, so confirm with the gateway:

```php
$payment = Checkout::reconcile(Payment::where('uuid', $uuid)->firstOrFail());

return $payment->isPaid() ? view('checkout.thanks') : view('checkout.pending');
```

### 4. Safety net for missed webhooks

```php
// routes/console.php
Schedule::command('safe-checkout:reconcile --older-than=10')->everyFiveMinutes();
```

### Queue mode

Set `CHECKOUT_QUEUE=true` to answer webhooks immediately and reconcile in a queued job (5 tries with backoff).

## How a payment moves

```
pending ──► paid      (final)
   │  └──► expired   (final)
   └────► failed ──► paid   (customer retried on the same hosted page)
                └──► expired
```

## Adding another gateway

```php
Checkout::extend('hyperpay', fn (string $name, array $config) => new HyperPayGateway($name, $config));
```

Implement `IslamGaber\SafeCheckout\Contracts\Gateway` (`createCheckout`, `fetch`, `parseWebhook`) and add a `gateways.hyperpay` entry with `'driver' => 'hyperpay'` to the config. Everything else (idempotency, verification flow, amount checks, state machine, events) comes for free.

## Testing

```bash
composer test
```

Every flow above has a test with faked gateway responses: double clicks, forged and tampered webhooks, replayed deliveries, wrong amounts, stale responses, gateway outages and missed webhooks. CI runs on PHP 8.2–8.4 with SQLite, and on real MySQL 8.4 and PostgreSQL 17.

## Notes

- Moyasar: hosted invoices (`POST /v1/invoices`), Basic auth with the secret key, amounts in halalas.
- Tap: hosted charges (`POST /v2/charges`, source `src_all` by default), Bearer auth, amounts in major units.
- Refunds and saved cards are not covered yet.

## License

MIT © [Islam Gaber](https://github.com/Islam016)
