<?php

namespace App\Models\Launchpad\Traits\Scopes;

trait LaunchpadScope
{
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
