<?php
namespace App\Services\Operations;

use App\Models\Currency\Currency;
use App\Models\Deposit\DepositChannel;
use App\Services\Custody\CustodyNetwork;
use App\Services\Deposit\DepositChannelPolicy;
use App\Services\Wallet\{WithdrawalNetworkPolicy,NetworkAvailability,NetworkRules};
use Illuminate\Support\Facades\{DB,Cache};

/** Configuration/read-model only. No RPC calls or address generation during inspection. */
final class Assets
{
    public function index(array $filters): array {
        $q=Currency::where('type','coin')->with('networks')->orderBy('symbol');
        if(!empty($filters['asset']))$q->where('id',$filters['asset']);
        $items=$q->paginate(15)->withQueryString();
        $items->getCollection()->transform(function($c){
            return ['id'=>$c->id,'symbol'=>$c->symbol,'name'=>$c->name,'enabled'=>(bool)$c->status,
                'bsc_only'=>\App\Services\Market\AssetProfile::bscOnly($c),'networks'=>$c->networks->map(fn($n)=>$this->row($c,$n))->values(),
                'links'=>$this->links($c->id,null),'checked_at'=>now()->toISOString()];
        });
        return ['assets'=>$items,'options'=>Currency::where('type','coin')->orderBy('symbol')->get(['id','symbol']),'filters'=>$filters,'checked_at'=>now()->toISOString()];
    }
    public function row($currency,$network): array {
        $channel=DepositChannel::where('currency_id',$currency->id)->where('network_id',$network->id)->first();
        $map=CustodyNetwork::MAP[$network->slug]??null;$chain=$map[0]??null;
        $policy=$chain?DB::table('custody_networks')->where('chain',$chain)->first():null;
        $deposit=app(DepositChannelPolicy::class)->error($currency->id,$network->id,true,null);
        $withdraw=app(WithdrawalNetworkPolicy::class)->error($currency->id,$network->id);
        $state=$channel?DB::table('chain_deposit_scan_states')->where('chain',$channel->chain)->where('scope',$channel->scanScope())->first():null;
        $scanner=['kind'=>$channel?'channel':'legacy','last_success_at'=>$state->realtime_last_success_at??$state->last_success_at??null,
            'cursor'=>$state->realtime_through??$state->scanned_through??null,'backfill_cursor'=>$state->scanned_through??null,'has_error'=>$state?(bool)($state->realtime_last_error??$state->last_error??false):null];
        $btc=$network->slug==='btc' && $currency->symbol==='BTC';
        if($btc) {$b=Cache::get(\App\Services\Deposit\BitcoinWalletScanner::STATE,[]);$scanner=['kind'=>'Bitcoin Core','last_success_at'=>$b['checked_at']??null,'cursor'=>$b['height']??null,'has_error'=>null];}
        elseif($network->slug==='trx') {$s=DB::table('tron_deposit_scan_states')->orderByDesc('last_success_at')->first(['last_success_at','last_error']);$scanner=['kind'=>'TRX','last_success_at'=>$s->last_success_at??null,'cursor'=>null,'has_error'=>!empty($s->last_error)];}
        $collection=[];
        if($btc)$collection[]='Bitcoin Core wallet inventory; separate from token sweeps.';
        elseif(!$map)$collection[]='Collection adapter is not integrated';
        else {
            try{CustodyNetwork::asset($currency->id,$network->id);}catch(\Throwable $e){$collection[]='Asset contract or network mapping needs correction';}
            if(!$policy?->enabled)$collection[]='Custody network is disabled';
            if(!$policy?->auto_sweep)$collection[]='Automatic sweeps are disabled';
            if(!trim((string)setting($chain.'.wallet')) || !trim((string)setting($chain.'.private_key')))$collection[]='Hot wallet or signing key is missing';
            if(!CustodyNetwork::bridge($chain))$collection[]='Custody bridge is not configured';
            if($channel?->isPilot() && ($policy->auto_sweep_scope??null)!=='all_verified')$collection[]='Current scope excludes pilot deposits and pending tasks';
        }
        $cold=DB::table('cold_storage')->where('currency_id',$currency->id)->where('network_id',$network->id)->get(['id','status','approved_at']);
        [$code,$message]=NetworkAvailability::reason($deposit);
        return ['id'=>$network->id,'name'=>$network->name,'chain'=>$channel?->chain??($btc?'bitcoin':$chain),'kind'=>$channel?->kind??($map && $map[1]?'token':'native'),
            'contract'=>$channel?->contract??($map && $map[1]?$currency->{$map[1]}:null),'decimals'=>$channel?->decimals,
            'channel_id'=>$channel?->id,'channel_state'=>$channel?->state,'config_digest'=>$channel?->config_digest,'updated_at'=>$channel?->updated_at?->toISOString(),
            'deposit_issue'=>$deposit,'withdrawal_issue'=>$withdraw,'public_deposit'=>['available'=>$deposit===null,'code'=>$code,'message'=>$message],
            'public_withdrawal'=>['available'=>$withdraw===null,'message'=>NetworkAvailability::reason($withdraw)[1]],
            'deposit_rules'=>NetworkRules::deposit($currency,$network->id,$channel),'withdrawal_rules'=>NetworkRules::withdrawal($currency,$network->id),
            'scanner'=>$scanner,'collection_issues'=>$collection,'auto_sweep'=>(bool)$policy?->auto_sweep,'fee_funding_enabled'=>isset($policy->daily_gas_limit)&&bccomp((string)$policy->daily_gas_limit,'0',18)>0,
            'cold_rules'=>$cold,'links'=>$this->links($currency->id,$network->id,$channel?->id,$chain)];
    }
    private function links(int $currency,?int $network,?int $channel=null,?string $chain=null): array {
        $out=[];
        foreach([
            ['admin.currencies.edit',['currency'=>$currency],'Asset'],
            ['admin.networks.edit',['network'=>$network],'Network'],
            ['admin.deposit-channels',['asset'=>$currency,'channel'=>$channel],'Deposit channels'],
            ['admin.custody',[],'Wallet custody'],
            ['admin.operations.trace',['type'=>'channel','reference'=>$channel],'Trace history'],
        ] as [$route,$args,$label]){
            if(($route==='admin.networks.edit' && !$network) || ($route==='admin.operations.trace' && !$channel) || !Access::route($route))continue;
            $out[]=['label'=>$label,'url'=>route($route,array_filter($args,fn($v)=>$v!==null)).($route==='admin.custody'&&$chain?'#network-'.$chain:'')];
        }
        return $out;
    }
}
