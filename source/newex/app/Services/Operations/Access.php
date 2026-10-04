<?php
namespace App\Services\Operations;

use App\Models\User\User;
use App\Support\{AdminAccess,AdminUserAccess};

final class Access
{
    public static function route(string $name): bool {
        if ($name === 'admin.custody' && !\App\Services\Custody\CustodyAccess::allowed(auth()->user())) return false;
        return in_array($name, AdminAccess::routes(auth()->user()), true);
    }
    public static function requireRoute(string $name): void { abort_unless(self::route($name),403); }
    public static function user(User $user): void {
        self::requireRoute('admin.users');
        AdminUserAccess::check($user);
    }
    /** Responsibility does not grant access or change either referral tree. */
    public static function canAssign(User $operator, User $target): bool {
        if (!AdminAccess::allows($operator) || !$operator->hasAnyRole(['superadmin','admin','user_editor','user_leader','salesman','perm_users'])) return false;
        if ($operator->hasRole('superadmin')) return true;
        if ($target->roles()->where('name','!=','user')->exists()) return false;
        $parent=$target->referral_id; $seen=[$target->id=>true];
        while ($parent && count($seen)<1000) {
            if (isset($seen[$parent])) return false;
            if ((int)$parent===(int)$operator->id) return true;
            $seen[$parent]=true; $parent=User::whereKey($parent)->value('referral_id');
        }
        return false;
    }
}
