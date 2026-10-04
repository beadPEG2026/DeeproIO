<?php

namespace App\Services\Deposit;

use RuntimeException;
final class ChainAmount
{
    public const TRANSFER = 'ddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';
    public static function integer(string $hex): string
    {
        $hex = preg_replace('/^0x/', '', $hex);
        if (!preg_match('/^[0-9a-fA-F]{1,64}$/', $hex)) {
            throw new RuntimeException('DEPOSIT_INVALID_HEX');
        }
        $n = '0';
        foreach (str_split(strtolower($hex)) as $digit) {
            $n = bcadd(bcmul($n, '16', 0), (string) hexdec($digit), 0);
        }
        return $n;
    }
    public static function decimal(string $raw, int $decimals): string
    {
        if ($decimals < 0 || $decimals > 18 || !preg_match('/^[0-9]{1,78}$/', $raw)) {
            throw new RuntimeException('DEPOSIT_INVALID_AMOUNT');
        }
        $v = bcdiv($raw, bcpow('10', (string) $decimals, 0), 18);
        if (bccomp($v, '1000000000000000000', 18) >= 0) {
            throw new RuntimeException('DEPOSIT_AMOUNT_OUT_OF_RANGE');
        }
        return $v;
    }
}
