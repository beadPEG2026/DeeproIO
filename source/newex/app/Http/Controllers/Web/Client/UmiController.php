<?php
namespace App\Http\Controllers\Web\Client;
use App\Http\Controllers\Controller;
use App\Models\Umi\LegacyAccount;
use App\Services\Umi\{LegacyActivation,LegacyPortfolio};
use Illuminate\Http\Request;
use Inertia\Inertia;
class UmiController extends Controller {
    public function bindingCode(Request $r,\App\Services\Umi\LegacyBinding $s){$v=$r->validate(['identifier'=>'required|string|max:255']);return response()->json(['challenge'=>$s->request($r->user(),$v['identifier']),'message'=>__('若归属和核定邮箱匹配，验证码将发送至当前账户邮箱。')])->header('Cache-Control','no-store');}
    public function bindingComplete(Request $r,\App\Services\Umi\LegacyBinding $s){$v=$r->validate(['challenge'=>'required|uuid','code'=>'required|digits:8']);$s->complete($r->user(),$v['challenge'],$v['code']);return response()->json(['message'=>__('原 UMI 账户已绑定，原关系和权益保持不变。')])->header('Cache-Control','no-store');}
    public function recover(Request $r) {
        $v=$r->validate(['identifier'=>'required|string|max:255','reply_email'=>'required|email:rfc|max:255','evidence'=>'required|string|min:20|max:2000']);
        $key='umi-recovery:'.LegacyActivation::lookup($v['identifier']);
        abort_if(\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($key,2),429);
        \Illuminate\Support\Facades\RateLimiter::hit($key,86400);
        $id=(string)\Illuminate\Support\Str::uuid();
        \Illuminate\Support\Facades\DB::table('umi_recovery_requests')->insert(['id'=>$id,
            'identifier'=>encrypt($v['identifier']),'reply_email'=>encrypt($v['reply_email']),
            'evidence'=>encrypt($v['evidence']),'created_at'=>now(),'updated_at'=>now()]);
        return response()->json(['reference'=>$id,'message'=>__('核验申请已记录，请保存申请编号。账户归属确认前不会变更密码或联系方式。')])->header('Cache-Control','no-store');
    }
    public function activation() {return Inertia::render('Umi/Activate');}
    public function send(Request $r,LegacyActivation $service) {
        $v=$r->validate(['identifier'=>'required|string|max:255']);
        return response()->json(['challenge'=>$service->requestCode($v['identifier']),
            'message'=>__('若账户已核定可用邮箱，验证码将发送至该邮箱。未收到时请联系账户支持核对归属。')])->header('Cache-Control','no-store');
    }
    public function complete(Request $r,LegacyActivation $service) {
        $v=$r->validate(['challenge'=>'required|uuid','code'=>'required|digits:8',
            'password'=>['required','string','min:12','max:72','confirmed']]);
        $service->activate($v['challenge'],$v['code'],$v['password']);
        return response()->json(['message'=>__('密码设置成功，请使用核定邮箱和密码按登录页提示登录。原 UID 与团队关系保持不变。'),'login_url'=>route('login')])->header('Cache-Control','no-store');
    }
    public function account(Request $r,LegacyPortfolio $service) {
        if (\App\Services\Umi\V2\FundedRuntime::schemaReady()) {
            $v=$r->validate(['section'=>'nullable|in:overview,burn,team-income,usdt,team-usdt,team,treasury,bao,vip', 'page'=>'nullable|integer|min:1|max:100000']);
            return redirect()->route('umi.portfolio', ['tab'=>'history',
                'legacy_section'=>$v['section']??'overview', 'page'=>$v['page']??1]);
        }
        $v=$r->validate(['section'=>'nullable|in:overview,burn,team-income,usdt,team-usdt,team,treasury,bao,vip', 'page'=>'nullable|integer|min:1|max:100000']);
        $a=LegacyAccount::where('user_id',$r->user()->id)->first();
        return Inertia::render('Umi/Account',['portfolio'=>$a?$service->show($a,$v['section']??'overview',(int)($v['page']??1)):null,
            'section'=>$v['section']??'overview','availability'=>app(\App\Services\Wallet\AssetAvailability::class)->forSymbol('UMI', (int)$r->user()->id),'asset'=>config('umi.asset'),'adminView'=>false])->toResponse($r)->header('Cache-Control','private, no-store');
    }
}
