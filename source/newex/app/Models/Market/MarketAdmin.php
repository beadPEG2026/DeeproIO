<?php

namespace App\Models\Market;

use App\Models\Market\Traits\Relations\MarketRelation;
use App\Models\Market\Traits\Scopes\MarketScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MarketAdmin extends Market
{
    public $table = 'markets';

    public function resolveRouteBinding($value, $field = null)
    {
        $market = $this->where($this->getRouteKeyName(), $value);

        if($field) {
            $market->orWhere($field, $value);
        }

        return $market->withTrashed()->first();
    }
}
