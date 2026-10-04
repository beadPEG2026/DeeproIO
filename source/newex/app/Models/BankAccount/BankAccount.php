<?php

namespace App\Models\BankAccount;

use App\Models\BankAccount\Traits\Relations\BankAccountRelation;
use App\Models\BankAccount\Traits\Scopes\BankAccountScope;
use Illuminate\Database\Eloquent\Model;

class BankAccount extends Model
{
    use BankAccountScope, BankAccountRelation;

    public $fillable = [
        'reference_number',
        'name',
        'iban',
        'swift',
        'ifsc',
        'address',
        'account_holder_name',
        'account_holder_address',
        'note',
        'status',
        'country_id',
    ];

    protected $casts = [
        'status' => 'boolean',
    ];
}
