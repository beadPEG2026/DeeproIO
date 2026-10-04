<?php
namespace App\Services\Deposit;

use App\Models\Deposit\DepositChannel;
use App\Models\Wallet\WalletAddress;
use Illuminate\Support\Facades\DB;

/** Persist verified-but-uncreditable events without skipping undiscovered chain data. */
final class DepositReview
{
    public const ISOLATABLE = ['DEPOSIT_ACCOUNT_UNAVAILABLE','DEPOSIT_ADDRESS_OWNERSHIP_CONFLICT','DEPOSIT_PREDATES_ADDRESS','DEPOSIT_WALLET_ALLOCATION_REQUIRES_REVIEW','DEPOSIT_RECEIPT_CONFLICT','DEPOSIT_LEGACY_RECONCILIATION_REQUIRED'];
    public function process(DepositChannel $channel, WalletAddress $address, array $proof): array {
        try {
            $result = app(VerifiedChainDeposit::class)->process($channel, $address, $proof);
            DB::table('deposit_review_events')->where('event_key',$this->key($proof))->where('status','open')->update(['status'=>'resolved','deposit_id'=>$result['deposit_id']??null,'resolved_at'=>now(),'updated_at'=>now()]);
            return $result;
        } catch (\RuntimeException $e) {
            if (!in_array($e->getMessage(),self::ISOLATABLE,true)) throw $e;
            $key = $this->key($proof);
            DB::table('deposit_review_events')->insertOrIgnore(['event_key'=>$key,'channel_id'=>$channel->id,'wallet_address_id'=>$address->id,'chain'=>$channel->chain,'txn'=>$proof['txn'],'event_index'=>(string)$proof['event_index'],'reason'=>$e->getMessage(),'status'=>'open','evidence'=>json_encode($proof+['review_user_id'=>(int)$address->user_id],JSON_THROW_ON_ERROR),'attempts'=>0,'created_at'=>now(),'updated_at'=>now()]);
            DB::table('deposit_review_events')->where('event_key',$key)->where('status','open')->update(['reason'=>$e->getMessage(),'attempts'=>DB::raw('attempts+1'),'updated_at'=>now()]);
            return ['result'=>'review_required'];
        }
    }
    private function key(array $proof): string { return implode(':',[$proof['chain'],$proof['txn'],(string)$proof['event_index']]); }
}
