<?php

namespace App\Domain\Umi\V2;

use InvalidArgumentException;
use OverflowException;

/** Exact, non-negative UMI amounts. Inputs never pass through binary floats. */
final class Decimal
{
    public const SCALE = 24;
    public const INTEGER_DIGITS = 30; // DECIMAL(54,24)

    public static function amount(mixed $value, bool $positive = false): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('Decimal input must be a string');
        }
        if (!preg_match('/^(?:0|[1-9][0-9]{0,17})(?:\.[0-9]{1,24})?$/D', $value)) {
            throw new InvalidArgumentException('Invalid decimal amount');
        }
        $normalized = self::display(bcadd($value, '0', self::SCALE));
        if ($positive && self::cmp($normalized, '0') <= 0) {
            throw new InvalidArgumentException('Amount must be positive');
        }
        return $normalized;
    }

    public static function rate(mixed $value): string
    {
        $rate = self::amount($value);
        if (self::cmp($rate, '1') > 0) {
            throw new InvalidArgumentException('Rate must not exceed one');
        }
        return $rate;
    }

    public static function add(mixed $a, mixed $b): string
    {
        self::strings($a, $b);
        return self::fit(self::display(bcadd($a, $b, self::SCALE)));
    }

    public static function sub(mixed $a, mixed $b): string
    {
        self::strings($a, $b);
        return self::fit(self::display(bcsub($a, $b, self::SCALE)));
    }

    public static function mul(mixed $a, mixed $b): string
    {
        self::strings($a, $b);
        return self::fit(self::display(bcmul($a, $b, self::SCALE)));
    }

    public static function cmp(mixed $a, mixed $b): int
    {
        self::strings($a, $b);
        return bccomp($a, $b, self::SCALE);
    }

    public static function min(mixed $a, mixed $b): string
    {
        return self::fit(self::cmp($a, $b) <= 0 ? $a : $b);
    }

    public static function display(string $value): string
    {
        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }

    private static function fit(string $value): string
    {
        $integer = explode('.', ltrim($value, '-'), 2)[0];
        if (strlen($integer) > self::INTEGER_DIGITS) {
            throw new OverflowException('UMI amount exceeds DECIMAL(54,24) integer precision');
        }
        return $value;
    }

    private static function strings(mixed $a, mixed $b): void
    {
        if (!is_string($a) || !is_string($b)) {
            throw new InvalidArgumentException('Decimal input must be a string');
        }
    }
}
