<?php
namespace App\Services\Wallet;
use Illuminate\Support\Facades\{DB,Http,Cache};
final class WalletReadiness
{
    public function snapshot(): array
    {
        $data=Cache::get('deepro.wallet-readiness');
        if(!is_array($data))return ['checked_at'=>null,'stale'=>true,'chains'=>[],'note'=>__('等待定时钱包检查；尚无可用样本。')];
        try{$data['stale']=\Carbon\Carbon::parse($data['checked_at'])->lt(now()->subMinutes(3));}catch(\Throwable $e){$data['stale']=true;}
        return $data;
    }
    public function inspect(): array
    {
        $result=[];
        foreach (['ethereum'=>['ethereum','eth','erc20'],'bsc'=>['bnb','bnb','bep20'],'tron'=>['tron','trx','trc20'],'polygon'=>['polygon','matic','matic20'],'xlayer'=>['xlayer','xlayer','xlayer20'],'solana'=>['solana','sol','solspl'],'ton'=>['ton','ton']] as $chain=>$map) {
            $setting=array_shift($map);$bridge=null;
            try { $r=Http::connectTimeout(1)->timeout(4)->get(config('app.'.$chain.'_bridge').'/health');$body=$r->json();if(is_array($body) && ($body['service']??'')==='deepro-wallet-bridge')$bridge=array_intersect_key($body,array_flip(['chain','port','rpc','database','checked_at','broadcast_enabled','height','rpc_error','database_error','configuration_issues'])); } catch (\Throwable $e) {}
            $key=(string)setting($setting.'.private_key');$address=(string)setting($setting.'.wallet');
            $assets=DB::table('currency_networks')->join('currencies','currencies.id','=','currency_networks.currency_id')->join('networks','networks.id','=','currency_networks.network_id')->whereIn('networks.slug',$map)->whereNull('currencies.deleted_at')->where('currencies.status',true)->select('currencies.symbol','currencies.min_deposit_confirmation','currencies.deposit_status','currencies.withdraw_status','currency_networks.network_id','currencies.id')->get();
            $errors=[];if(!$bridge)$errors[]=__('桥接协议不可用');elseif(!$bridge['rpc']||!$bridge['database'])$errors[]=__('节点或数据库未就绪');
            if(in_array('TRONGRID_KEY_MISSING_OR_INVALID', $bridge['configuration_issues']??[],true))$errors[]=__('TronGrid Key 缺失或格式无效，当前仅使用公共查询额度');
            if(!$key || !$address)$errors[]=__('热钱包配置缺失');
            foreach($assets as $asset){if($asset->deposit_status && $asset->min_deposit_confirmation<1)$errors[]=$asset->symbol.__(' 确认数为 0');if($asset->withdraw_status && ($issue=app(WithdrawalNetworkPolicy::class)->error($asset->id,$asset->network_id)))$errors[]=$asset->symbol.': '.$issue;}
            $result[]=['chain'=>$chain,'service'=>$bridge,'wallet_address_configured'=>$address!=='','wallet_key_configured'=>$key!=='','asset_count'=>$assets->count(),'issues'=>array_values(array_unique($errors)), 'status'=>$errors?'needs_configuration':'awaiting_chain_acceptance'];
        }
        foreach (['bitcoin','ripple'] as $chain) $result[]=$this->directChain($chain);
        return ['checked_at'=>now()->toIso8601String(),'chains'=>$result,'note'=>__('服务连通与配置检查不代表链上充提到账验收已通过。')];
    }
    private function directChain(string $chain): array
    {
        $isBitcoin=$chain==='bitcoin';
        $slugs=$isBitcoin?['btc','brc20']:['xrp'];
        $rpc=false;$height=null;$synced=null;$walletLoaded=false;$canSign=false;$bitcoin=[];
        try {
            if ($isBitcoin) {
                $bitcoin=app(BitcoinRpcHealth::class)->inspect();
                $rpc=$bitcoin['rpc'];$height=$bitcoin['height'];$synced=$bitcoin['chain_synced'];
                $walletLoaded=$bitcoin['wallet_loaded'];$canSign=$bitcoin['wallet_can_sign'];
            } else {
                $response=Http::connectTimeout(1)->timeout(4)->post(config('ripple.rpc_endpoint'),['method'=>'server_info','params'=>[(object)[]]]);
                $body=$response->json();$info=$body['result']['info']??[];
                $rpc=$response->successful() && ($body['result']['status']??null)==='success' && isset($info['validated_ledger']['seq']) && (!isset($info['server_state']) || in_array($info['server_state'], ['full','validating','proposing'],true));
                $height=$info['validated_ledger']['seq']??null;
            }
        } catch (\Throwable $e) {}
        $assets=DB::table('currency_networks')->join('networks','networks.id','=','currency_networks.network_id')->join('currencies','currencies.id','=','currency_networks.currency_id')->whereIn('networks.slug',$slugs)->whereNull('currencies.deleted_at')->where('currencies.status',true)->select('currencies.*')->get();
        $address=(string)setting($chain.'.wallet');$key=(string)setting($chain.'.private_key');
        $issues=[];
        if (!$rpc) $issues[]=__('RPC 尚未通过连通与同步检查');
        if ($isBitcoin) {
            if (!$synced || !($bitcoin['wallet_chain_synced']??false)) $issues[]=__('Bitcoin Core 主网区块尚未同步完成');
            if (!$walletLoaded) $issues[]=__('Bitcoin Core 钱包未加载或无法读取');
            elseif (!$canSign) $issues[]=__('Bitcoin Core 钱包只读或处于锁定状态');
        }
        elseif (!$address || !$key) $issues[]=__('热钱包地址或签名配置缺失');
        foreach($assets as $asset) if ($asset->deposit_status && $asset->min_deposit_confirmation<1) $issues[]=$asset->symbol.__(' 确认数为 0');
        return ['chain'=>$chain,'service'=>array_merge($isBitcoin?$bitcoin:[],['direct'=>true,'rpc'=>$rpc,'database'=>true,'checked_at'=>now()->toIso8601String(),'height'=>$height,'chain_synced'=>$synced,'wallet_loaded'=>$isBitcoin?$walletLoaded:null,'broadcast_enabled'=>false]), 'wallet_address_configured'=>$address!=='','wallet_key_configured'=>$isBitcoin?$canSign:$key!=='','wallet_managed_by_node'=>$isBitcoin,'asset_count'=>$assets->count(),'issues'=>array_values(array_unique($issues)),'status'=>$issues?'needs_configuration':'awaiting_chain_acceptance'];
    }

}
