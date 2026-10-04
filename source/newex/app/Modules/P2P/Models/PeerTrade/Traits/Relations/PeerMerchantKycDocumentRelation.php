<?php

namespace App\Modules\P2P\Models\PeerTrade\Traits\Relations;

use App\Models\Country\Country;
use App\Models\FileUpload\FileUpload;
use App\Models\User\User;

trait PeerMerchantKycDocumentRelation
{
    public function file()
    {
        return $this->belongsTo(FileUpload::class, 'file_id');
    }

    public function country()
    {
        return $this->belongsTo(Country::class, 'country_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}


