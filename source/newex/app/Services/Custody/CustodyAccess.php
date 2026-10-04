<?php
namespace App\Services\Custody;
use Illuminate\Http\Request;
use PragmaRX\Google2FA\Google2FA;

final class CustodyAccess {
    public static function allowed($user): bool {
        return $user && !$user->deleted && !$user->deactivated && $user->hasAnyRole(['superadmin','perm_cold_storage'])
            && ((int)$user->is_leng===1 || $user->hasRole('perm_cold_storage'));
    }
    public static function fresh(Request $r): bool {
        if(!$r->hasSession())return false;
        $grant=$r->session()->get('custody_verified');
        return !empty($r->user()?->two_factor_secret) && is_array($grant) && ($grant['user']??0)===$r->user()->id && ($grant['until']??0)>time();
    }
    public static function requireFresh(Request $r): void {
        abort_unless(self::allowed($r->user()),403);
        if(!self::fresh($r)) CustodyNetwork::fail('CUSTODY_2FA_REQUIRED');
    }
    public static function verify(Request $r): void {
        abort_unless(self::allowed($r->user()),403);
        $v=$r->validate(['code'=>['required','digits:6']]);
        if (!$r->user()->two_factor_secret || !(new Google2FA())->verifyKey(decrypt($r->user()->two_factor_secret),$v['code'])) CustodyNetwork::fail('CUSTODY_2FA_INVALID');
        $r->session()->put('custody_verified',['user'=>$r->user()->id,'until'=>time()+300]);
    }
}
