<?php
namespace App\Services\Umi;
use App\Models\Umi\LegacyAccount;
use App\Models\User\User;
use App\Services\Umi\Business\{Continuity,Engine};
use Illuminate\Support\Facades\{DB,Hash,RateLimiter};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
final class LegacyBinding {
    public function request(User $user,string $identifier):string {
        abort_unless($user->hasVerifiedEmail()&&!$user->deactivated&&!$user->deleted,403);
        $id=(string)Str::uuid();$key='umi-binding:'.$user->id;
        if(RateLimiter::tooManyAttempts($key,1)) throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json(['message'=>__('操作较频繁，请一分钟后重试。'),'retry_after'=>RateLimiter::availableIn($key)],429)->header('Retry-After',(string)RateLimiter::availableIn($key)));RateLimiter::hit($key,60);
        if (\Illuminate\Support\Facades\Schema::hasTable('umi_v2_members') && DB::table('umi_v2_members')->where('user_id',$user->id)->exists()) return $id;
        $a=app(LegacyActivation::class)->find($identifier);
        if(!$a||$a->user_id||$a->legacy_status===0||!$a->approved_email||strtolower($a->approved_email)!==strtolower($user->email)||LegacyAccount::where('user_id',$user->id)->exists()||app(Engine::class)->owned($user->id))return $id;
        $code=(string)random_int(10000000,99999999);
        DB::transaction(function()use($id,$a,$user,$code){
            DB::table('umi_binding_challenges')->where('user_id',$user->id)->whereNull('used_at')->update(['used_at'=>now()]);
            DB::table('umi_binding_challenges')->insert(['id'=>$id,'legacy_id'=>$a->legacy_id,'user_id'=>$user->id,'email_lookup'=>LegacyActivation::lookup($user->email),'code_hash'=>Hash::make($code),'expires_at'=>now()->addMinutes(10),'created_at'=>now()]);
            \App\Jobs\SendUmiBindingCode::dispatch($id,$code,app()->getLocale())->onQueue('default')->afterCommit();
        });return $id;
    }
    public function complete(User $user,string $id,string $code):void {
        abort_unless($user->hasVerifiedEmail()&&!$user->deactivated&&!$user->deleted,403);
        $ok=DB::transaction(function()use($user,$id,$code){
            DB::table('users')->where('id',$user->id)->lockForUpdate()->first();
            // A new v2 invitation is immutable: binding a different history needs a reviewed correction, never silent reparenting.
            if (\Illuminate\Support\Facades\Schema::hasTable('umi_v2_members') && DB::table('umi_v2_members')->where('user_id',$user->id)->exists()) return false;
            DB::table('umi_business_state')->where('id',1)->lockForUpdate()->first();
            $hint=DB::table('umi_binding_challenges')->find($id);if(!$hint||(int)$hint->user_id!==$user->id)return false;
            $a=LegacyAccount::whereKey($hint->legacy_id)->lockForUpdate()->first();
            $c=DB::table('umi_binding_challenges')->where('id',$id)->lockForUpdate()->first();
            if(!$c||!$a||$c->used_at||now()->gte($c->expires_at)||$c->attempts>=5||$a->user_id||$a->legacy_status===0||!$a->approved_email||!hash_equals($c->email_lookup,LegacyActivation::lookup($user->email))||!hash_equals($c->email_lookup,LegacyActivation::lookup($a->approved_email)))return false;
            DB::table('umi_binding_challenges')->where('id',$id)->increment('attempts');
            if(!Hash::check($code,$c->code_hash)||app(Engine::class)->owned($user->id)||LegacyAccount::where('user_id',$user->id)->exists())return false;
            $a->update(['user_id'=>$user->id,'activation_status'=>'activated','activated_at'=>now()]);
            app(Continuity::class)->attach($user->id);
            DB::table('umi_binding_challenges')->where('legacy_id',$a->legacy_id)->whereNull('used_at')->update(['used_at'=>now()]);
            return true;
        });
        if(!$ok)throw ValidationException::withMessages(['code'=>__('绑定未完成，请核对验证码、账户归属和已核定邮箱。')]);
    }
}
