<?php
namespace App\Support;
use App\Models\User\User;

final class AdminUserAccess
{
    public static function check(User $target, bool $credential = false): void
    {
        $actor = auth()->user();
        abort_unless($actor && !$actor->deleted && !$actor->deactivated,403);
        if ($actor->hasRole('superadmin')) return;
        abort_if($credential || $target->roles()->where('name','!=','user')->exists(),403);
        // Delegated operators may manage ordinary descendants only, never themselves or another tree.
        $seen = [(int)$target->id=>true]; $parent = $target->referral_id;
        while ($parent) {
            abort_if(isset($seen[(int)$parent]),403); $seen[(int)$parent]=true;
            if ((int)$parent === (int)$actor->id) return;
            $parent = User::where('id',$parent)->value('referral_id');
        }
        abort(403);
    }
}
