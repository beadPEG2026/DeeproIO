<?php

namespace App\Modules\P2P\Models\PeerTrade;

use App\Modules\P2P\Models\PeerTrade\Traits\Relations\PeerMerchantKycDocumentRelation;
use App\Modules\P2P\Models\PeerTrade\Traits\Scopes\PeerMerchantKycDocumentScope;
use Illuminate\Database\Eloquent\Model;

class PeerMerchantKycDocument extends Model
{
    use PeerMerchantKycDocumentRelation, PeerMerchantKycDocumentScope;

    protected $table = 'peer_merchant_documents';

    public $fillable = [
        'user_id',
        'address',
        'postal_code',
        'city',
        'state',
        'country_id',
        'document_type',
        'rejected_reason',
        'file_id',
        'status'
    ];

    protected $casts = [
        'created_at' => "datetime:Y-m-d H:i:s",
    ];
}
