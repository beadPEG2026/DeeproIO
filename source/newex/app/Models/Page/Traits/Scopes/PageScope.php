<?php

namespace App\Models\Page\Traits\Scopes;

trait PageScope
{
    public function scopeActive($query)
    {
        return $query->where('status', 1);
    }
}
