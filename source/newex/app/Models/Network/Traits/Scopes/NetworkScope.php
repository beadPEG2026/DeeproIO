<?php
/*
 *  Copyright 2021. Crypto Smart Solutions, LLC
 *  Protected with Proprietary License
 */

namespace App\Models\Network\Traits\Scopes;

trait NetworkScope
{
    // Retrieve the list of active networks
    public function scopeActive($query)
    {
        return $query->where('status', 1);
    }
}
