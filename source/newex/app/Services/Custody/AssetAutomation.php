<?php
namespace App\Services\Custody;

use App\Models\Currency\Currency;
use App\Models\Deposit\DepositChannel;
use App\Services\Deposit\DepositChannelPolicy;
use App\Services\Wallet\WithdrawalNetworkPolicy;
use Illuminate\Support\Facades\DB;

/** One inventory for onboarding; no signing, crediting or implicit activation. */
final class AssetAutomation
{
    public function draft(Currency $currency): int
    {
        if($currency->type !== 'coin') return 0;
        $count=0;
        foreach($currency->networks()->get() as $network){
            if (\App\Services\Market\AssetProfile::networkError($currency,$network->slug)) continue;
            $map=DepositChannelPolicy::NETWORKS[$network->id]??null;
            if(!$map)continue;
            [$chain,$kind]=$map;
            $field=CustodyNetwork::MAP[$network->slug][1]??null;
            $suffix=['erc20'=>'_erc','bep20'=>'_bep','trc20'=>'_trc','matic20'=>'_matic','xlayer20'=>'_xlayer'][$network->slug]??'';
            $row=DepositChannel::firstOrCreate(['currency_id'=>$currency->id,'network_id'=>$network->id],[
                'chain'=>$chain,'kind'=>$kind,'contract'=>$field?trim((string)$currency->{$field}):null,
                // Draft precision is a placeholder until the node verifies the actual contract.
                'decimals'=>$kind==='native'?($chain==='solana'?9:18):($currency->symbol==='USDT'&&in_array($network->slug,['erc20','trc20','matic20','xlayer20'],true)?6:18),
                'confirmations'=>$chain==='solana'?1:max((int)$currency->min_deposit_confirmation,['ethereum'=>12,'bsc'=>15,'polygon'=>128,'xlayer'=>64,'tron'=>20][$chain]),
                'minimum'=>$currency->min_deposit??0,'fee_fixed'=>$currency->{'deposit_fee'.$suffix.'_fixed'}??0,
                'fee_percent'=>$currency->{'deposit_fee'.$suffix}??0,'state'=>'draft']);
            if($row->wasRecentlyCreated){
                $count++;$detail=['source'=>'asset-network-template','state'=>'draft','channel_id'=>$row->id];
                if(auth()->id())DB::table('deposit_channel_audits')->insert(['channel_id'=>$row->id,'actor_id'=>auth()->id(),'before'=>null,'after'=>json_encode($detail),'created_at'=>now()]);
                else DB::table('custody_audits')->insert(['actor_id'=>null,'action'=>'channel.draft_created','detail'=>json_encode($detail),'created_at'=>now()]);
            }
        }
        return $count;
    }

    public function inventory(): array
    {
        $rows=[];$policies=DB::table('custody_networks')->get()->keyBy('chain');
        foreach(Currency::where('type','coin')->with('networks')->orderBy('symbol')->get() as $currency){
            if($currency->networks->isEmpty())$rows[]=['currency_id'=>$currency->id,'symbol'=>$currency->symbol,'network_id'=>null,'network_name'=>'—','chain'=>null,'deposit_issue'=>'Asset is not linked to this network','channel_id'=>null,'state'=>null,'collection_issues'=>['Asset is not linked to this network'],'warnings'=>[],'auto_sweep'=>false,'scope'=>null,'withdrawal_issue'=>'This network is not supported for the selected currency'];
            foreach($currency->networks as $network){
                if($network->slug==='internal')continue;
                $map=CustodyNetwork::MAP[$network->slug]??null;$chain=$map[0]??null;$policy=$policies[$chain]??null;
                $channel=DepositChannel::where('currency_id',$currency->id)->where('network_id',$network->id)->first();
                $issues=[];$warnings=[];
                $receiptSupported=isset(DepositChannelPolicy::NETWORKS[$network->id])||($network->slug==='trx'&&$currency->symbol==='TRX');
                if($network->slug==='btc' && $currency->symbol==='BTC') {
                    // Core holds its own keys and UTXOs; it does not use a token bridge.
                    $issues[]='Bitcoin Core wallet inventory; separate from token sweeps.';
                }
                elseif(!$map)$issues[]='Collection adapter is not integrated';
                else{
                    try{CustodyNetwork::asset($currency->id,$network->id);}catch(\Throwable $e){$issues[]='Asset contract or network mapping needs correction';}
                    if(!$receiptSupported)$issues[]='Verified deposit receipt integration is missing';
                    if(!$policy?->enabled)$issues[]='Custody network is disabled';
                    if(!$policy?->auto_sweep)$issues[]='Automatic sweeps are disabled';
                    if(!trim((string)setting($chain.'.wallet'))||!trim((string)setting($chain.'.private_key')))$issues[]='Hot wallet or signing key is missing';
                    if(!CustodyNetwork::bridge($chain))$issues[]='Custody bridge is not configured';
                    if($map[1]&&bccomp((string)($policy->daily_gas_limit??0),'0',18)<=0)$warnings[]='Automatic fee funding is disabled; deposit addresses need native fees or delegated resources';
                    if($channel?->isPilot()&&($policy->auto_sweep_scope??'new_live')!=='all_verified')$issues[]='Current scope excludes pilot deposits and pending tasks';
                }
                $rows[]=['currency_id'=>$currency->id,'symbol'=>$currency->symbol,'network_id'=>$network->id,'network_name'=>$network->name,'chain'=>$chain,
                    'channel_id'=>$channel?->id,'state'=>$channel?->state,'deposit_issue'=>app(DepositChannelPolicy::class)->error($currency->id,$network->id),
                    'collection_issues'=>$issues,'warnings'=>$warnings,'auto_sweep'=>(bool)($policy->auto_sweep??false),'scope'=>$policy->auto_sweep_scope??null,
                    'withdrawal_issue'=>app(WithdrawalNetworkPolicy::class)->error($currency->id,$network->id)];
            }
        }
        return $rows;
    }
}
