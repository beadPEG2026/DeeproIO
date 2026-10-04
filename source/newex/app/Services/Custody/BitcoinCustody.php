<?php
namespace App\Services\Custody;

use App\Services\Wallet\{BitcoinWalletManager,BitcoinWalletRpc};
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Core signs locally; the existing custody journal persists raw bytes before broadcast. */
class BitcoinCustody {
    public function __construct(private BitcoinWalletRpc $rpc,private BitcoinWalletManager $wallets) {}
    public function call(string $action,array $p): array {
        $w=isset($p['bitcoin_wallet_id'])?$this->wallets->wallet((int)$p['bitcoin_wallet_id']):
            (DB::table('bitcoin_wallets')->where('address',$p['sender']??'')->first()??$this->wallets->active());
        if(!$w)throw new RuntimeException('BTC_WALLET_NOT_FOUND');
        if(($p['sender']??$w->address)!==$w->address)throw new RuntimeException('CUSTODY_SOURCE_CHANGED');
        if($action==='receipt')return $this->receipt($w,$p);
        if($action==='broadcast') {
            if(!is_string($p['raw']??null) || !ctype_xdigit($p['raw']))throw new RuntimeException('BTC_INVALID_TRANSACTION');
            $decoded=$this->rpc->call('decoderawtransaction',[$p['raw']]);
            if(($decoded['txid']??'')!==($p['txn']??''))throw new RuntimeException('BTC_INVALID_TRANSACTION');
            try{$tx=$this->rpc->call('sendrawtransaction',[$p['raw']]);}
            catch(RuntimeException $e){if($e->getMessage()==='BTC_RPC_ERROR_27')return ['success'=>true];throw $e;}
            if($tx!==$p['txn'])throw new RuntimeException('BTC_INVALID_TRANSACTION');
            return ['success'=>true,'txn'=>$tx];
        }
        $this->rpc->ready($w->name,in_array($action,['validate','prepare'],true));
        if(!empty($p['destination']))$this->rpc->address($p['destination']);
        if($action==='validate') {
            if(($this->rpc->call('getaddressinfo',[$w->address],$w->name)['ismine']??false)!==true)throw new RuntimeException('BTC_ADDRESS_NOT_OWNED');
            return ['success'=>true];
        }
        $balances=$this->rpc->call('getbalances',[],$w->name);
        if($action==='balance')return ['success'=>true,'balance'=>BitcoinWalletRpc::amount($balances['mine']['trusted']??0)];
        if($action==='prepare')return $this->prepare($w,$p);
        throw new RuntimeException('CUSTODY_ACTION_INVALID');
    }
    private function prepare(object $w,array $p): array {
        $id=(int)($p['id']??0);$task=DB::table('custody_transfers')->find($id);
        if(!$task || $task->chain!=='bitcoin' || $task->signed_payload || $task->status!=='approved'
            || (int)$task->bitcoin_wallet_id!==$w->id)throw new RuntimeException('CUSTODY_STATE_CONFLICT');
        $amount=BitcoinWalletRpc::amount($p['amount']);$cap=BitcoinWalletRpc::amount($p['max_fee']);
        $feeQuote=$this->rpc->call('estimatesmartfee',[6,'conservative']);
        if(empty($feeQuote['feerate']))throw new RuntimeException('BTC_FEE_ESTIMATE_UNAVAILABLE');
        $rate=bcmul(BitcoinWalletRpc::amount($feeQuote['feerate']),'100000',4);
        $rateCap=(string)DB::table('bitcoin_wallet_control')->where('id',1)->value('max_fee_rate');
        if(bccomp($rate,$rateCap,4)>0)throw new RuntimeException('BTC_FEE_RATE_LIMIT');
        // A failed preparation has never broadcast. Reuse its reservations, or release them for a new quote.
        DB::table('bitcoin_utxo_reservations')->where('transfer_id',$id)->delete();
        $reserved=DB::table('bitcoin_utxo_reservations')->get()->mapWithKeys(fn($r)=>[$r->txn.':'.$r->vout=>true]);
        $coins=$this->rpc->call('listunspent',[max(1,(int)$p['confirmations']),9999999,[],false],$w->name);
        usort($coins,fn($a,$b)=>bccomp(BitcoinWalletRpc::amount($b['amount']),BitcoinWalletRpc::amount($a['amount']),8));
        $inputs=[];$total='0';$need=bcadd($amount,$cap,8);
        foreach($coins as $u) {
            if(($u['safe']??false)!==true || ($u['spendable']??true)!==true || $reserved->has($u['txid'].':'.$u['vout']))continue;
            $inputs[]=['txid'=>$u['txid'],'vout'=>(int)$u['vout']];$total=bcadd($total,BitcoinWalletRpc::amount($u['amount']),8);
            if(bccomp($total,$need,8)>=0)break;
            if(count($inputs)>=100)throw new RuntimeException('BTC_TOO_MANY_INPUTS');
        }
        if(bccomp($total,$need,8)<0)throw new RuntimeException('CUSTODY_INSUFFICIENT_GAS_OR_BALANCE');
        DB::transaction(function()use($inputs,$id){foreach($inputs as $u)DB::table('bitcoin_utxo_reservations')->insert(['txn'=>$u['txid'],'vout'=>$u['vout'],'transfer_id'=>$id,'created_at'=>now()]);});
        try {
            $funded=$this->rpc->call('walletcreatefundedpsbt',[$inputs,[$p['destination']=>$amount],0,
                ['add_inputs'=>false,'fee_rate'=>$rate,'replaceable'=>false]],$w->name);
            $fee=BitcoinWalletRpc::amount($funded['fee']??-1);
            if(bccomp($fee,$cap,8)>0)throw new RuntimeException('CUSTODY_FEE_LIMIT');
            $signed=$this->rpc->call('walletprocesspsbt',[$funded['psbt']],$w->name);
            $final=$this->rpc->call('finalizepsbt',[$signed['psbt']]);
            if(($final['complete']??false)!==true)throw new RuntimeException('BTC_SIGNATURE_INCOMPLETE');
            $d=$this->rpc->call('decoderawtransaction',[$final['hex']]);
            $actualInputs=array_map(fn($v)=>$v['txid'].':'.$v['vout'],$d['vin']??[]);
            $expectedInputs=array_map(fn($v)=>$v['txid'].':'.$v['vout'],$inputs);sort($actualInputs);sort($expectedInputs);
            if($actualInputs!==$expectedInputs)throw new RuntimeException('BTC_INPUT_MISMATCH');
            $received='0';$out='0';
            foreach($d['vout'] as $v){
                $value=BitcoinWalletRpc::amount($v['value']);$out=bcadd($out,$value,8);
                $address=$v['scriptPubKey']['address']??'';
                if($address===$p['destination'])$received=bcadd($received,$value,8);
                elseif(!$address || ($this->rpc->call('getaddressinfo',[$address],$w->name)['ismine']??false)!==true)throw new RuntimeException('BTC_CHANGE_NOT_OWNED');
            }
            if(bccomp($received,$amount,8)!==0 || bccomp(bcsub($total,$out,8),$fee,8)!==0)throw new RuntimeException('CUSTODY_AMOUNT_MISMATCH');
            return ['success'=>true,'raw'=>$final['hex'],'txn'=>$d['txid'],'amount'=>$amount,'fee'=>$fee,'inputs'=>$inputs];
        }catch(\Throwable $e){DB::table('bitcoin_utxo_reservations')->where('transfer_id',$id)->delete();throw $e;}
    }
    private function receipt(object $w,array $p): array {
        try{$t=$this->rpc->call('gettransaction',[$p['txn']],$w->name);}
        catch(RuntimeException $e){if($e->getMessage()==='BTC_RPC_ERROR_5')return ['state'=>'pending'];throw $e;}
        if((int)($t['confirmations']??0)<0 || !empty($t['walletconflicts']) || ($t['abandoned']??false))return ['state'=>'review','txn'=>$p['txn']];
        if((int)($t['confirmations']??0)<(int)$p['confirmations'])return ['state'=>'pending'];
        $d=$this->rpc->call('decoderawtransaction',[$t['hex']]);$amount='0';
        foreach($d['vout']??[] as $v)if(($v['scriptPubKey']['address']??'')===$p['destination'])$amount=bcadd($amount,BitcoinWalletRpc::amount($v['value']),8);
        if(($d['txid']??null)!==$p['txn'] || bccomp($amount,$p['amount'],8)!==0)throw new RuntimeException('CUSTODY_RECEIPT_MISMATCH');
        return ['state'=>'confirmed','txn'=>$p['txn'],'amount'=>$amount,'confirmations'=>(int)$t['confirmations']];
    }
}
