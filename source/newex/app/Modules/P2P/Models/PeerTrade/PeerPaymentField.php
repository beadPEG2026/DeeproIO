<?php

namespace App\Modules\P2P\Models\PeerTrade;

use App\Modules\P2P\Models\PeerTrade\Traits\Scopes\PeerTradePaymentFieldScope;
use Illuminate\Database\Eloquent\Model;

class PeerPaymentField extends Model
{
    use PeerTradePaymentFieldScope;

    public $fillable = [
        'title',
        'required',
        'payment_method'
    ];

    protected $casts = [
        'required' => 'boolean',
    ];
}
