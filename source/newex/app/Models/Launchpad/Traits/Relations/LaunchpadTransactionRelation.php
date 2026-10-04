<?php

namespace App\Models\Launchpad\Traits\Relations;

use App\Models\Launchpad\Launchpad;
use App\Models\User\User;

trait LaunchpadTransactionRelation
{
    public function launchpad()
    {
        return $this->belongsTo(Launchpad::class, 'launchpad_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}


