<?php
namespace App\Services\Deposit;

use App\Models\Deposit\DepositChannel;
use App\Models\Wallet\WalletAddress;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Fresh chain evidence is required; an operator cannot dismiss or manually credit a case. */
final class DepositRecovery
{
    public function retry(int $id): array {
        $row=DB::table('deposit_review_events')->find($id);abort_unless($row,404);
        if($row->status==='resolved')return ['status'=>'resolved'];
        if($row->chain==='bitcoin' && $row->reason==='DEPOSIT_POST_CREDIT_CHAIN_CONFLICT')return $this->bitcoin($row);
        if(!array_key_exists($row->chain,config('deposits.evm',[])))throw ValidationException::withMessages(['event'=>__('This case requires chain-specific evidence review.')]);
        if($row->reason==='DEPOSIT_POST_CREDIT_CHAIN_CONFLICT'){
            $receipt=DB::table('chain_deposit_receipts')->where('deposit_id',$row->deposit_id)->first();
            if(!$receipt)throw ValidationException::withMessages(['event'=>__('Original verified receipt is missing.')]);
            $channel=DepositChannel::findOrFail($receipt->channel_id);
            $original=json_decode($receipt->evidence,true)['chain'];
            $client=app(EvmDepositClient::class);
            $proofs=$client->verify($channel,$receipt->txn,$original['address'],$client->height($row->chain));
            $restored=collect($proofs)->first(fn($p)=>(string)$p['event_index']===(string)$receipt->event_index
                && (string)$p['raw_amount']===(string)$original['raw_amount']);
            if(!$restored)throw ValidationException::withMessages(['event'=>__('Original deposit has not regained verified confirmations.')]);
            // No money is credited again. Retain the original ledger and the restoring proof.
            DB::transaction(function () use ($receipt,$row,$restored,$id) {
                DB::table('chain_deposit_receipts')->where('id',$receipt->id)->lockForUpdate()->first();
                $resolved=DB::table('deposit_review_events')->where('id',$id)->where('status','open')->where('attempts',$row->attempts)->update(['status'=>'resolved','resolved_at'=>now(),'updated_at'=>now(),'evidence'=>json_encode(['conflict'=>json_decode($row->evidence,true),'restored'=>$restored,'reviewed_by'=>auth()->id()])]);
                if (!$resolved) throw ValidationException::withMessages(['event'=>__('Evidence changed during review. Recheck the latest chain state.')]);
                // Original receipt is immutable. The accepted canonical anchor follows a re-included transaction.
                DB::table('chain_deposit_receipts')->where('id',$receipt->id)->update(['recheck_anchor'=>json_encode($restored,JSON_THROW_ON_ERROR),'rechecked_at'=>now()]);
                app(\App\Services\Custody\CustodyService::class)->audit('deposit.risk_cleared',['event_id'=>$id,'basis'=>'verified_original_chain_event']);
            },3);
            return ['status'=>'resolved'];
        }
        $channel=DepositChannel::findOrFail($row->channel_id);$address=WalletAddress::findOrFail($row->wallet_address_id);
        $saved=json_decode($row->evidence,true);
        if(isset($saved['review_user_id'])&&(int)$saved['review_user_id']!==(int)$address->user_id)throw ValidationException::withMessages(['event'=>__('Address ownership changed; independent reconciliation is required.')]);
        $client=app(EvmDepositClient::class);$proofs=$client->verify($channel,$row->txn,$address->address,$client->height($row->chain));
        $proof=collect($proofs)->first(fn($p)=>(string)$p['event_index']===(string)$row->event_index);
        if(!$proof)throw ValidationException::withMessages(['event'=>__('Verified chain event is unavailable.')]);
        $result=app(DepositReview::class)->process($channel,$address,$proof);
        app(\App\Services\Custody\CustodyService::class)->audit('deposit.review_rechecked',['event_id'=>$id,'result'=>$result['result']??null]);
        return $result;
    }
    private function bitcoin(object $row): array {
        $d=DB::table('deposits')->find($row->deposit_id);
        $out=DB::table('bitcoin_deposit_outputs')->where('deposit_id',$row->deposit_id)->first();
        $wallet=$out?DB::table('bitcoin_wallets')->find($out->wallet_id):null;
        if(!$d||!$out||!$wallet)throw ValidationException::withMessages(['event'=>__('Original wallet evidence is missing.')]);
        $rpc=app(\App\Services\Wallet\BitcoinWalletRpc::class);$rpc->ready($wallet->name);
        $tx=$rpc->call('gettransaction',[$row->txn],$wallet->name);
        $minimum=max(6,(int)DB::table('currencies')->where('id',$d->currency_id)->value('min_deposit_confirmation'));
        $match=collect($tx['details']??[])->first(fn($p)=>($p['category']??'')==='receive' && (int)($p['vout']??-1)===(int)$out->vout && ($p['address']??null)===$d->address && bccomp(\App\Services\Wallet\BitcoinWalletRpc::amount($p['amount']??0),(string)$d->amount,8)===0);
        if(($tx['txid']??null)!==$row->txn || !$match || !empty($tx['walletconflicts']) || (int)($tx['confirmations']??0)<$minimum)throw ValidationException::withMessages(['event'=>__('Original deposit has not regained verified confirmations.')]);
        $resolved=DB::table('deposit_review_events')->where('id',$row->id)->where('status','open')->where('attempts',$row->attempts)->update(['status'=>'resolved','resolved_at'=>now(),'updated_at'=>now(),'evidence'=>json_encode(['conflict'=>json_decode($row->evidence,true),'restored'=>['txn'=>$row->txn,'confirmations'=>$tx['confirmations'],'vout'=>$out->vout],'reviewed_by'=>auth()->id()])]);
        if (!$resolved) throw ValidationException::withMessages(['event'=>__('Evidence changed during review. Recheck the latest chain state.')]);
        app(\App\Services\Custody\CustodyService::class)->audit('deposit.risk_cleared',['event_id'=>$row->id,'basis'=>'verified_bitcoin_output']);
        return ['status'=>'resolved'];
    }
}
