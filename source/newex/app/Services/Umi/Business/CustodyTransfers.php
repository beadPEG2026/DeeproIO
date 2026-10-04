<?php
namespace App\Services\Umi\Business;
use Illuminate\Support\Facades\{DB,Cache};
/** Atomic bridge into the existing exchange funding wallet; providers stay unchanged. */
final class CustodyTransfers {
    public function __construct(private Engine $engine) {}
    public function claimToWallet(int $actor,string $pocket,string $amount,string $key):array {
        $a=$this->engine->owned($actor);abort_unless($a,403);
        if(!in_array($pocket,['linear','team','referral','treasure'],true))$this->engine->fail('pocket',__('请选择可领取的收益账户。'));
        $amount=Amount::valid($amount,true);
        return $this->engine->run($a->id,$actor,'claim_to_wallet',compact('pocket','amount'),$key,function($op)use($actor,$a,$pocket,$amount){
            $before=$this->engine->ledger->balance($a->id,'main');
            $this->engine->action($a->id,$actor,$pocket==='treasure'?'treasure_withdraw':'claim',['amount'=>$amount,'pocket'=>$pocket],'claim-'.$op);
            $net=Amount::sub($this->engine->ledger->balance($a->id,'main'),$before);
            // The native wallet supports 18 decimals. Dust remains in the UMI account.
            $native=bcadd($net,'0',18);
            if(bccomp($native,'0',18)<=0)$this->engine->fail('amount',__('扣费后金额低于资金账户最小精度。'));
            $this->transfer($actor,'UMI','out',$native,'wallet-'.$op);
        });
    }
    public function transfer(int $actor,string $asset,string $direction,string $amount,string $key,?string $pool=null):array {
        abort_unless($this->engine->rules->live(),503,__('生态账户划转尚未启用。'));
        $amount=Amount::valid($amount,true);
        if(!in_array($asset,['UMI','USDT'],true)||!in_array($direction,['in','out'],true)||bccomp($amount,bcadd($amount,'0',18),24)!==0)$this->engine->fail('amount',__('资产或金额精度无效，最多支持 18 位小数。'));
        if($pool){
            abort_unless(\App\Models\User\User::find($actor)?->hasRole('superadmin'),403);
            if(!in_array($pool,['distribution','inventory'],true)||$direction!=='in')$this->engine->fail('pool',__('资金池仅接受资金账户转入。'));
            $account=null;$bucket='system:'.$pool;
        }else{$account=$this->engine->owned($actor);abort_unless($account,422,__('请先开通 UMI 生态账户。'));$bucket=Ledger::bucket($account->id,'main');}
        return $this->engine->run($account?->id,$actor,'custody_transfer',compact('asset','direction','amount','pool'),$key,function($op)use($actor,$asset,$direction,$amount,$bucket,$account){
            $currency=DB::table('currencies')->where('symbol',$asset)->first();abort_unless($currency,422,__('该资产尚未登记。'));
            $wallet=DB::table('wallets')->where('user_id',$actor)->where('currency_id',$currency->id)->lockForUpdate()->first();abort_unless($wallet,422,__('请先在资金账户中开通该资产。'));
            if($direction==='in'){
                if(bccomp($wallet->balance_in_wallet,$amount,18)<0)$this->engine->fail('amount',__('资金账户可用余额不足。'));
                $delta=bcsub('0',$amount,18);$this->engine->ledger->move($op,'system:custody',$bucket,$amount,$asset,'从交易所资金账户转入');
            }else{$delta=bcadd($amount,'0',18);$this->engine->ledger->move($op,$bucket,'system:custody',$amount,$asset,'转回交易所资金账户');}
            DB::table('wallets')->where('id',$wallet->id)->update(['balance_in_wallet'=>bcadd($wallet->balance_in_wallet,$delta,18),'updated_at'=>now()]);
            DB::table('umi_custody_transfers')->insert(['operation_id'=>$op,'wallet_id'=>$wallet->id,'account_id'=>$account?->id,'asset'=>$asset,'wallet_delta'=>$delta,'destination'=>$bucket,'created_at'=>now()]);
            DB::afterCommit(fn()=>app(\App\Services\Performance\ReadModelCacheService::class)->invalidateWallets($actor));
        });
    }
}
