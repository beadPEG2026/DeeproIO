<?php

namespace App\Modules\Merchant\Enums;

enum WebhookPriority: string
{
    case CRITICAL = 'critical';
    case HIGH = 'high';
    case NORMAL = 'normal';
    case LOW = 'low';

    /**
     * Get sort order (lower = higher priority)
     */
    public function sortOrder(): int
    {
        return match ($this) {
            self::CRITICAL => 1,
            self::HIGH => 2,
            self::NORMAL => 3,
            self::LOW => 4,
        };
    }

    /**
     * Get max retry attempts for this priority
     */
    public function maxAttempts(): int
    {
        return match ($this) {
            self::CRITICAL => 10,
            self::HIGH => 7,
            self::NORMAL => 5,
            self::LOW => 3,
        };
    }
}
