<?php
namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Services\Custody\{CustodyAccess,CustodyService,CustodyNetwork};
use App\Services\Wallet\{BitcoinWalletManager,BitcoinWalletRpc};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Cache};
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class BitcoinWalletController extends Controller {
    public function __construct(){ $this->middleware(function($r,$next){abort_unless(CustodyAccess::allowed($r->user()),403);return $next($r);}); }
    public function index(Request $r,BitcoinWalletRpc $rpc) {
        $node=[];$available=[];$error=null;$currentWallet=null;
        try {$node=$rpc->call('getblockchaininfo');$available=array_column($rpc->call('listwalletdir')['wallets']??[],'name');
            $control=DB::table('bitcoin_wallet_control')->find(1);$name=app(BitcoinWalletManager::class)->active()->name??$control->legacy_wallet_name;
            $info=$rpc->call('getwalletinfo',[],$name);$currentWallet=['name'=>$info['walletname']??'', 'scanning'=>$info['scanning']??false,'balances'=>$rpc->call('getbalances',[],$name)['mine']??[]];}
        catch(\Throwable $e){$error=$this->error($e);}
        $wallets=DB::table('bitcoin_wallets')->orderBy('id')->get()->map(function($w)use($rpc){
            $w->scan=Cache::get(\App\Services\Deposit\BitcoinWalletScanner::STATE.':wallet:'.$w->id,[]);
            try{$info=$rpc->call('getwalletinfo',[],$w->name);$w->scanning=$info['scanning']??false;
                $w->can_sign=($info['private_keys_enabled']??false)&&(!isset($info['unlocked_until'])||$info['unlocked_until']>time());
                $w->balances=$rpc->call('getbalances',[],$w->name)['mine']??[];
            }catch(\Throwable $e){$w->error=$this->error($e);}
            return $w;
        });
        return Inertia::render('Admin/ColdStorage/Bitcoin',['wallets'=>$wallets,'available'=>$available,
            'node'=>array_intersect_key($node,array_flip(['chain','blocks','headers','verificationprogress','initialblockdownload'])),
            'currentWallet'=>$currentWallet,'nodeError'=>$error,'control'=>DB::table('bitcoin_wallet_control')->find(1),'network'=>DB::table('custody_networks')->where('chain','bitcoin')->first(),
            'verified'=>CustodyAccess::fresh($r),'twoFactorConfigured'=>!empty($r->user()->two_factor_secret),
            'canConfigure'=>$r->user()->hasRole('superadmin'),'actorId'=>$r->user()->id,
            'pendingTransfers'=>DB::table('custody_transfers')->where('chain','bitcoin')->whereIn('status',CustodyService::ACTIVE)->count()]);
    }
    public function action(Request $r,string $action,BitcoinWalletManager $manager,BitcoinWalletRpc $rpc) {
        CustodyAccess::requireFresh($r);
        if($action!=='approve')abort_unless($r->user()->hasRole('superadmin'),403);
        try {
            if($action==='connect'){$v=$r->validate(['name'=>'required|string|max:64','create'=>'required|boolean']);$manager->connect($v['name'],$v['create'],$r->user()->id);}
            elseif(in_array($action,['backup','propose'],true)){$v=$r->validate(['wallet_id'=>'required|integer|exists:bitcoin_wallets,id']);$manager->$action($v['wallet_id'],$r->user()->id);}
            elseif($action==='approve'){$v=$r->validate(['revision'=>'required|integer|min:1']);$manager->approve($v['revision'],$r->user()->id);}
            elseif($action==='configure'){
                $v=$r->validate(['enabled'=>'required|boolean','auto_cold'=>'required|boolean','max_fee'=>['required','numeric','gt:0','regex:/^\d{1,2}(\.\d{1,8})?$/D'],
                    'max_fee_rate'=>'required|numeric|min:1|max:10000','confirmations'=>'required|integer|min:6|max:1000']);
                if($v['enabled']){ $w=$manager->active()??DB::table('bitcoin_wallets')->first();if(!$w)throw new \RuntimeException('BTC_WALLET_NOT_FOUND');$rpc->ready($w->name,true); }
                $manager->locked(function()use($v,$r){DB::transaction(function()use($v,$r){
                    DB::table('custody_networks')->where('chain','bitcoin')->update(['enabled'=>$v['enabled'],'max_fee'=>$v['max_fee'],'native_max_fee'=>$v['max_fee'],'confirmations'=>$v['confirmations'],'updated_by'=>$r->user()->id,'updated_at'=>now()]);
                    DB::table('bitcoin_wallet_control')->where('id',1)->update(['max_fee_rate'=>$v['max_fee_rate'],'auto_cold'=>$v['auto_cold'],'updated_at'=>now()]);
                    app(CustodyService::class)->audit('bitcoin.limits_updated',['chain'=>'bitcoin']+$v,null,$r->user()->id);
                });});
            }elseif($action==='migrate'){
                $v=$r->validate(['wallet_id'=>'required|integer|exists:bitcoin_wallets,id','amount'=>['required','numeric','gt:0','regex:/^\d{1,8}(\.\d{1,8})?$/D']]);
                $manager->locked(function()use($v,$manager){$from=$manager->wallet($v['wallet_id']);$to=$manager->active();if(!$to||$to->id===$from->id)throw new \RuntimeException('CUSTODY_SELF_TRANSFER');
                    $asset=CustodyNetwork::asset(DB::table('currencies')->where('symbol','BTC')->value('id'),DB::table('networks')->where('slug','btc')->value('id'));
                    app(CustodyService::class)->create($asset,['key'=>'btc-migration:'.\Illuminate\Support\Str::uuid(),'purpose'=>'migration','bitcoin_wallet_id'=>$from->id,'sender'=>$from->address,'destination'=>$to->address,'amount'=>$v['amount']]);
                });
            }else abort(404);
        }catch(ValidationException $e){throw $e;}catch(\RuntimeException $e){throw ValidationException::withMessages(['bitcoin'=>__($this->error($e))]);}
        return back()->with('success',__('Saved'));
    }
    private function error(\Throwable $e): string {return preg_match('/^(BTC|CUSTODY)_[A-Z_0-9]+$/D',$e->getMessage())?$e->getMessage():'BTC_OPERATION_REVIEW';}
}
