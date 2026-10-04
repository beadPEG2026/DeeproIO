<?php

namespace App\Support;

use App\Models\User\User;

final class AdminAccess
{
    // Keep entry redirects and the backend route gate on the same role list.
    public const EXCHANGE_ROLES = [
        'superadmin', 'admin', 'user_leader', 'salesman',
        'user_editor', 'finance_manager', 'content_editor', 'assets_editor',
        'perm_dashboard', 'perm_markets', 'perm_currencies', 'perm_networks',
        'perm_bank_accounts', 'perm_p2p', 'perm_stakings', 'perm_launchpads',
        'perm_quantify', 'perm_liquidity', 'perm_users', 'perm_kyc_documents',
        'perm_vouchers', 'perm_deposits', 'perm_withdrawals', 'perm_finances',
        'perm_pages', 'perm_articles', 'perm_languages', 'perm_options_templates',
        'perm_support_tickets', 'perm_cold_storage', 'perm_settings',
    ];

    public const ROLES = [...self::EXCHANGE_ROLES, ...UmiAdminAccess::ROLES];

    /** Same role predicates as the route gate; this is menu metadata, never authorization. */
    public static function routes(?User $user): array
    {
        if (!self::allows($user)) return [];
        $names = [];
        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            if (!str_starts_with((string) $route->getName(), 'admin.') || !in_array('GET', $route->methods(), true)) continue;
            $allowed = true;
            foreach ($route->middleware() as $gate) {
                if (!is_string($gate) || !str_starts_with($gate, 'role:')) continue;
                $roles = explode('|', explode(',', substr($gate, 5))[0]);
                if (!$user->hasAnyRole($roles)) { $allowed = false; break; }
            }
            if ($allowed) $names[] = $route->getName();
        }
        return $names;
    }

    public static function allows(?User $user): bool
    {
        return $user !== null && !$user->deleted && !$user->deactivated && $user->hasAnyRole(self::ROLES);
    }
}
