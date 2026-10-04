<?php

namespace App\Models\ColdStorage\Traits\Scopes;

trait ColdStorageScope
{
    public function scopeActive($query)
    {
        return $query->whereStatus(true);
    }
}

