<?php
namespace App\Services\Deposit;

use App\Models\{Currency\Currency,Deposit\Deposit,Wallet\Wallet,Wallet\WalletAddress};
use App\Services\Currency\CurrencyService;
use App\Services\Wallet\BitcoinWalletRpc;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BitcoinManagedDeposits {
    public function ingest(object $wallet,string $txn): bool {
        if(!preg_match('/^[a-f0-9]{64}$/D',$txn))throw new RuntimeException('BTC_INVALID_TRANSACTION');
        $rpc=app(BitcoinWalletRpc::class);$rpc->ready($wallet->name);
        try{$tx=$rpc->call('gettransaction',[$txn],$wallet->name);}
        catch(RuntimeException $e){if($e->getMessage()==='BTC_RPC_ERROR_5')return false;throw $e;}
        if(($tx['txid']??null)!==$txn || !is_array($tx['details']??null))throw new RuntimeException('BTC_INVALID_TRANSACTION');
        if((int)($tx['confirmations']??0)<0 || !empty($tx['walletconflicts'])){
            foreach(DB::table('deposits')->where('txn',$txn)->where('status',DEPOSIT_CONFIRMED)->whereIn('network_id',DB::table('networks')->where('slug','btc')->select('id'))->get() as $deposit)
                app(DepositRisk::class)->record($deposit,'bitcoin',['confirmations'=>$tx['confirmations']??0,'wallet_conflicts'=>$tx['walletconflicts']??[]]);
            return false;
        }
        $network=DB::table('networks')->where('slug','btc')->first();$currency=Currency::where('symbol','BTC')->firstOrFail();
        if(!$network || !DB::table('currency_networks')->where('currency_id',$currency->id)->where('network_id',$network->id)->exists())return false;
        if(!$network->status || !$network->deposit_status || !$currency->status || !$currency->deposit_status)return false;
        $disabled=is_array($currency->disabled_deposit_networks)?$currency->disabled_deposit_networks:json_decode($currency->disabled_deposit_networks?:'[]',true);
        if(!is_array($disabled))$disabled=preg_split('/[,;\s]+/',trim($currency->disabled_deposit_networks),-1,PREG_SPLIT_NO_EMPTY);
        if(in_array((int)$network->id,array_map('intval',$disabled),true))return false;
        // Platform-to-platform cold/migration outputs must never create customer credits.
        if(DB::table('custody_transfers')->where('chain','bitcoin')->where('txn',$txn)->whereIn('purpose',['cold','migration'])->exists())return true;
        foreach($tx['details'] as $output){
            if(($output['category']??'')!=='receive' || !isset($output['vout'],$output['address']) || ($output['amount']??0)<=0)continue;
            $amount=BitcoinWalletRpc::amount($output['amount']);$vout=(int)$output['vout'];
            DB::transaction(function()use($wallet,$txn,$output,$amount,$vout,$network,$currency,$tx){
                DB::select('select pg_advisory_xact_lock(hashtext(?))',['btc-deposit:'.$txn.':'.$vout]);
                $owners=WalletAddress::where('network_id',$network->id)->where('address',$output['address'])->get();
                if($owners->isEmpty())return;
                if($owners->pluck('user_id')->unique()->count()!==1)throw new RuntimeException('BTC_DEPOSIT_OWNERSHIP_CONFLICT');
                $accounts=Wallet::where('currency_id',$currency->id)->where('user_id',$owners->first()->user_id)->lockForUpdate()->get();
                if($accounts->count()!==1)throw new RuntimeException('BTC_DEPOSIT_OWNERSHIP_CONFLICT');$account=$accounts->first();
                $proof=DB::table('bitcoin_deposit_outputs')->where('txn',$txn)->where('vout',$vout)->first();
                $d=$proof?Deposit::whereKey($proof->deposit_id)->lockForUpdate()->first():null;
                if(!$proof){
                    $old=Deposit::where('network_id',$network->id)->where('txn',$txn)->where('address',$output['address'])->where('user_id',$account->user_id)->lockForUpdate()->get();
                    // Adopt one matching historical row without crediting it twice.
                    $used=DB::table('bitcoin_deposit_outputs')->where('txn',$txn)->pluck('deposit_id');
                    $unmapped=$old->filter(fn($r)=>!$used->contains($r->id));
                    $old=$unmapped->filter(fn($r)=>bccomp((string)$r->amount,$amount,8)===0);
                    if($old->isEmpty() && $unmapped->isNotEmpty())throw new RuntimeException('BTC_LEGACY_DEPOSIT_REVIEW');
                    if($old->count()>1)throw new RuntimeException('BTC_LEGACY_DEPOSIT_REVIEW');
                    $d=$old->first();
                    if(!$d){
                        $fee=app(CurrencyService::class)->calculateSystemFee('btc',$currency,$amount);
                        if(bccomp((string)$fee,$amount,8)>0)throw new RuntimeException('BTC_INVALID_DEPOSIT_FEE');
                        $d=Deposit::create(['deposit_id'=>generate_uuid(),'source_id'=>'btc:'.$txn.':'.$vout,'txn'=>$txn,
                            'currency_id'=>$currency->id,'network_id'=>$network->id,'type'=>'coin','address'=>$output['address'],
                            'user_id'=>$account->user_id,'amount'=>$amount,'full_amount'=>$amount,'network_fee'=>0,'system_fee'=>$fee,
                            'confirms'=>(int)$tx['confirmations'],'status'=>bccomp($amount,(string)$currency->min_deposit,8)<0?DEPOSIT_IGNORED:DEPOSIT_PENDING,
                            'wallet_transfer_status'=>'processed','initial_raw'=>json_encode(['wallet_id'=>$wallet->id,'txn'=>$txn,'vout'=>$vout])]);
                    }
                    DB::table('bitcoin_deposit_outputs')->insert(['txn'=>$txn,'vout'=>$vout,'wallet_id'=>$wallet->id,'deposit_id'=>$d->id,'created_at'=>now(),'updated_at'=>now()]);
                }
                if(!$d || bccomp((string)$d->amount,$amount,8)!==0 || (int)$d->user_id!==$account->user_id)throw new RuntimeException('BTC_DEPOSIT_PROOF_MISMATCH');
                if($d->status===DEPOSIT_CONFIRMED && (int)$tx['confirmations']<max(ConfirmationPolicy::minimum('bitcoin'),(int)$currency->min_deposit_confirmation)){app(DepositRisk::class)->record($d,'bitcoin',['confirmations'=>$tx['confirmations'],'vout'=>$vout]);return;}
                if($d->status===DEPOSIT_PENDING && (int)$tx['confirmations']>=max(ConfirmationPolicy::minimum('bitcoin'),(int)$currency->min_deposit_confirmation)){
                    app(DepositCreditService::class)->credit($account,bcsub($amount,(string)$d->system_fee,8));
                    $d->status=DEPOSIT_CONFIRMED;$d->confirms=(int)$tx['confirmations'];$d->save();
                    DB::afterCommit(function()use($account,$currency,$d){
                        try{
                            \Illuminate\Support\Facades\Mail::to($account->user)->queue(new \App\Mail\Deposits\DepositReceived($account->user,bcsub((string)$d->amount,(string)$d->system_fee,8),$currency->symbol));
                            if(setting('notification.admin_email') && setting('notification.crypto_deposits'))\Illuminate\Support\Facades\Mail::to(setting('notification.admin_email'))->queue(new \App\Mail\Deposits\AdminDepositReceived($d->amount,$currency->symbol,route('admin.reports.deposits').'?search='.$d->deposit_id));
                            event(new \App\Events\DepositUpdated($d, 'received'));
                        }catch(\Throwable $e){\Illuminate\Support\Facades\Log::warning('BTC deposit notification failed',['deposit_id'=>$d->id]);}
                    });
                }
            });
        }
        return true;
    }
}
