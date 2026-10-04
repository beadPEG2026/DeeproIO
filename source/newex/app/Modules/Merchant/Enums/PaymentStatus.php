<?php

namespace App\Modules\Merchant\Enums;

enum PaymentStatus: string
{
    case DETECTING = 'detecting';
    case CONFIRMING = 'confirming';
    case CONFIRMED = 'confirmed';
    case FAILED = 'failed';
    case ORPHANED = 'orphaned';

    /**
     * Get human-readable label
     */
    public function label(): string
    {
        return match ($this) {
            self::DETECTING => 'Detecting',
            self::CONFIRMING => 'Confirming',
            self::CONFIRMED => 'Confirmed',
            self::FAILED => 'Failed',
            self::ORPHANED => 'Orphaned',
        };
    }

    /**
     * Check if this is a final state
     */
    public function isFinal(): bool
    {
        return in_array($this, [
            self::CONFIRMED,
            self::FAILED,
            self::ORPHANED,
        ]);
    }

    /**
     * Check if payment counts towards invoice total
     */
    public function countsTowardsTotal(): bool
    {
        return in_array($this, [
            self::DETECTING,
            self::CONFIRMING,
            self::CONFIRMED,
        ]);
    }
}
