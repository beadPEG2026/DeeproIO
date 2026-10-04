<?php
namespace App\Services\Staking;

use App\Models\{Staking\Staking,Staking\StakingUser,Wallet\Wallet,User\User};
use App\Services\Operations\{StakingConfiguration,History};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Term positions snapshot either an operating reserve or a platform reward obligation. */
final class FundedTermProduct
{
    public function controls(Staking $p): array {return app(StakingConfiguration::class)->controls($p->id);}
    public function enabled(Staking $p): bool {return !empty($this->controls($p)['funded_term']);}
    private function fail(string $message): never {throw ValidationException::withMessages(['amount'=>__($message)]);}
    public function availability(Staking $p): array
    {
        $c=$this->controls($p);$platform=($c['reward_funding']??'reserved')==='platform';$uid=$platform?0:(int)($c['reward_user_id']??0);
        $user=$uid?User::find($uid):null;
        $balance=$user&&!$user->deleted&&!$user->deactivated&&!$user->is_xn?Wallet::where('user_id',$uid)->where('currency_id',$p->currency_id)->value('balance_in_trade'):null;
        $maxRate='0';foreach(explode(',',$p->rewards_percentage) as $rate)if(bccomp($rate,$maxRate,18)>0)$maxRate=$rate;
        $minReward=bcdiv(bcmul((string)$p->min_amount,(string)$maxRate,24),'100',8);
        $total=StakingUser::where('staking_id',$p->id)->active()->sum('amount');
        $ready=\Illuminate\Support\Facades\Schema::hasColumn('staking_users','meta') && $p->status==='active' && ($platform || ($balance!==null && bccomp((string)$balance,$minReward,18)>=0)) && bccomp($minReward,'0',8)>0
            && bccomp(bcadd((string)$total,(string)$p->min_amount,18),(string)($c['pool_limit']??'0'),18)<=0;
        return ['ready'=>$ready,'reward_funding'=>$platform?'platform':'reserved','funded_term'=>true,'early_redemption'=>'principal_only','reward_payment'=>'at_maturity','source_account'=>'spot'];
    }
    public function subscribe(Staking $p,User $user,string $amount,int $days): StakingUser
    {
        return DB::transaction(function()use($p,$user,$amount,$days){
            app(\App\Services\Deposit\DepositRisk::class)->assertClear($user->id);
            $p=Staking::whereKey($p->id)->lockForUpdate()->firstOrFail();$c=$this->controls($p);
            if (!\Illuminate\Support\Facades\Schema::hasColumn('staking_users','meta') || !$this->enabled($p) || $p->status!=='active' || $user->is_xn || $user->deleted || $user->deactivated) $this->fail('This product is not open for subscription.');
            if (!preg_match('/^\d+(?:\.\d{1,8})?$/D',$amount) || strlen($amount)>40 || bccomp($amount,'0',8)<=0) $this->fail('Invalid subscription amount or duration.');
            $rate=get_apy_by_day($days,$p->allowed_days,$p->rewards_percentage);
            if ($rate===null || bccomp($amount,(string)$p->min_amount,18)<0 || bccomp($amount,(string)$p->max_amount,18)>0) $this->fail('Invalid subscription amount or duration.');
            if (StakingUser::where('staking_id',$p->id)->where('user_id',$user->id)->active()->exists()) $this->fail('You already have an active position in this product.');
            $total=(string)StakingUser::where('staking_id',$p->id)->active()->sum('amount');
            if (bccomp(bcadd($total,$amount,18),(string)($c['pool_limit']??'0'),18)>0) $this->fail('Product subscription limit reached.');
            $platform=($c['reward_funding']??'reserved')==='platform';
            $uid=$platform?0:(int)($c['reward_user_id']??0);$operator=$uid?User::find($uid):null;
            if (!$platform && (!$operator || $operator->deleted || $operator->deactivated || $operator->is_xn || $uid===$user->id)) $this->fail('This product is not open for subscription.');
            $wallets=Wallet::whereIn('user_id',[$uid,$user->id])->where('currency_id',$p->currency_id)->orderBy('id')->lockForUpdate()->get()->keyBy('user_id');
            $source=$wallets[$user->id]??null;$fund=$platform?null:($wallets[$uid]??null);
            $reward=bcdiv(bcmul($amount,(string)$rate,24),'100',8);
            if ((!$platform && (!$fund || bccomp((string)$fund->balance_in_trade,$reward,18)<0)) || bccomp($reward,'0',8)<=0) $this->fail('This product is not open for subscription.');
            if (!$source || bccomp((string)$source->balance_in_trade,$amount,18)<0) $this->fail('Insufficient spot balance');
            $sourceBefore=(string)$source->balance_in_trade;$fundBefore=$fund?(string)$fund->balance_in_trade:null;
            $source->balance_in_trade=bcsub($sourceBefore,$amount,18);$source->save();
            if ($fund) {$fund->balance_in_trade=bcsub($fundBefore,$reward,18);$fund->save();}
            $start=now()->addDay()->startOfDay();
            $s=new StakingUser();$s->forceFill(['user_id'=>$user->id,'staking_id'=>$p->id,'currency_id'=>$p->currency_id,'amount'=>$amount,'days'=>$days,'apy'=>$rate,'reward'=>'0','status'=>'active','value_date'=>$start,'redemption_date'=>$start->copy()->addDays($days),
                'meta'=>['funded_term'=>1,'reward_funding'=>$platform?'platform':'reserved','reward_wallet_id'=>$fund?->id,'term_reward'=>$reward,'reserved_reward'=>$platform?'0':$reward,'platform_reward_due'=>$platform?$reward:'0','source_balance_field'=>'balance_in_trade','source_account_type'=>'real','early_redemption'=>'principal_only']]);$s->save();
            History::append('funded_staking',$s->id,'subscribe',['principal'=>$amount,'currency_id'=>$p->currency_id,'reward_funding'=>$platform?'platform':'reserved','term_reward'=>$reward,'reserved_reward'=>$platform?'0':$reward,'platform_reward_due'=>$platform?$reward:'0','source_wallet'=>$source->id,'source_before'=>$sourceBefore,'source_after'=>(string)$source->balance_in_trade,'reward_wallet'=>$fund?->id,'reward_before'=>$fundBefore,'reward_after'=>$fund?(string)$fund->balance_in_trade:null],$user->id,$platform?'Spot principal booked; reward obligation payable by Deepro at maturity':'Principal and full term reward reserved');
            return $s;
        },3);
    }
    public function accrue(StakingUser $s): void
    {
        $meta=$s->meta??[];if (empty($meta['funded_term']) || $s->status!=='active') return;
        $elapsed=max(0,min((int)$s->days,(int)$s->value_date->diffInDays(now()->startOfDay(),false)));
        $reward=bcdiv(bcmul($meta['term_reward']??$meta['reserved_reward'],(string)$elapsed,18),(string)$s->days,8);
        if (bccomp($reward,(string)$s->reward,18)<=0) return;
        DB::table('staking_reward_receipts')->insert(['stake_id'=>$s->id,'reward_date'=>now()->toDateString(),'amount'=>bcsub($reward,(string)$s->reward,18),'balance_before'=>(string)$s->reward,'balance_after'=>$reward,'rule'=>'funded_elapsed_term_days','created_at'=>now()]);
        $s->reward=$reward;$s->save();
    }
    public function settle(int $id,?int $owner=null): bool
    {
        return DB::transaction(function()use($id,$owner){
            $s=StakingUser::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($owner!==null && (int)$s->user_id!==$owner) abort(403);
            if ($s->status!=='active' || empty($s->meta['funded_term'])) return false;
            $matured=now()->gte($s->redemption_date);if (!$matured && $owner===null) {$this->accrue($s);return false;}
            $this->accrue($s);$meta=$s->meta;$platform=($meta['reward_funding']??'reserved')==='platform';
            $reserved=$meta['reserved_reward'];$termReward=$meta['term_reward']??$reserved;$paid=$matured?$termReward:'0';
            $ids=Wallet::where('user_id',$s->user_id)->where('currency_id',$s->currency_id)->pluck('id');if (!$platform) $ids->push($meta['reward_wallet_id']);
            $wallets=Wallet::whereIn('id',$ids)->orderBy('id')->lockForUpdate()->get();$source=$wallets->firstWhere('user_id',$s->user_id);$fund=$platform?null:$wallets->firstWhere('id',$meta['reward_wallet_id']);
            if (!$source || (!$platform && !$fund)) throw new \RuntimeException('funded_staking_wallet_missing');
            $sourceBefore=(string)$source->balance_in_trade;$fundBefore=$fund?(string)$fund->balance_in_trade:null;
            $source->balance_in_trade=bcadd($sourceBefore,bcadd((string)$s->amount,$paid,18),18);$source->save();
            $returned=$platform?'0':bcsub($reserved,$paid,18);
            if ($fund) {$fund->balance_in_trade=bcadd($fundBefore,$returned,18);$fund->save();}
            $meta['paid_reward']=$paid;$meta['returned_reserve']=$returned;$meta['platform_reward_due']='0';$meta['platform_reward_expense']=$platform?$paid:'0';$meta['settled_at']=now()->toIso8601String();
            $s->reward=$paid;$s->meta=$meta;$s->status=$matured?'completed':'redeemed';$s->save();
            History::append('funded_staking',$s->id,$matured?'matured':'early_redeem',['principal'=>(string)$s->amount,'currency_id'=>$s->currency_id,'reward_funding'=>$platform?'platform':'reserved','platform_reward_expense'=>$meta['platform_reward_expense'],'cancelled_reward'=>$matured?'0':$termReward,'paid_reward'=>$paid,'returned_reserve'=>$meta['returned_reserve'],'source_wallet'=>$source->id,'source_before'=>$sourceBefore,'source_after'=>(string)$source->balance_in_trade,'reward_wallet'=>$fund?->id,'reward_before'=>$fundBefore,'reward_after'=>$fund?(string)$fund->balance_in_trade:null],$owner,$platform?'Platform reward obligation settled to user spot balance':'Funded term settlement');
            return true;
        },3);
    }
}
