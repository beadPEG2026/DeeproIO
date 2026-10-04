<?php

namespace App\Models\Lending;

use App\Models\Lending\Traits\Relations\LendingCurrencyRelation;
use Illuminate\Database\Eloquent\Model;

class LendingTransactions extends Model
{
    use LendingCurrencyRelation;

    protected $table = 'lending_transactions';

    public $fillable = [
        'lending_id',
        'amount',
        'collateral_id',
        'user_id'
    ];
}
