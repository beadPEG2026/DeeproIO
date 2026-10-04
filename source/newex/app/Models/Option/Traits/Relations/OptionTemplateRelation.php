<?php

namespace App\Models\Option\Traits\Relations;

use App\Models\Market\Market;

trait OptionTemplateRelation
{
    public function market()
    {
        return $this->belongsTo(Market::class, 'market_id');
    }
}



