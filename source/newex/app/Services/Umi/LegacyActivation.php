<?php
namespace App\Services\Umi;
use App\Models\Umi\LegacyAccount;
use App\Models\User\User;
use App\Mail\UmiActivationCode;
use Illuminate\Support\Facades\{DB,Hash,Mail,RateLimiter,Crypt};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LegacyActivation {
    public static function lookup(string $value): string {return hash_hmac('sha256',strtolower(trim($value)),config('app.key'));}
    public function find(string $identifier): ?LegacyAccount {
        $identifier=trim($identifier);
        if(preg_match('/^(?:UMI:)?([1-9][0-9]{0,17})$/i',$identifier,$m)) return LegacyAccount::find($m[1]);
        if(str_contains($identifier,'@')) {
            $matches=LegacyAccount::where('email_lookup',self::lookup($identifier))->limit(2)->get();
            return $matches->count()===1?$matches->first():null;
        }
        return LegacyAccount::where('legacy_uuid',$identifier)->first();
    }
    public function resolveLogin(string $identifier): ?User {
        if(!preg_match('/^UMI:([1-9][0-9]{0,17})$/i',$identifier,$m)) return null;
        $a=LegacyAccount::where('legacy_id',$m[1])->where('activation_status','activated')->first();
        return $a?User::authorizable()->where('deactivated',false)->find($a->user_id):null;
    }
    public function requestCode(string $identifier): string {
        $id=(string)Str::uuid();
        $key='umi-activation:'.self::lookup($identifier);
        if(RateLimiter::tooManyAttempts($key,1)) throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json(['message'=>__('操作较频繁，请一分钟后重试。'),'retry_after'=>RateLimiter::availableIn($key)],429)->header('Retry-After',(string)RateLimiter::availableIn($key)));
        RateLimiter::hit($key,60);
        $a=$this->find($identifier);
        if(!$a || $a->activation_status!=='pending' || $a->legacy_status===0 || !$a->approved_email || $a->user_id) return $id;
        if(User::whereRaw('lower(email) = ?',[$a->approved_email])->exists()) return $id;
        $code=(string)random_int(10000000,99999999);
        DB::transaction(function() use($a,$id,$code) {
            $a=LegacyAccount::whereKey($a->legacy_id)->lockForUpdate()->firstOrFail();
            if($a->activation_status!=='pending'||$a->legacy_status===0||!$a->approved_email||$a->user_id)return;
            DB::table('umi_activation_challenges')->where('legacy_id',$a->legacy_id)->whereNull('used_at')->update(['used_at'=>now()]);
            DB::table('umi_activation_challenges')->insert(['id'=>$id,'legacy_id'=>$a->legacy_id,
                'email_lookup'=>self::lookup($a->approved_email),'code_hash'=>Hash::make($code),
                'expires_at'=>now()->addMinutes(10),'created_at'=>now()]);
            \App\Jobs\SendUmiActivationCode::dispatch($id,$code,app()->getLocale())->onQueue('default')->afterCommit();
        });
        return $id;
    }
    public function activate(string $challengeId,string $code,string $password): User {
        // Failed-attempt increments must commit, not roll back with a validation exception.
        $result=DB::transaction(function() use($challengeId,$code,$password) {
            DB::table('umi_business_state')->where('id',1)->lockForUpdate()->first();
            $hint=DB::table('umi_activation_challenges')->find($challengeId);
            if(!$hint) return null;
            $a=LegacyAccount::whereKey($hint->legacy_id)->lockForUpdate()->first();
            $c=DB::table('umi_activation_challenges')->where('id',$challengeId)->lockForUpdate()->first();
            if(!$a || !$c || $c->used_at || now()->gte($c->expires_at) || $c->attempts>=5 || $a->user_id ||
                $a->activation_status!=='pending' || $a->legacy_status===0 || !$a->approved_email ||
                !hash_equals($c->email_lookup,self::lookup($a->approved_email))) return null;
            DB::table('umi_activation_challenges')->where('id',$challengeId)->increment('attempts');
            if(!Hash::check($code,$c->code_hash)) return null;
            if(User::whereRaw('lower(email) = ?',[$a->approved_email])->exists()) return null;
            $u=User::create(['name'=>$a->identity['nickname']?:'UMI 用户','email'=>$a->approved_email,
                'password'=>Hash::make($password),'email_verified_at'=>now(),'withdrawal_disabled'=>true,
                'referral_id'=>null,'referral_code'=>'DP'.Str::upper(Str::random(14))]);
            $u->assignRole('user');
            $a->update(['user_id'=>$u->id,'activation_status'=>'activated','activated_at'=>now()]);
            app(\App\Services\Umi\Business\Continuity::class)->attach($u->id);
            DB::table('umi_activation_challenges')->where('legacy_id',$a->legacy_id)->whereNull('used_at')->update(['used_at'=>now()]);
            return $u;
        });
        if(!$result) throw ValidationException::withMessages(['code'=>__('暂时无法完成验证，请核对验证码，或联系账户支持。')]);
        return $result;
    }
    public function propose(int $id, string $email,string $evidence,int $admin): void {
        $a=LegacyAccount::findOrFail($id);
        if($a->user_id || $a->legacy_status===0) throw ValidationException::withMessages(['email'=>__('此账户当前不能变更激活渠道。')]);
        DB::table('umi_identity_reviews')->insert(['legacy_id'=>$id,'proposed_by'=>$admin,'email'=>Crypt::encryptString(strtolower(trim($email))),
            'evidence'=>Crypt::encryptString($evidence),'created_at'=>now(),'updated_at'=>now()]);
    }
    public function approve(int $id,int $admin): void {
        DB::transaction(function() use($id,$admin) {
            $hint=DB::table('umi_identity_reviews')->find($id); abort_unless($hint,404);
            $a=LegacyAccount::whereKey($hint->legacy_id)->lockForUpdate()->firstOrFail();
            $r=DB::table('umi_identity_reviews')->where('id',$id)->lockForUpdate()->first();
            if($r->state!=='pending' || (int)$r->proposed_by===$admin || $a->user_id || $a->legacy_status===0)
                throw ValidationException::withMessages(['review'=>__('需要另一位管理员复核，且账户必须处于待激活状态。')]);
            $email=Crypt::decryptString($r->email); $hash=self::lookup($email);
            if(LegacyAccount::where('approved_email_lookup',$hash)->where('legacy_id','!=',$a->legacy_id)->exists())
                throw ValidationException::withMessages(['review'=>__('邮箱已关联其他账户，需处理归属冲突后继续。')]);
            $a->update(['approved_email'=>$email,'approved_email_lookup'=>$hash]);
            DB::table('umi_activation_challenges')->where('legacy_id',$a->legacy_id)->whereNull('used_at')->update(['used_at'=>now()]);
            DB::table('umi_identity_reviews')->where('id',$id)->update(['state'=>'approved','reviewed_by'=>$admin,'reviewed_at'=>now(),'updated_at'=>now()]);
            DB::table('umi_identity_reviews')->where('legacy_id',$a->legacy_id)->where('state','pending')->update(['state'=>'superseded','updated_at'=>now()]);
        });
    }
}
