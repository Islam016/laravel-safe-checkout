<?php

declare(strict_types=1);

namespace IslamGaber\SafeCheckout\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use IslamGaber\SafeCheckout\Events\PaymentFailed;
use IslamGaber\SafeCheckout\Events\PaymentSucceeded;
use IslamGaber\SafeCheckout\Exceptions\IdempotencyConflict;
use IslamGaber\SafeCheckout\Facades\Checkout;
use IslamGaber\SafeCheckout\Models\Payment;
use IslamGaber\SafeCheckout\Money;
use IslamGaber\SafeCheckout\PaymentStatus;
use IslamGaber\SafeCheckout\Tests\TestCase;

final class MoyasarCheckoutTest extends TestCase
{
    private const INVOICES = 'https://api.moyasar.com/v1/invoices';

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $this->stub('POST', self::INVOICES, $this->moyasarInvoice('inv_1', 'initiated', 15075));
    }

    private function startOrder(?string $key = 'order-1001', string $amount = '150.75'): Payment
    {
        return Checkout::start(Money::fromMajor($amount), $key, null, ['description' => 'Order #1001']);
    }

    public function test_it_creates_a_hosted_invoice_in_halalas(): void
    {
        $payment = $this->startOrder();

        self::assertSame('moyasar', $payment->gateway);
        self::assertSame('inv_1', $payment->gateway_reference);
        self::assertSame('https://checkout.moyasar.com/invoices/inv_1', $payment->checkout_url);
        self::assertSame(PaymentStatus::Pending, $payment->status);
        self::assertSame(15075, $payment->amount);

        Http::assertSent(function (Request $request) use ($payment): bool {
            return $request->method() === 'POST'
                && $request->url() === self::INVOICES
                && $request->hasHeader('Authorization', 'Basic '.base64_encode(self::MOYASAR_KEY.':'))
                && $request['amount'] === 15075
                && $request['currency'] === 'SAR'
                && $request['description'] === 'Order #1001'
                && $request['success_url'] === 'https://shop.test/thank-you'
                && $request['metadata']['payment_uuid'] === $payment->uuid;
        });
    }

    public function test_the_same_idempotency_key_never_creates_a_second_invoice(): void
    {
        $first = $this->startOrder();
        $second = $this->startOrder();

        self::assertTrue($first->is($second));
        self::assertSame(1, Payment::count());
        Http::assertSentCount(1);
    }

    public function test_reusing_a_key_for_a_different_amount_is_rejected(): void
    {
        $this->startOrder();

        $this->expectException(IdempotencyConflict::class);

        $this->startOrder('order-1001', '99.00');
    }

    public function test_a_failed_gateway_call_can_be_retried_with_the_same_key(): void
    {
        $this->stubs = [];
        $this->stub('POST', self::INVOICES, ['__status' => 500, '__body' => ['message' => 'down']]);

        try {
            $this->startOrder();
            self::fail('Expected the gateway error.');
        } catch (\IslamGaber\SafeCheckout\Exceptions\GatewayRequestFailed) {
        }

        $this->stub('POST', self::INVOICES, $this->moyasarInvoice('inv_1', 'initiated', 15075));
        $payment = $this->startOrder();

        self::assertSame('inv_1', $payment->gateway_reference);
        self::assertSame(1, Payment::count());
    }

    public function test_a_verified_webhook_marks_the_payment_paid_once(): void
    {
        $payment = $this->startOrder();
        $this->stub('GET', self::INVOICES.'/inv_1', $this->moyasarInvoice('inv_1', 'paid', 15075));

        $this->postJson(route('safe-checkout.webhook', ['gateway' => 'moyasar']), $this->moyasarWebhook('evt_1', 'inv_1'))
            ->assertOk()
            ->assertJson(['status' => 'processed']);

        $payment->refresh();
        self::assertSame(PaymentStatus::Paid, $payment->status);
        self::assertNotNull($payment->paid_at);

        // The gateway retries the same delivery: nothing happens twice.
        $this->postJson(route('safe-checkout.webhook', ['gateway' => 'moyasar']), $this->moyasarWebhook('evt_1', 'inv_1'))
            ->assertOk()
            ->assertJson(['status' => 'duplicate']);

        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
    }

    public function test_a_webhook_with_the_wrong_secret_is_rejected_and_changes_nothing(): void
    {
        $payment = $this->startOrder();

        $this->postJson(route('safe-checkout.webhook', ['gateway' => 'moyasar']), $this->moyasarWebhook('evt_1', 'inv_1', 'guessed'))
            ->assertForbidden();

        self::assertSame(PaymentStatus::Pending, $payment->refresh()->status);
        Http::assertSentCount(1); // only the create call, no fetch
    }

    public function test_the_webhook_payload_is_never_trusted_for_the_status(): void
    {
        $payment = $this->startOrder();

        // The webhook claims "paid", but the gateway API says the invoice is still unpaid.
        $this->stub('GET', self::INVOICES.'/inv_1', $this->moyasarInvoice('inv_1', 'initiated', 15075));

        $this->postJson(route('safe-checkout.webhook', ['gateway' => 'moyasar']), $this->moyasarWebhook('evt_1', 'inv_1'))
            ->assertOk();

        self::assertSame(PaymentStatus::Pending, $payment->refresh()->status);
        Event::assertNotDispatched(PaymentSucceeded::class);
    }

    public function test_a_paid_invoice_with_a_different_amount_is_not_accepted(): void
    {
        $payment = $this->startOrder();
        $this->stub('GET', self::INVOICES.'/inv_1', $this->moyasarInvoice('inv_1', 'paid', 100));

        Checkout::reconcile($payment);

        $payment->refresh();
        self::assertSame(PaymentStatus::Failed, $payment->status);
        self::assertStringStartsWith('amount_mismatch', (string) $payment->failure_reason);
        Event::assertNotDispatched(PaymentSucceeded::class);
        Event::assertDispatched(PaymentFailed::class);
    }

    public function test_a_paid_payment_never_goes_back(): void
    {
        $payment = $this->startOrder();
        $this->stub('GET', self::INVOICES.'/inv_1', $this->moyasarInvoice('inv_1', 'paid', 15075));
        Checkout::reconcile($payment);

        // A stale or replayed response arrives later.
        $this->stub('GET', self::INVOICES.'/inv_1', $this->moyasarInvoice('inv_1', 'expired', 15075));
        Checkout::reconcile($payment);

        self::assertSame(PaymentStatus::Paid, $payment->refresh()->status);
        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
    }

    public function test_an_expired_invoice_fails_the_payment(): void
    {
        $payment = $this->startOrder();
        $this->stub('GET', self::INVOICES.'/inv_1', $this->moyasarInvoice('inv_1', 'expired', 15075));

        Checkout::reconcile($payment);

        self::assertSame(PaymentStatus::Expired, $payment->refresh()->status);
        Event::assertDispatched(PaymentFailed::class);
    }

    public function test_gateway_errors_during_a_webhook_ask_for_a_retry(): void
    {
        $this->startOrder();
        $this->stub('GET', self::INVOICES.'/inv_1', ['__status' => 502, '__body' => ['message' => 'bad gateway']]);

        $this->postJson(route('safe-checkout.webhook', ['gateway' => 'moyasar']), $this->moyasarWebhook('evt_1', 'inv_1'))
            ->assertStatus(503);

        // Next delivery succeeds, because the failed one was not recorded as processed.
        $this->stub('GET', self::INVOICES.'/inv_1', $this->moyasarInvoice('inv_1', 'paid', 15075));

        $this->postJson(route('safe-checkout.webhook', ['gateway' => 'moyasar']), $this->moyasarWebhook('evt_1', 'inv_1'))
            ->assertOk()
            ->assertJson(['status' => 'processed']);
    }

    public function test_webhooks_for_unknown_payments_are_acknowledged(): void
    {
        $this->postJson(route('safe-checkout.webhook', ['gateway' => 'moyasar']), $this->moyasarWebhook('evt_9', 'inv_unknown'))
            ->assertOk()
            ->assertJson(['status' => 'unknown_payment']);
    }

    public function test_unknown_gateways_return_404(): void
    {
        $this->postJson(route('safe-checkout.webhook', ['gateway' => 'paypal']), [])->assertNotFound();
    }

    public function test_the_reconcile_command_catches_missed_webhooks(): void
    {
        $payment = $this->startOrder();
        $payment->forceFill(['created_at' => now()->subMinutes(30)])->save();
        $this->stub('GET', self::INVOICES.'/inv_1', $this->moyasarInvoice('inv_1', 'paid', 15075));

        $this->artisan('safe-checkout:reconcile', ['--older-than' => 10])
            ->expectsOutputToContain('1 updated')
            ->assertSuccessful();

        self::assertSame(PaymentStatus::Paid, $payment->refresh()->status);
    }

    public function test_queued_mode_reconciles_in_a_job(): void
    {
        config()->set('safe-checkout.queue.enabled', true);
        config()->set('queue.default', 'sync');

        $payment = $this->startOrder();
        $this->stub('GET', self::INVOICES.'/inv_1', $this->moyasarInvoice('inv_1', 'paid', 15075));

        $this->postJson(route('safe-checkout.webhook', ['gateway' => 'moyasar']), $this->moyasarWebhook('evt_1', 'inv_1'))
            ->assertOk()
            ->assertJson(['status' => 'queued']);

        self::assertSame(PaymentStatus::Paid, $payment->refresh()->status);
    }
}
