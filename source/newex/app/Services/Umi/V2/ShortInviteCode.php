<?php
namespace App\Services\Umi\V2;

use Illuminate\Support\Facades\{DB,Schema};

/** Short public alias; member identities and all historical invitations stay intact. */
final class ShortInviteCode
{
    public static function taken(string $code): bool
    {
        return DB::table('umi_v2_members')->where('member_code',$code)->exists()
            || (Schema::hasTable('umi_v2_invite_aliases') && DB::table('umi_v2_invite_aliases')->where('code',$code)->exists())
            || (Schema::hasTable('umi_business_accounts') && DB::table('umi_business_accounts')->where('code',$code)->exists());
    }
    public function forMember(int $memberId): string
    {
        return DB::transaction(function () use ($memberId) {
            // One allocator across member/alias/legacy namespaces. Row lock also
            // keeps repeat visits idempotent on the isolated SQLite simulator.
            if (DB::getDriverName()==='pgsql') DB::select('SELECT pg_advisory_xact_lock(867240610)');
            $member=DB::table('umi_v2_members')->where('id',$memberId)->lockForUpdate()->first();
            if (!$member) throw new \DomainException('UMI 账户暂不可用。');
            foreach (DB::table('umi_v2_invite_aliases')->where('member_id',$memberId)->orderBy('created_at')->pluck('code') as $code) {
                if (preg_match('/^[A-HJ-NP-Z2-9]{6}$/D',$code) && preg_match('/[A-Z]/',$code) && preg_match('/[2-9]/',$code)) return $code;
            }
            $letters='ABCDEFGHJKLMNPQRSTUVWXYZ';$digits='23456789';$alphabet=$letters.$digits;
            do {
                $chars=[$letters[random_int(0,strlen($letters)-1)],$digits[random_int(0,strlen($digits)-1)]];
                for($i=2;$i<6;$i++)$chars[]=$alphabet[random_int(0,strlen($alphabet)-1)];
                for($i=5;$i>0;$i--){$j=random_int(0,$i);[$chars[$i],$chars[$j]]=[$chars[$j],$chars[$i]];}
                $code=implode('',$chars);
            } while (self::taken($code));
            DB::table('umi_v2_invite_aliases')->insert(['code'=>$code,'member_id'=>$memberId,'created_at'=>FundedTime::database(now())]);
            return $code;
        },3);
    }
}
