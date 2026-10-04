<?php
namespace App\Services\Deposit;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** A risk hold preserves every original ledger row; it never invents a refund. */
final class DepositRisk
{
    public function record(object $deposit, string $chain, array $evidence): void {
        DB::transaction(function () use ($deposit, $chain, $evidence) {
            $key = 'reorg:'.$deposit->id;
            if (DB::table('deposit_review_events')->insertOrIgnore(['event_key'=>$key,'deposit_id'=>$deposit->id,'chain'=>$chain,'txn'=>$deposit->txn,'event_index'=>'reorg','reason'=>'DEPOSIT_POST_CREDIT_CHAIN_CONFLICT','status'=>'open','evidence'=>json_encode($evidence,JSON_THROW_ON_ERROR),'created_at'=>now(),'updated_at'=>now()])) return;
            $row = DB::table('deposit_review_events')->where('event_key',$key)->lockForUpdate()->first();
            $before = json_decode($row->evidence,true) ?: [];
            if ($row->status === 'resolved') {
                app(\App\Services\Custody\CustodyService::class)->audit('deposit.risk_reopened',['event_id'=>$row->id,'previous_resolution'=>$before]);
                $next = $evidence;
            } else {
                $next = ['first_observed'=>$before['first_observed']??$before,'latest_observed'=>$evidence];
            }
            DB::table('deposit_review_events')->where('id',$row->id)->update(['status'=>'open','resolved_at'=>null,'attempts'=>$row->attempts+1,'evidence'=>json_encode($next,JSON_THROW_ON_ERROR),'updated_at'=>now()]);
        }, 3);
    }
    public function assertClear(int $userId): void {
        if(DB::table('deposit_review_events as r')->join('deposits as d','d.id','=','r.deposit_id')->where('d.user_id',$userId)->where('r.reason','DEPOSIT_POST_CREDIT_CHAIN_CONFLICT')->where('r.status','open')->exists())
            throw ValidationException::withMessages(['amount'=>__('Deposit confirmation is under review. Please contact support before moving funds.')]);
    }
}
