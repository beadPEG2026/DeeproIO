<?php

namespace App\Modules\P2P\Models\PeerTrade;

use App\Modules\P2P\Models\PeerTrade\Traits\Relations\PeerTradePaymentMethodsRelation;
use App\Modules\P2P\Models\PeerTrade\Traits\Scopes\PeerTradePaymentMethodScope;
use Database\Factories\P2P\PeerPaymentMethodFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PeerPaymentMethod extends Model
{
    use HasFactory, PeerTradePaymentMethodScope, PeerTradePaymentMethodsRelation;

    protected static function newFactory()
    {
        return PeerPaymentMethodFactory::new();
    }

    public $fillable = [
        'title',
        'status',
        'color'
    ];

    protected $casts = [
        'status' => 'boolean',
    ];
}
