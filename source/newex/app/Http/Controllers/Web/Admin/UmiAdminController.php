<?php
namespace App\Http\Controllers\Web\Admin;
use App\Http\Controllers\Controller;
use App\Models\Umi\LegacyAccount;
use App\Services\Umi\{LegacyActivation,LegacyPortfolio};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Crypt};
use Inertia\Inertia;
class UmiAdminController extends Controller {
    public function reconciliation(Request $r,\App\Services\Umi\LegacyReconciliation $service) {
        $v=$r->validate(['legacy_id'=>'nullable|integer|min:1','state'=>'nullable|in:issues,all,matched,difference,missing,invalid,ambiguous,notice','page'=>'nullable|integer|min:1|max:100000','format'=>'nullable|in:json']);
        $report=$service->report(isset($v['legacy_id'])?(int)$v['legacy_id']:null);
        $batch=DB::table('umi_continuity_batches')->latest('created_at')->first();
        $runtime=['imported'=>(bool)$batch,'cutoff'=>$batch?->cutoff,'imported_at'=>$batch?->created_at,
            'linked_accounts'=>DB::table('umi_business_accounts')->whereNotNull('legacy_id')->whereNotNull('user_id')->count(),
            'openings'=>DB::table('umi_continuity_openings')->count(),
            'writable'=>app(\App\Services\Umi\Business\Rules::class)->writable()];
        if(($v['format']??null)==='json')return response()->json($report+['evidence'=>config('umi-evidence'),'runtime'=>$runtime])->header('Cache-Control','private, no-store')->header('Content-Disposition','attachment; filename="umi-historical-reconciliation.json"');
        $state=$v['state']??'issues';$page=(int)($v['page']??1);
        $checks=array_values(array_filter($report['checks'],fn($c)=>$state==='all'||($state==='issues'?!in_array($c['state'],['matched','notice'],true):$c['state']===$state)));
        unset($report['checks']);
        return Inertia::render('Admin/Umi/Reconciliation',['report'=>$report,'evidence'=>config('umi-evidence'),'runtime'=>$runtime,'checks'=>['rows'=>array_slice($checks,($page-1)*25,25),'total'=>count($checks),'page'=>$page,'last_page'=>max(1,(int)ceil(count($checks)/25))],'filters'=>['legacy_id'=>$v['legacy_id']??null,'state'=>$state]])->toResponse($r)->header('Cache-Control','private, no-store');
    }
    public function index(Request $r) {
        if (\App\Services\Umi\V2\FundedRuntime::schemaReady()) {
            return redirect()->route('admin.umi.operations', ['tab' => 'archive'] + $r->query());
        }
        return Inertia::render('Admin/Umi/Index', $this->archiveData($r))
            ->toResponse($r)->header('Cache-Control', 'private, no-store');
    }
    public function archiveData(Request $r): array {
        $v=$r->validate(['search'=>'nullable|string|max:100','page'=>'nullable|integer|min:1|max:100000']);
        $q=LegacyAccount::query()->orderBy('legacy_id');
        if(!empty($v['search'])) {$search=$v['search'];$q->where(fn($q)=>$q->where('legacy_uuid',$search)->orWhere('legacy_id',ctype_digit($search)?$search:0));}
        $accounts=$q->paginate(25)->withQueryString()->through(fn($a)=>['id'=>$a->legacy_id,'uuid'=>$a->legacy_uuid,'nickname'=>$a->identity['nickname']??'',
            'parent_id'=>$a->parent_legacy_id,'level'=>$a->level,'legacy_status'=>$a->legacy_status,'activation_status'=>$a->activation_status,
            'contact_ready'=>(bool)$a->approved_email,'user_id'=>$a->user_id]);
        $reviews=DB::table('umi_identity_reviews')->orderByDesc('id')->limit(50)->get()->map(function($r){
            $email=Crypt::decryptString($r->email);$at=strpos($email,'@');
            return ['id'=>$r->id,'legacy_id'=>$r->legacy_id,'email'=>substr($email,0,1).'***'.substr($email,$at),
                'evidence'=>Crypt::decryptString($r->evidence),'state'=>$r->state,'proposed_by'=>$r->proposed_by,'reviewed_by'=>$r->reviewed_by];
        });
        return ['accounts'=>$accounts,'reviews'=>$reviews,
            'recoveryRequests'=>DB::table('umi_recovery_requests')->latest('updated_at')->limit(50)->get()->map(fn($q)=>['id'=>$q->id,'identifier'=>decrypt($q->identifier),'reply_email'=>decrypt($q->reply_email),'evidence'=>decrypt($q->evidence),'state'=>$q->state,'version'=>$q->version,'assigned_to'=>$q->assigned_to,'legacy_id'=>$q->legacy_id,
                'actions'=>DB::table('umi_recovery_actions')->where('request_id',$q->id)->orderBy('version')->get()->map(fn($a)=>['actor_id'=>$a->actor_id,'state'=>$a->to_state,'note'=>Crypt::decryptString($a->note),'created_at'=>$a->created_at])]),
            'batches'=>DB::table('umi_import_batches')->latest('id')->limit(10)->get()->map(fn($b)=>['id'=>$b->id,'summary'=>json_decode($b->summary,true),'created_at'=>$b->created_at]),
            'search'=>$v['search']??'','asset'=>config('umi.asset')];
    }
    public function show(Request $r,int $id,LegacyPortfolio $s) {
        if (\App\Services\Umi\V2\FundedRuntime::schemaReady()) return redirect()->route('admin.umi.operations',['tab'=>'archive','legacy_account'=>$id,'legacy_section'=>$r->query('section','overview'),'legacy_page'=>$r->query('page',1)]);
        $v=$r->validate(['section'=>'nullable|in:overview,burn,team-income,usdt,team-usdt,team,treasury,bao,vip','page'=>'nullable|integer|min:1|max:100000']);
        return Inertia::render('Umi/Account',['portfolio'=>$s->show(LegacyAccount::findOrFail($id),$v['section']??'overview',(int)($v['page']??1)),
            'section'=>$v['section']??'overview','asset'=>config('umi.asset'),'adminView'=>true])->toResponse($r)->header('Cache-Control','private, no-store');
    }
    public function propose(Request $r,int $id,LegacyActivation $s) {
        $v=$r->validate(['email'=>'required|email:rfc|max:255','evidence'=>'required|string|min:20|max:2000']);
        $s->propose($id,$v['email'],$v['evidence'],$r->user()->id);return back()->with('success',__('已提交，等待另一位管理员复核。'));
    }
    public function approve(Request $r,int $id,LegacyActivation $s) {$s->approve($id,$r->user()->id);return back()->with('success',__('激活邮箱已核定，用户可自行验证并设置密码。'));}
    public function recovery(Request $r,string $id,\App\Services\Umi\RecoveryWorkflow $service) {
        $v=$r->validate(['version'=>'required|integer|min:0','state'=>'required|in:in_review,needs_information,resolved,rejected','note'=>'required|string|min:10|max:2000','legacy_id'=>'nullable|integer|min:1']);
        $service->update($id,$r->user(),$v['version'],$v['state'],$v['note'],isset($v['legacy_id'])?(int)$v['legacy_id']:null);
        return back()->with('success',__('核查记录已保存。'));
    }
}
