<?php

namespace App\Models\Currency;

use App\Models\Currency\Traits\Relations\CurrencyRelation;
use App\Models\Currency\Traits\Scopes\CurrencyScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class CurrencyAdmin extends Currency
{
    use HasFactory, SoftDeletes, CurrencyRelation, CurrencyScope;

    public function resolveRouteBinding($value, $field = null)
    {
        return in_array(SoftDeletes::class, class_uses($this))
            ? $this->where($this->getRouteKeyName(), $value)->withTrashed()->first()
            : parent::resolveRouteBinding($value);
    }
}
