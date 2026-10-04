<?php

namespace App\Models\Lending;

use App\Models\Lending\Traits\Relations\LendingCurrencyRelation;
use Illuminate\Database\Eloquent\Model;

class LendingCurrencies extends Model
{
    use LendingCurrencyRelation;

    protected $table = 'lending_currencies';

    public $fillable = [
        'flex_initial_ltv',
        'flex_margin_call',
        'flex_liquidation_ltv',

        'weekly_initial_ltv',
        'weekly_margin_call',
        'weekly_liquidation_ltv',

        'monthly_initial_ltv',
        'monthly_margin_call',
        'monthly_liquidation_ltv',

        'currency_id',
        'lending_id'
    ];
}
