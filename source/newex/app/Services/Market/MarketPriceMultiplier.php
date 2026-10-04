<?php

namespace App\Services\Market;

/** BS is stored as a ratio: an admin input of 30% is stored as 0.3. */
final class MarketPriceMultiplier
{
    public static function resolve($ratio): float
    {
        if (!is_numeric($ratio)) {
            return 1.0;
        }

        $multiplier = (float) $ratio;

        // Preserve the legacy neutral behavior for unset/zero/invalid values.
        return is_finite($multiplier) && $multiplier > 0 ? $multiplier : 1.0;
    }
}
