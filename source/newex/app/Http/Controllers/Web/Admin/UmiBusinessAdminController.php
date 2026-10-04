<?php
namespace App\Http\Controllers\Web\Admin;
use App\Http\Controllers\Controller;
use App\Services\Umi\Business\{Engine,Portfolio,Settlement};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
class UmiBusinessAdminController extends Controller {
    public function continuity(Request $r,Engine $e,\App\Services\Umi\Business\Continuity $c){
        $e->rules->assertReadable();$pages=app(\App\Support\UmiReportPage::class);$filters=$pages->filters($r,'continuity');
        return Inertia::render('Admin/Umi/Continuity',['report'=>$pages->apply($c->preview(),$filters,'continuity',$r),'filters'=>$filters,'readOnly'=>!$e->rules->writable()])->toResponse($r)->header('Cache-Control','private, no-store');
    }
    public function settlementPreview(Request $r,Engine $e,Settlement $s){abort_if(\App\Services\Umi\V2\FundedRuntime::schemaReady(),410,'请在 UMI 运营中心结算。');$e->rules->assertReadable();$v=$r->validate(['day'=>'required|date_format:Y-m-d']);return response()->json($s->preview($r->user()->id,$v['day']))->header('Cache-Control','no-store');}
    public function shadow(Request $r,Engine $e,\App\Services\Umi\LegacyShadow $s){
        $e->rules->assertReadable();$pages=app(\App\Support\UmiReportPage::class);$filters=$pages->filters($r,'shadow');$report=$s->report();
        if($r->query('format')==='json')return response()->json($report)->header('Cache-Control','private, no-store')->header('Content-Disposition','attachment; filename="umi-snapshot-shadow.json"');
        return Inertia::render('Admin/Umi/Shadow',['report'=>$pages->apply($report,$filters,'shadow',$r),'filters'=>$filters])->toResponse($r)->header('Cache-Control','private, no-store');
    }
    public function index(Request $r,Engine $e,Portfolio $p){
        if(\App\Services\Umi\V2\FundedRuntime::schemaReady())
            return redirect()->route('admin.umi.operations',array_merge($r->query(),['tab'=>'history']));
        $e->rules->assertReadable();$v=$r->validate(['level'=>'nullable|integer|min:0|max:9','binding'=>'nullable|in:bound,unbound','rewards'=>'nullable|in:included,excluded','search'=>'nullable|string|max:100','accounts_page'=>'sometimes|integer|min:1|max:100000','account'=>'nullable|integer|min:1','page'=>'sometimes|integer|min:1|max:100000']);return Inertia::render('Admin/Umi/Business',$p->admin((int)($v['account']??0),(int)($v['page']??1),trim($v['search']??''),(int)($v['accounts_page']??1),array_intersect_key($v,array_flip(['level','binding','rewards']))))->toResponse($r)->header('Cache-Control','private, no-store');}
    public function submit(Request $r,Engine $e,Settlement $settlement){
        abort_if(\App\Services\Umi\V2\FundedRuntime::schemaReady(),410,'账户已统一，请在 UMI 运营中心办理。');
        $e->rules->assertLocal();$v=$r->validate(['action'=>'required|in:settle,rules,gift,pause,plan,level,quota,fund_pool,continuity,reward_correction,release_revision','request_key'=>'required|string|max:120','day'=>'sometimes|date_format:Y-m-d','rules'=>'sometimes|array','expected_rule_id'=>'sometimes|integer','reason'=>'sometimes|string|max:2000','account_id'=>'sometimes|integer|min:1','amount'=>'sometimes|string|max:48','multiplier'=>'sometimes|string|max:48','paused'=>'sometimes|boolean','plan_id'=>'sometimes|integer|min:1','status'=>'sometimes|in:active,paused','manual_level'=>'sometimes|integer|min:0|max:9','reward_excluded'=>'sometimes|boolean','direction'=>'sometimes|in:add,subtract','asset'=>'sometimes|in:UMI,USDT','pool'=>'sometimes|in:distribution,inventory','fingerprint'=>'sometimes|string|size:64','ids'=>'sometimes|array|max:100','ids.*'=>'integer|min:1','target_type'=>'sometimes|in:plan,continuity','target_id'=>'sometimes|integer|min:1','expected_revision'=>'sometimes|integer|min:0','daily_rate'=>'sometimes|string|max:48','daily_amount'=>'sometimes|string|max:48']);
        $actor=$r->user()->id;$action=$v['action'];$key=$v['request_key'];unset($v['action'],$v['request_key']);
        if($action==='release_revision')$result=app(\App\Services\Umi\Business\ReleaseSchedule::class)->revise($actor,$v,$key);
        elseif($action==='continuity')$result=app(\App\Services\Umi\Business\Continuity::class)->import($actor,$v['fingerprint']??'',$v['reason']??'',$key);
        elseif($action==='reward_correction')$result=app(\App\Services\Umi\Business\RewardCorrections::class)->pay($actor,$v['ids']??[],$v['reason']??'',$key);
        elseif($action==='fund_pool')$result=app(\App\Services\Umi\Business\CustodyTransfers::class)->transfer($actor,$v['asset']??'UMI','in',$v['amount']??'',$key,$v['pool']??'distribution');
        elseif($action==='settle')$result=$settlement->advance($actor,$v['day']??'',$key);
        elseif($action==='rules')$result=$e->run(null,$actor,'rules',$v,$key,function()use($e,$v,$actor){if((int)($v['expected_rule_id']??0)!==(int)$e->rules->current()['id'])$e->fail('rules',__('配置已更新，请刷新后再修改。'));$e->rules->update($v['rules']??[],$v['reason']??'',$actor);});
        elseif($action==='gift')$result=$e->purchase((int)($v['account_id']??0),$actor,$v['amount']??'',$key,['multiplier'=>$v['multiplier']??'3','note'=>$v['reason']??'',...(isset($v['daily_rate'])?['daily_rate'=>$v['daily_rate']]:[])]);
        else{$r->validate(match($action){'pause'=>['paused'=>'required|boolean'],'plan'=>['plan_id'=>'required|integer','status'=>'required|in:active,paused'],'level'=>['account_id'=>'required|integer','manual_level'=>'required|integer|min:0|max:9','reward_excluded'=>'required|boolean'],'quota'=>['account_id'=>'required|integer','amount'=>'required|string','direction'=>'required|in:add,subtract']});$result=$e->manage($actor,$action,$v,$key);}
        return response()->json(['message'=>__('已保存'),'operation'=>$result])->header('Cache-Control','no-store');
    }
}
