<?php
namespace App\Services\Option;
use App\Models\Option\Option;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
final class OptionSettlementReview {
    public function enqueue(int $id, \Carbon\CarbonInterface $expiry): void {
        DB::transaction(function()use($id,$expiry){
            $option=Option::whereKey($id)->lockForUpdate()->first();
            if(!$option || !in_array($option->status,['active','review_required'],true))return;
            DB::table('option_settlement_reviews')->insertOrIgnore(['option_id'=>$id,'status'=>'pending','reason'=>'verified_expiry_quote_unavailable','expires_at'=>$expiry,'evidence'=>json_encode(['opening_source'=>$option->opening_source,'expiry'=>$expiry->toIso8601String(),'observed_at'=>now()->toIso8601String()]),'created_at'=>now(),'updated_at'=>now()]);
            $option->status='review_required';$option->settlement_source='review_required';$option->save();
        },3);
    }
    public function requestRefund(int $id, int $actor, string $note): void {
        $this->note($note);
        DB::transaction(function()use($id,$actor,$note){
            $option=Option::whereKey($id)->lockForUpdate()->firstOrFail();
            $review=DB::table('option_settlement_reviews')->where('option_id',$id)->lockForUpdate()->first();
            if(!$review || $option->status!=='review_required' || $review->status!=='pending')throw ValidationException::withMessages(['option'=>__('This option is not awaiting settlement review.')]);
            DB::table('option_settlement_reviews')->where('id',$review->id)->update(['status'=>'refund_requested','requested_by'=>$actor,'resolution_note'=>$note,'updated_at'=>now()]);
        },3);
    }
    public function approveRefund(int $id, int $actor, string $note): void {
        $this->note($note);
        DB::transaction(function()use($id,$actor,$note){
            $option=Option::whereKey($id)->lockForUpdate()->firstOrFail();
            $query=DB::table('option_settlement_reviews')->where('option_id',$id);$review=(clone$query)->lockForUpdate()->first();
            if($review?->status==='refunded')return;
            if(!$review || $review->status!=='refund_requested' || $option->status!=='review_required' || (int)$review->requested_by===$actor)throw ValidationException::withMessages(['option'=>__('A different operator must approve the pending refund.')]);
            $netFee=bcsub((string)($option->fee??'0'),(string)($option->fee_refund_amount??'0'),18);
            if(bccomp($netFee,'0',18)<0)throw ValidationException::withMessages(['option'=>__('Fee reconciliation is required before refund.')]);
            $refund=bcadd((string)$option->amount,$netFee,18);
            app(OptionFunds::class)->credit($option,$refund);
            $option->status='closed';$option->pnl='0';$option->settlement_source='review_refund:'.$review->id;$option->save();
            $evidence=json_decode($review->evidence?:'{}',true);$evidence['refund_amount']=$refund;$evidence['approval_note']=$note;$evidence['fee_referral_treatment']='original_rewards_preserved';
            $query->update(['status'=>'refunded','resolved_by'=>$actor,'resolved_at'=>now(),'evidence'=>json_encode($evidence),'updated_at'=>now()]);
        },3);
    }
    private function note(string $note): void { if(mb_strlen(trim($note))<8 || mb_strlen($note)>1000)throw ValidationException::withMessages(['note'=>__('Provide a review reason between 8 and 1000 characters.')]); }
}
