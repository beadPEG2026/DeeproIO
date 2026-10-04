<?php

namespace App\Models\User\Traits\Relations;

use App\Models\Language\Language;
use App\Models\User\User;
use App\Models\KycDocument\KycDocument;
use Spatie\Permission\Models\Role;

trait UserRelation
{
    public function language()
    {
        return $this->belongsTo(Language::class, 'language_id');
    }

    public function referral()
    {
        return $this->belongsTo(User::class, 'referral_id');
    }

    public function document()
    {
        return $this->belongsTo(KycDocument::class, 'user_id');
    }
}



