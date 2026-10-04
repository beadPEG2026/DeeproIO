<?php

namespace App\Services\Umi\V2;

/** Compatibility hook: exchange deposits cannot automatically fund UMI. */
final class FundedInboundBinder
{
    public function bind(int $depositId): ?string
    {
        return null;
    }
}
