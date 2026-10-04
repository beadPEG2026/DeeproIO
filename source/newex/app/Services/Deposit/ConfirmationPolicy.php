<?php
namespace App\Services\Deposit;

final class ConfirmationPolicy
{
    // Reuse the site's established custody finality thresholds for incoming funds.
    public const MINIMUM = ['ethereum'=>12,'bsc'=>15,'polygon'=>128,'xlayer'=>64,'tron'=>20,'solana'=>1,'bitcoin'=>6];
    public static function minimum(string $chain): int { return self::MINIMUM[$chain] ?? 1; }
}
