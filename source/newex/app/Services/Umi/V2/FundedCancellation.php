<?php
namespace App\Services\Umi\V2;

use App\Domain\Umi\V2\Decimal;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Only a never-submitted spot intake may be returned. Custody and payout states are reconciliation-only. */
final class FundedCancellation
{
    public function cancelIntake(int $intentId, int $actor, string $reason): array
    {
        if(mb_strlen(trim($reason))<10) throw new DomainException('请填写至少十个字的核对依据。');
        return DB::transaction(function()use($intentId,$actor,$reason){
            $settings=DB::table('umi_v2_live_settings')->where('id',1)->lockForUpdate()->first();
            $hint=DB::table('umi_v2_live_intents')->find($intentId);
            if(!$hint) throw new DomainException('充值订单不存在。');
            $member=DB::table('umi_v2_members')->where('id',$hint->member_id)->lockForUpdate()->first();
            $intent=DB::table('umi_v2_live_intents')->where('id',$intentId)->lockForUpdate()->first();
            if($intent->status==='cancelled') return ['intent_id'=>$intentId,'replayed'=>true];
            $lot=DB::table('umi_v2_live_burn_lots')->where('cycle_id',$intent->cycle_id)->where('purpose','activation')->lockForUpdate()->first();
            $cycle=DB::table('umi_v2_cycles')->where('id',$intent->cycle_id)->lockForUpdate()->first();
            if(!$settings?->pool_user_id || !$member || $intent->source!=='spot' || $intent->deposit_id || $intent->status!=='pending_burn'
                || !$cycle || $cycle->status!=='pending_burn' || !$lot || $lot->status!=='pending' || $lot->custody_transfer_id
                || $lot->burn_evidence_id || DB::table('umi_v2_live_burn_proofs')->where('lot_id',$lot->id)->exists()
                || DB::table('umi_v2_release_events')->where('cycle_id',$cycle->id)->exists()) {
                throw new DomainException('仅可退回尚未提交托管、未销毁且未产生收益的现货充值订单。');
            }
            app(\App\Services\Deposit\DepositRisk::class)->assertClear((int)$settings->pool_user_id);
            app(FundedWallet::class)->move((int)$settings->pool_user_id,(int)$member->user_id,(string)$intent->amount_umi,
                'intake-cancel:'.$intentId,'activation_cancel','intent:'.$intentId,(int)$member->id,'wallet','trade');
            $restored=Decimal::sub((string)$cycle->principal_umi,(string)$intent->amount_umi);
            if(Decimal::cmp($restored,'0')<0) throw new DomainException('充值本金不一致，请先对账。');
            $now=FundedTime::database(now());
            if(Decimal::cmp($restored,'0')>0) {
                $pending=DB::table('umi_v2_pending_principals')->where('member_id',$member->id)->lockForUpdate()->first();
                if(!$pending) throw new DomainException('原待激活本金凭证缺失，请先对账。');
                DB::table('umi_v2_pending_principals')->where('member_id',$member->id)->update(['amount_umi'=>Decimal::add((string)$pending->amount_umi,$restored),'updated_at'=>$now]);
            }
            DB::table('umi_v2_live_burn_lots')->where('id',$lot->id)->update(['status'=>'cancelled','updated_at'=>$now]);
            DB::table('umi_v2_cycles')->where('id',$cycle->id)->update(['status'=>'cancelled','updated_at'=>$now]);
            DB::table('umi_v2_live_intents')->where('id',$intentId)->update(['status'=>'cancelled','updated_at'=>$now]);
            $result=['intent_id'=>$intentId,'refunded_spot_umi'=>(string)$intent->amount_umi,'restored_principal_umi'=>$restored];
            DB::table('umi_v2_recovery_actions')->insert(['action'=>'cancel_intake','actor_id'=>$actor,'reason'=>trim($reason),
                'result_json'=>json_encode($result,JSON_THROW_ON_ERROR),'created_at'=>$now]);
            return $result+['replayed'=>false];
        },3);
    }
}
