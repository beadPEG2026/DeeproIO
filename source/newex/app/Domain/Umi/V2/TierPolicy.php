<?php

namespace App\Domain\Umi\V2;

use InvalidArgumentException;

final class TierPolicy
{
    public static function displayTiers(): array
    {
        return [['minimum_usdt' => '100', 'maximum_exclusive_usdt' => '2000', 'multiple' => 3],
            ['minimum_usdt' => '2000', 'maximum_exclusive_usdt' => '5000', 'multiple' => 4],
            ['minimum_usdt' => '5000', 'maximum_exclusive_usdt' => null, 'multiple' => 5]];
    }

    /** $2,000 belongs to the 4x tier; $5,000 belongs to the 5x tier. */
    public static function multipleForUsdValue(mixed $value): int
    {
        $value = Decimal::amount($value, true);
        if (Decimal::cmp($value, '100') < 0) {
            throw new InvalidArgumentException('Minimum purchase value is 100 USDT');
        }
        if (Decimal::cmp($value, '2000') < 0) {
            return 3;
        }
        if (Decimal::cmp($value, '5000') < 0) {
            return 4;
        }
        return 5;
    }
}
