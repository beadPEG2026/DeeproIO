<?php
/*
 *  Copyright 2021. Crypto Smart Solutions, LLC
 *  Protected with Proprietary License
 */

namespace App\Models\Language\Traits\Scopes;

trait LanguageScope
{
    // Retrieve active languages list
    public function scopeActive($query)
    {
        return $query->where('status', 1);
    }
}

