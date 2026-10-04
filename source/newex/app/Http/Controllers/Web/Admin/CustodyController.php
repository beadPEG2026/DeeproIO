<?php
namespace App\Http\Controllers\Web\Admin;
use App\Http\Controllers\Controller;
use App\Services\Custody\{CustodyAccess,CustodyBridge,CustodyService,ColdRuleService,CustodyNetwork};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class CustodyController extends Controller {
    public function __construct(){ $this->middleware(function($r,$next){abort_unless(CustodyAccess::allowed($r->user()),403);return $next($r);}); }
    public function index(Request $r) {
        $auditQuery=app(\App\Services\Custody\AuditQuery::class);
        $filters=$auditQuery->filters($r);
        $audits=$auditQuery->query($filters)->orderByDesc('a.id')->paginate(25,['*'],'audit_page')->withQueryString();
        $audits->getCollection()->transform(fn($a)=>$auditQuery->redact($a));
        $networks=DB::table('custody_networks')->where('chain','!=','bitcoin')->orderBy('chain')->get()->map(function($n){$n->hot_address=app(CustodyService::class)->hotSender($n->chain);$n->key_configured=$n->chain==='bitcoin'?(bool)app(\App\Services\Wallet\BitcoinWalletManager::class)->active():trim((string)setting($n->chain.'.private_key'))!=='';return$n;});
        $transfers=DB::table('custody_transfers as t')->join('currencies as c','c.id','=','t.currency_id')->select('t.id','t.purpose','t.chain','t.sender','t.destination','t.amount','t.sent_amount','t.fee','t.status','t.txn','t.last_error','t.requested_by','t.approved_by','t.created_at','t.completed_at','c.symbol')->orderByDesc('t.id')->paginate(30)->withQueryString();
        $transfers->getCollection()->transform(function($t){$t->automation_reason=app(\App\Services\Custody\SweepAutomation::class)->pendingReason($t);return $t;});
        return Inertia::render('Admin/ColdStorage/Custody',['networks'=>$networks,'bank'=>DB::table('fiat_deposit_instructions')->find(1),'rules'=>DB::table('cold_storage as r')->join('currencies as c','c.id','=','r.currency_id')->join('networks as n','n.id','=','r.network_id')->where('r.currency_id','>',0)->select('r.*','c.symbol','n.name as network_name')->get(),'transfers'=>$transfers,
            'audits'=>$audits,'auditFilters'=>$filters,'auditExportUrl'=>route('admin.custody.audits.export',array_diff_key($filters,['audit_page'=>true])),'auditAssets'=>DB::table('currencies')->orderBy('symbol')->get(['id','symbol']),
            'twoFactorConfigured'=>!empty($r->user()->two_factor_secret),'verified'=>CustodyAccess::fresh($r)]);
    }
    public function exportAudits(Request $r) {
        $query=app(\App\Services\Custody\AuditQuery::class);
        return $query->export($query->filters($r));
    }
    public function estimate(int $id) {
        $task=DB::table('custody_transfers')->find($id);abort_unless($task,404);
        abort_unless(in_array($task->chain,['tron','ethereum','bnb','polygon','xlayer'],true),422);
        try {return response()->json(app(CustodyBridge::class)->call($task->chain,'estimate',app(CustodyService::class)->intent($task)));}
        catch(\Throwable $e){$code=preg_match('/^CUSTODY_[A-Z_]+$/D',$e->getMessage())?$e->getMessage():'CUSTODY_RESOURCE_ESTIMATE_UNAVAILABLE';return response()->json(['message'=>__($code)],422);}
    }
    public function network(Request $r,string $chain) {
        CustodyAccess::requireFresh($r);
        $v=$r->validate(['enabled'=>'required|boolean','auto_sweep'=>'required|boolean','auto_sweep_scope'=>'sometimes|required|in:new_live,all_verified','max_fee'=>['required','numeric','gt:0','regex:/^\d{1,18}(\.\d{1,18})?$/D'],'native_max_fee'=>['sometimes','nullable','numeric','gt:0','regex:/^\d{1,18}(\.\d{1,18})?$/D'],'daily_gas_limit'=>['required','numeric','gte:0','regex:/^\d{1,18}(\.\d{1,18})?$/D'],'confirmations'=>'required|integer|min:1|max:10000']);
        $minimum=['ethereum'=>12,'bnb'=>15,'polygon'=>128,'xlayer'=>64,'tron'=>20,'solana'=>1,'ton'=>1];abort_unless(isset($minimum[$chain]),404);
        if($v['confirmations']<$minimum[$chain] || (in_array($chain,['solana','ton'],true)&&$v['confirmations']!==1))CustodyNetwork::fail('CUSTODY_CONFIRMATIONS_TOO_LOW');
        if($v['enabled'])app(CustodyBridge::class)->call($chain,'validate',['sender'=>trim((string)setting($chain.'.wallet')),'private_key'=>setting($chain.'.private_key')]);
        DB::transaction(function()use($chain,$v,$r){$before=DB::table('custody_networks')->where('chain',$chain)->lockForUpdate()->first();DB::table('custody_networks')->where('chain',$chain)->update($v+['updated_by'=>$r->user()->id,'updated_at'=>now()]);app(CustodyService::class)->audit('network.updated',['chain'=>$chain,'before'=>$before,'after'=>$v]);});
        return back();
    }
    public function action(Request $r,string $kind,int $id,string $action) {
        CustodyAccess::requireFresh($r);
        if($kind==='rule'&&in_array($action,['approve','pause'],true))app(ColdRuleService::class)->$action($id,$r->user()->id);
        elseif($kind==='transfer'&&in_array($action,['approve','cancel','resume'],true))app(CustodyService::class)->$action($id,$r->user()->id);
        elseif($kind==='transfer'&&$action==='recheck')app(CustodyService::class)->run($id);
        else abort(404);
        return back();
    }
    public function grant(Request $r) {
        CustodyAccess::requireFresh($r);abort_unless($r->user()->hasRole('superadmin'),403);
        $v=$r->validate(['user_id'=>'required|integer|exists:users,id','enabled'=>'required|boolean']);
        DB::transaction(function()use($v){$u=\App\Models\User\User::whereKey($v['user_id'])->lockForUpdate()->firstOrFail();abort_if($u->deleted||$u->deactivated,422);$u->is_leng=(int)$v['enabled'];$u->save();\Spatie\Permission\Models\Role::findOrCreate('perm_cold_storage','web');if($v['enabled'])$u->assignRole('perm_cold_storage');else$u->removeRole('perm_cold_storage');app(CustodyService::class)->audit('permission.updated',['user_id'=>$u->id,'enabled'=>$v['enabled']]);});return back();
    }
}
