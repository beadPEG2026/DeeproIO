<?php
namespace App\Http\Controllers\Web\Client;
use App\Http\Controllers\Controller;
use App\Services\Umi\Business\{Engine,Portfolio};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
class UmiBusinessController extends Controller {
    private function access(Request $r,Engine $e):void {$e->rules->assertReadable();abort_if($r->user()->deactivated||$r->user()->deleted,403);}
    public function index(Request $r,Engine $e,Portfolio $p){
        if(\App\Services\Umi\V2\FundedRuntime::schemaReady())
            return redirect()->route('umi.portfolio',array_merge($r->query(),['tab'=>'history']));
        $this->access($r,$e);$v=$r->validate(['page'=>'sometimes|integer|min:1|max:100000']);return Inertia::render('Umi/Finance',['state'=>$p->state(),'legacy'=>\App\Models\Umi\LegacyAccount::where('user_id',$r->user()->id)->first(['legacy_id','activation_status']),'portfolio'=>$p->show($e->owned($r->user()->id),(int)($v['page']??1))])->toResponse($r)->header('Cache-Control','private, no-store');}
    private function input(Request $r,bool $preview=false):array {
        if(\App\Services\Umi\V2\FundedRuntime::schemaReady())
            throw \Illuminate\Validation\ValidationException::withMessages(['action'=>'账户已统一，请在我的 UMI 中办理。']);
        return $r->validate(['action'=>'required|in:enroll,purchase,claim,treasure_deposit,treasure_withdraw,reserve_deposit,swap,transfer,stake,unstake,custody_in,custody_out,claim_to_wallet','request_key'=>($preview?'sometimes':'required').'|string|max:120','amount'=>'sometimes|required|string|max:48','parent'=>'required_if:action,enroll|nullable|string|max:24','pocket'=>'sometimes|string|max:20','asset'=>'sometimes|in:UMI,USDT','quote_rule_id'=>'sometimes|integer','expected_rule_id'=>'sometimes|integer','recipient'=>'sometimes|string|max:24']);
    }
    public function preview(Request $r,Engine $e,\App\Services\Umi\Business\UserOperations $operations){
        $this->access($r,$e);$v=$this->input($r,true);unset($v['request_key']);
        return response()->json($operations->preview($r->user()->id,$v))->header('Cache-Control','private, no-store');
    }
    public function submit(Request $r,Engine $e,\App\Services\Umi\Business\UserOperations $operations){
        $this->access($r,$e);$e->rules->assertLocal();$v=$this->input($r);$key=$v['request_key'];unset($v['request_key']);
        $result=$operations->execute($r->user()->id,$v,$key);
        return response()->json(['message'=>__('操作已完成'),'operation'=>$result])->header('Cache-Control','no-store');
    }
}
