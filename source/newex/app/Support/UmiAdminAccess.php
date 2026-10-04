<?php
namespace App\Support;

use App\Models\User\User;

/** Explicit UMI grants; exchange roles never imply access to UMI money operations. */
final class UmiAdminAccess
{
    public const ROLES = ['perm_umi_read','perm_umi_export','perm_umi_settlement','perm_umi_burn',
        'perm_umi_orders','perm_umi_stock','perm_umi_quotes','perm_umi_legacy','perm_umi_settings'];
    public const ACTIONS = ['settings'=>'settings','quote'=>'quotes','spot_quote'=>'quotes','day'=>'settlement',
        'queue_burn'=>'burn','sync_burn'=>'burn','recover_points'=>'burn','cancel_intake'=>'orders',
        'activate_legacy'=>'legacy','account_cutover'=>'legacy','approve_snapshot'=>'legacy',
        'stock_settle'=>'stock','stock_transfer'=>'stock','stock_writeoff'=>'stock'];

    public static function roleGate(): string { return 'superadmin|'.implode('|',self::ROLES); }
    public static function allows(?User $user, string $capability): bool
    {
        if (!$user || $user->deleted || $user->deactivated) return false;
        if ($user->hasRole('superadmin')) return true;
        return $capability === 'read' ? $user->hasAnyRole(self::ROLES)
            : in_array('perm_umi_'.$capability,self::ROLES,true) && $user->hasRole('perm_umi_'.$capability);
    }
    public static function capabilities(?User $user): array
    {
        $result=[];foreach(self::ROLES as $role) {$key=substr($role,9);$result[$key]=self::allows($user,$key);}return $result;
    }
    public static function authorize(?User $user, string $capability): void { abort_unless(self::allows($user,$capability),403); }
    public static function authorizeAction(?User $user, string $action): void
    {
        abort_unless(isset(self::ACTIONS[$action]),422);
        self::authorize($user,self::ACTIONS[$action]);
    }
}
