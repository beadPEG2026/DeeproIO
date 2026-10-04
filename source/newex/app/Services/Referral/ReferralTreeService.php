<?php

namespace App\Services\Referral;

use App\Models\User\User;

class ReferralTreeService
{
    public function ancestorIds(?User $user): array
    {
        if (!$user || !$user->getKey()) {
            return [];
        }

        $ids = [];
        $currentId = (int) $user->getKey();

        while ($currentId > 0 && !in_array($currentId, $ids, true)) {
            $ids[] = $currentId;
            $currentId = (int) (User::query()->whereKey($currentId)->value('referral_id') ?? 0);
        }

        return $ids;
    }
}
