<?php
/*
 *  Copyright 2021. Crypto Smart Solutions, LLC
 *  Protected with Proprietary License
 */

namespace App\Models\Network;

use App\Models\Network\Traits\Scopes\NetworkScope;
use Illuminate\Database\Eloquent\Model;

class Network extends Model
{
    use NetworkScope;

    public function getNameAttribute($value): string
    {
        return \App\Services\Wallet\NetworkName::display((int) $this->id, (string) $value);
    }

    public $fillable = [
        'name',
        'slug',
        'status',
        'deposit_status',
        'withdraw_status'
    ];

    protected $casts = [
        'status' => 'boolean',
        'deposit_status' => 'boolean',
        'withdraw_status' => 'boolean',
    ];
}
