<?php

declare(strict_types=1);

namespace IslamGaber\SafeCheckout\Tests\Unit;

use InvalidArgumentException;
use IslamGaber\SafeCheckout\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    /** @return iterable<string, array{string|int|float, string, int, string}> */
    public static function majorAmounts(): iterable
    {
        yield 'SAR string' => ['150.75', 'SAR', 15075, '150.75'];
        yield 'SAR integer' => [150, 'SAR', 15000, '150.00'];
        yield 'SAR float' => [150.75, 'SAR', 15075, '150.75'];
        yield 'float that is inexact in binary' => [0.1 + 0.2, 'SAR', 30, '0.30'];
        yield 'one decimal' => ['10.5', 'SAR', 1050, '10.50'];
        yield 'KWD has three decimals' => ['1.5', 'KWD', 1500, '1.500'];
        yield 'BHD' => ['0.125', 'BHD', 125, '0.125'];
        yield 'trailing zeros are fine' => ['5.1000', 'SAR', 510, '5.10'];
        yield 'lowercase currency' => ['1', 'sar', 100, '1.00'];
    }

    #[DataProvider('majorAmounts')]
    public function test_from_major(string|int|float $amount, string $currency, int $minor, string $formatted): void
    {
        $money = Money::fromMajor($amount, $currency);

        self::assertSame($minor, $money->minor);
        self::assertSame($formatted, $money->toMajorString());
        self::assertSame(strtoupper($currency), $money->currency);
    }

    public function test_minor_units_and_string(): void
    {
        self::assertSame('0.05 SAR', (string) Money::of(5));
        self::assertSame('1234.56 SAR', (string) Money::of(123456));
    }

    public function test_equality_needs_same_amount_and_currency(): void
    {
        self::assertTrue(Money::of(100)->equals(Money::fromMajor('1.00')));
        self::assertFalse(Money::of(100)->equals(Money::of(101)));
        self::assertFalse(Money::of(100, 'SAR')->equals(Money::of(100, 'USD')));
    }

    public function test_rejects_too_many_decimals(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromMajor('10.005', 'SAR');
    }

    public function test_rejects_negative_amounts(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::of(-1);
    }

    public function test_rejects_invalid_currency(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::of(100, 'RIYAL');
    }

    public function test_rejects_garbage(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::fromMajor('12,50');
    }
}
