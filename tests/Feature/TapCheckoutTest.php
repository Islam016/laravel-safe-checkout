<?php

declare(strict_types=1);

namespace IslamGaber\SafeCheckout\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use IslamGaber\SafeCheckout\Events\PaymentFailed;
use IslamGaber\SafeCheckout\Events\PaymentSucceeded;
use IslamGaber\SafeCheckout\Facades\Checkout;
use IslamGaber\SafeCheckout\Gateways\TapGateway;
use IslamGaber\SafeCheckout\Models\Payment;
use IslamGaber\SafeCheckout\Money;
use IslamGaber\SafeCheckout\PaymentStatus;
use IslamGaber\SafeCheckout\Tests\TestCase;

final class TapCheckoutTest extends TestCase
{
    private const CHARGES = 'https://api.tap.company/v2/charges';

    private const CUSTOMER = ['first_name' => 'Islam', 'email' => 'buyer@example.com'];

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([PaymentSucceeded::class, PaymentFailed::class]);

        $this->stub('POST', self::CHARGES, [
            'id' => 'chg_1',
            'status' => 'INITIATED',
            'amount' => 150.75,
            'currency' => 'SAR',
            'transaction' => ['url' => 'https://checkout.tap.company/?mode=page&token=abc', 'created' => '1761900000000'],
        ]);
    }

    /** @return array<string, mixed> */
    private function charge(string $status, float $amount = 150.75, string $currency = 'SAR'): array
    {
        return [
            'id' => 'chg_1',
            'status' => $status,
            'amount' => $amount,
            'currency' => $currency,
            'reference' => ['gateway' => '123456', 'payment' => '0131', 'transaction' => 'x'],
            'transaction' => ['created' => '1761900000000'],
            'response' => ['code' => '000', 'message' => 'Captured'],
        ];
    }

    private function startOrder(): Payment
    {
        return Checkout::start(Money::fromMajor('150.75'), 'order-7', null, [
            'gateway' => 'tap',
            'customer' => self::CUSTOMER,
            'order' => 'ORD-7',
        ]);
    }

    public function test_it_creates_a_charge_in_major_units(): void
    {
        $payment = $this->startOrder();

        self::assertSame('chg_1', $payment->gateway_reference);
        self::assertSame('https://checkout.tap.company/?mode=page&token=abc', $payment->checkout_url);

        Http::assertSent(function (Request $request) use ($payment): bool {
            return $request->url() === self::CHARGES
                && $request->hasHeader('Authorization', 'Bearer '.self::TAP_KEY)
                && $request['amount'] === 150.75
                && $request['currency'] === 'SAR'
                && $request['source']['id'] === 'src_all'
                && $request['customer'] === self::CUSTOMER
                && $request['reference']['transaction'] === $payment->uuid
                && $request['reference']['order'] === 'ORD-7'
                && $request['post']['url'] === route('safe-checkout.webhook', ['gateway' => 'tap'])
                && $request['redirect']['url'] === 'https://shop.test/thank-you';
        });
    }

    public function test_tap_requires_a_customer(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Checkout::start(Money::of(1000), 'k', null, ['gateway' => 'tap']);
    }

    public function test_a_signed_webhook_is_confirmed_against_the_api(): void
    {
        $payment = $this->startOrder();
        $charge = $this->charge('CAPTURED');
        $this->stub('GET', self::CHARGES.'/chg_1', $charge);

        $this->postJson(route('safe-checkout.webhook', ['gateway' => 'tap']), $charge, [
            'hashstring' => TapGateway::hashString($charge, self::TAP_KEY),
        ])->assertOk()->assertJson(['status' => 'processed']);

        self::assertSame(PaymentStatus::Paid, $payment->refresh()->status);
        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
    }

    public function test_a_tampered_webhook_is_rejected(): void
    {
        $payment = $this->startOrder();
        $charge = $this->charge('CAPTURED');
        $hash = TapGateway::hashString($charge, self::TAP_KEY);
        $charge['amount'] = 1.00;

        $this->postJson(route('safe-checkout.webhook', ['gateway' => 'tap']), $charge, ['hashstring' => $hash])
            ->assertForbidden();

        $this->postJson(route('safe-checkout.webhook', ['gateway' => 'tap']), $this->charge('CAPTURED'))
            ->assertForbidden();

        self::assertSame(PaymentStatus::Pending, $payment->refresh()->status);
    }

    public function test_declined_charges_fail_with_the_gateway_message(): void
    {
        $payment = $this->startOrder();
        $charge = array_replace($this->charge('DECLINED'), ['response' => ['code' => '507', 'message' => 'Declined, Card Issuer']]);
        $this->stub('GET', self::CHARGES.'/chg_1', $charge);

        Checkout::reconcile($payment);

        $payment->refresh();
        self::assertSame(PaymentStatus::Failed, $payment->status);
        self::assertSame('Declined, Card Issuer', $payment->failure_reason);
        Event::assertDispatched(PaymentFailed::class);
    }

    public function test_hash_uses_three_decimals_for_kwd(): void
    {
        $sar = TapGateway::hashString($this->charge('CAPTURED', 1.5, 'KWD'), 'key');
        $expected = hash_hmac('sha256', 'x_idchg_1x_amount1.500x_currencyKWDx_gateway_reference123456x_payment_reference0131x_statusCAPTUREDx_created1761900000000', 'key');

        self::assertSame($expected, $sar);
    }
}
