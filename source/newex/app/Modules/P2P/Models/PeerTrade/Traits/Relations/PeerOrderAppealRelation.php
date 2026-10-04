<?php

namespace App\Modules\P2P\Models\PeerTrade\Traits\Relations;

use App\Models\FileUpload\FileUpload;
use App\Models\User\User;

trait PeerOrderAppealRelation
{
    public function attachments()
    {
        return $this->belongsToMany(FileUpload::class, 'peer_order_appeals_file_uploads', 'appeal_id', 'file_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'appeal_author_id');
    }

    public function appealed()
    {
        return $this->belongsTo(User::class, 'appealed_user');
    }
}
