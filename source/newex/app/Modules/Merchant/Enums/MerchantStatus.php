<?php

namespace App\Modules\Merchant\Enums;

enum MerchantStatus: string
{
    case PENDING = 'pending';
    case ACTIVE = 'active';
    case SUSPENDED = 'suspended';
    case TERMINATED = 'terminated';

    /**
     * Get human-readable label
     */
    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending Approval',
            self::ACTIVE => 'Active',
            self::SUSPENDED => 'Suspended',
            self::TERMINATED => 'Terminated',
        };
    }

    /**
     * Check if merchant can create invoices in this status
     */
    public function canCreateInvoices(): bool
    {
        return $this === self::ACTIVE;
    }

    /**
     * Check if this is a final state
     */
    public function isFinal(): bool
    {
        return $this === self::TERMINATED;
    }

    /**
     * Get badge color for UI
     */
    public function badgeColor(): string
    {
        return match ($this) {
            self::PENDING => 'yellow',
            self::ACTIVE => 'green',
            self::SUSPENDED => 'orange',
            self::TERMINATED => 'red',
        };
    }
}
