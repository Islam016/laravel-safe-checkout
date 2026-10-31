<?php

declare(strict_types=1);

namespace IslamGaber\SafeCheckout;

use InvalidArgumentException;

/**
 * An amount of money stored as an integer in the currency's smallest unit
 * (halalas for SAR, fils for KWD), so no floating-point rounding ever touches it.
 */
final class Money
{
    /** Currencies whose minor unit is not 1/100. */
    private const DECIMALS = [
        'BHD' => 3, 'IQD' => 3, 'JOD' => 3, 'KWD' => 3, 'LYD' => 3, 'OMR' => 3, 'TND' => 3,
        'JPY' => 0, 'KRW' => 0,
    ];

    public readonly string $currency;

    public function __construct(public readonly int $minor, string $currency = 'SAR')
    {
        if ($minor < 0) {
            throw new InvalidArgumentException('Amount cannot be negative.');
        }

        $currency = strtoupper(trim($currency));

        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException("Invalid ISO 4217 currency code [{$currency}].");
        }

        $this->currency = $currency;
    }

    /** Create from the smallest unit: Money::of(15075) is 150.75 SAR. */
    public static function of(int $minor, string $currency = 'SAR'): self
    {
        return new self($minor, $currency);
    }

    /**
     * Create from a major-unit amount such as "150.75" or 150.75.
     * Strings are parsed exactly; floats are rounded to the currency's decimals first.
     */
    public static function fromMajor(string|int|float $amount, string $currency = 'SAR'): self
    {
        $decimals = self::decimalsFor($currency);

        if (is_float($amount)) {
            $amount = number_format($amount, $decimals, '.', '');
        }

        $amount = trim((string) $amount);

        if (! preg_match('/^(\d+)(?:\.(\d+))?$/', $amount, $m)) {
            throw new InvalidArgumentException("Invalid amount [{$amount}].");
        }

        $fraction = $m[2] ?? '';

        if (strlen(rtrim($fraction, '0')) > $decimals) {
            throw new InvalidArgumentException("Amount [{$amount}] has more than {$decimals} decimal places for {$currency}.");
        }

        $fraction = str_pad(substr($fraction, 0, $decimals), $decimals, '0');

        return new self((int) ($m[1].$fraction), $currency);
    }

    public static function decimalsFor(string $currency): int
    {
        return self::DECIMALS[strtoupper($currency)] ?? 2;
    }

    public function decimals(): int
    {
        return self::decimalsFor($this->currency);
    }

    /** "150.75" for SAR, "1.500" for KWD. */
    public function toMajorString(): string
    {
        $decimals = $this->decimals();

        if ($decimals === 0) {
            return (string) $this->minor;
        }

        $factor = 10 ** $decimals;

        return intdiv($this->minor, $factor).'.'.str_pad((string) ($this->minor % $factor), $decimals, '0', STR_PAD_LEFT);
    }

    public function equals(self $other): bool
    {
        return $this->minor === $other->minor && $this->currency === $other->currency;
    }

    public function __toString(): string
    {
        return $this->toMajorString().' '.$this->currency;
    }
}
