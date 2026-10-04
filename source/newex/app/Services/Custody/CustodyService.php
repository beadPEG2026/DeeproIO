<?php
namespace App\Services\Custody;

use App\Models\Wallet\WalletAddress;
use App\Models\Withdrawal\Withdrawal;
use App\Models\Wallet\Wallet;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\{DB,Crypt};
use Illuminate\Support\Str;
use RuntimeException;

class CustodyService {
    public const ACTIVE=['awaiting_approval','approved','prepared','confirming','review'];
    public function __construct(private CustodyBridge $bridge) {}
    public function audit(string $action,array $detail=[],?int $id=null,?int $actor=null): void {
        DB::table('custody_audits')->insert(['actor_id'=>$actor??auth()->id(),'action'=>$action,'transfer_id'=>$id,'detail'=>json_encode($detail),'created_at'=>now()]);
    }
    public function network(string $chain): object {
        $n=DB::table('custody_networks')->where('chain',$chain)->first();
        if (!$n || !$n->enabled || bccomp((string)$n->max_fee,'0',18)<=0) CustodyNetwork::fail('CUSTODY_NETWORK_DISABLED');
        return $n;
    }
    public function create(array $asset,array $data): object {
        return DB::transaction(function()use($asset,$data){
            if($asset['chain']==='bitcoin')DB::select('select pg_advisory_xact_lock(hashtext(?))',['bitcoin:wallet-management']);
            return $this->createTransfer($asset,$data);
        });
    }
    private function createTransfer(array $asset,array $data): object {
        $n=$this->network($asset['chain']);
        CustodyNetwork::address($asset['chain'],$data['sender']); CustodyNetwork::address($asset['chain'],$data['destination']);
        if($asset['chain']==='bitcoin') { $manager=app(\App\Services\Wallet\BitcoinWalletManager::class);$w=isset($data['bitcoin_wallet_id'])&&$data['purpose']==='migration'?$manager->wallet((int)$data['bitcoin_wallet_id']):$manager->active();if(!$w)CustodyNetwork::fail('BTC_WALLET_NOT_FOUND');$data['bitcoin_wallet_id']=$w->id;$data['sender']=$w->address; }
        if ($this->evm($asset['chain'])) { $data['sender']=strtolower($data['sender']); $data['destination']=strtolower($data['destination']); }
        if ($this->sameAddress($asset['chain'],$data['sender'],$data['destination'])) CustodyNetwork::fail('CUSTODY_SELF_TRANSFER');
        if (bccomp((string)$data['amount'],'0',18)<=0) CustodyNetwork::fail('CUSTODY_INVALID_AMOUNT');
        $row=DB::table('custody_transfers')->where('key',$data['key'])->first(); if ($row) { foreach (['purpose','sender','destination','amount'] as $f) { if (($f==='amount' ? bccomp((string)$row->$f,(string)$data[$f],18)!==0 : (string)$row->$f!==(string)$data[$f])) CustodyNetwork::fail('CUSTODY_IDEMPOTENCY_CONFLICT'); } return $row; }
        unset($asset['symbol']);
        $id=DB::table('custody_transfers')->insertGetId(array_merge($asset,['status'=>'awaiting_approval','max_fee'=>CustodyNetwork::feeCap($n,$asset['contract']??null),'confirmations'=>$n->confirmations,'requested_by'=>auth()->id(),'created_at'=>now(),'updated_at'=>now()],$data));
        $this->audit('transfer.created',['purpose'=>$data['purpose']],$id);
        return DB::table('custody_transfers')->find($id);
    }
    public function withdrawal(Withdrawal $w): array {
        if ($w->fund_origin==='treasury' || $w->source_id==='system') CustodyNetwork::fail('CUSTODY_LEGACY_SYSTEM_REVIEW');
        $a=CustodyNetwork::asset($w->currency_id,$w->network_id);
        $row=$this->create($a,['key'=>'withdrawal:'.$w->id,'purpose'=>'withdrawal','withdrawal_id'=>$w->id,
            'sender'=>$this->hotSender($a['chain']),'destination'=>trim($w->address),
            'amount'=>bcsub((string)$w->amount,(string)$w->fee,18),'status'=>'approved','approved_by'=>auth()->id()]);
        return ['status'=>STATUS_OK,'source'=>'custody:'.$row->id,'message'=>'Custody transfer queued'];
    }
    public function cold(int $id): ?object {
        return DB::transaction(function()use($id) {
            $r=DB::table('cold_storage')->where('id',$id)->lockForUpdate()->first();
            if (!$r || !$r->status || !$r->approved_by || !$r->approved_at || !trim($r->address??'') || !$r->currency_id || !$r->network_id) return null;
            if (DB::table('custody_transfers')->where('rule_id',$id)->whereIn('status',self::ACTIVE)->exists()) return null;
            if ($r->cold_storage_transaction_id) return null; // Legacy activity needs explicit reconciliation.
            $a=CustodyNetwork::asset($r->currency_id,$r->network_id); $n=$this->network($a['chain']);
            $sender=$this->hotSender($a['chain']);
            $b=$this->bridge->call($a['chain'],'balance',['sender'=>$sender,'contract'=>$a['contract']]);
            if (bccomp((string)$b['balance'],(string)$r->cold_min_balance_amount,18)<0) return null;
            $reserved=(string)DB::table('withdrawals')->where('currency_id',$r->currency_id)->where('network_id',$r->network_id)->where('fund_origin','user')->whereIn('status',[WITHDRAWAL_WAITING_APPROVAL,WITHDRAWAL_CONFIRMED_BY_SYSTEM,WITHDRAWAL_WAITING_PROVIDER_APPROVAL])->sum('amount');
            $other=(string)DB::table('custody_transfers')->where('chain',$a['chain'])->where('currency_id',$r->currency_id)->whereIn('purpose',['cold','gas'])->whereIn('status',self::ACTIVE)->sum('amount');
            $available=bcsub(bcsub(bcsub((string)$b['balance'],$reserved,18),$other,18),(string)$r->hot_reserve,18);
            if (!$a['contract']) $available=bcsub($available,CustodyNetwork::feeCap($n),18);
            $spent=(string)DB::table('custody_transfers')->where('rule_id',$id)->where('created_at','>=',now()->startOfDay())->whereNotIn('status',['cancelled','failed'])->sum('amount');
            $amount=$this->minimum([(string)$r->cold_transfer_amount,$available,bcsub((string)$r->daily_limit,$spent,18)]);
            if (bccomp($amount,'0',18)<=0) return null;
            $data=['key'=>'cold:'.Str::uuid(),'purpose'=>'cold','rule_id'=>$id,'sender'=>$sender,'destination'=>$r->address,'amount'=>$amount];
            if($a['chain']==='bitcoin' && DB::table('bitcoin_wallet_control')->where('id',1)->value('auto_cold'))$data+=['status'=>'approved','approved_by'=>$r->approved_by];
            return $this->create($a,$data);
        });
    }
    public function sweep(int $id): ?object {
        return DB::transaction(function()use($id) {
            $d=DB::table('deposits')->where('id',$id)->lockForUpdate()->first();
            if (!$d || $d->status!==DEPOSIT_CONFIRMED || $d->wallet_transfer_status!=='review') return null;
            if ($old=DB::table('custody_transfers')->where('deposit_id',$id)->first()) return $old;
            // Only independently verified receipts enter automatic custody. Imported legacy records remain reviewable.
            $proof=json_decode($d->initial_raw??'',true);
            if (!is_array($proof) || !$proof) return null;
            $journal=DB::table('tron_deposit_receipts')->where('deposit_id',$id)->where('txn',$d->txn)->exists() || DB::table('chain_deposit_receipts')->where('deposit_id',$id)->where('txn',$d->txn)->exists();
            if(!$journal)return null;
            $a=CustodyNetwork::asset($d->currency_id,$d->network_id); $n=$this->network($a['chain']);
            $slugs=array_keys(array_filter(CustodyNetwork::MAP,fn($m)=>$m[0]===$a['chain']));
            $ids=DB::table('networks')->whereIn('slug',$slugs)->pluck('id');
            $query=WalletAddress::whereIn('network_id',$ids);
            $addresses=($this->evm($a['chain'])?$query->whereRaw('lower(address)=?',[strtolower($d->address)]):$query->where('address',$d->address))->get();
            if($addresses->isEmpty()||$addresses->pluck('user_id')->unique()->count()!==1||(int)$addresses->first()->user_id!==(int)$d->user_id||$addresses->map(fn($v)=>$v->private_key)->unique()->count()!==1)CustodyNetwork::fail('CUSTODY_ADDRESS_OWNERSHIP_CONFLICT');
            $wa=$addresses->firstWhere('network_id',$d->network_id)??$addresses->first();
            $row=$this->create($a,['key'=>'deposit:'.$id,'purpose'=>'sweep','deposit_id'=>$id,'wallet_address_id'=>$wa->id,
                'sender'=>$d->address,'destination'=>$this->hotSender($a['chain']),'amount'=>(string)$d->amount,
                'status'=>'awaiting_approval','requested_by'=>null]);
            app(SweepAutomation::class)->approve($row->id, true);
            $row=DB::table('custody_transfers')->find($row->id);
            DB::table('deposits')->where('id',$id)->update(['wallet_transfer_status'=>'custody']);
            return $row;
        });
    }
    public function approve(int $id,int $actor): void {
        DB::transaction(function()use($id,$actor){
            $r=DB::table('custody_transfers')->where('id',$id)->lockForUpdate()->first();
            if (!$r || $r->status!=='awaiting_approval') CustodyNetwork::fail('CUSTODY_STATE_CONFLICT');
            if ($r->requested_by && (int)$r->requested_by===$actor) CustodyNetwork::fail('CUSTODY_INDEPENDENT_APPROVAL_REQUIRED');
            if ($r->signed_payload || $r->txn) CustodyNetwork::fail('CUSTODY_CHAIN_REVIEW');
            $n=$this->network($r->chain);
            // Pending tasks retain their original intent. Approval can tighten limits, never loosen them.
            $fee=$this->minimum([(string)$r->max_fee,CustodyNetwork::feeCap($n,$r->contract)]);
            $confirmations=max((int)$r->confirmations,(int)$n->confirmations);
            DB::table('custody_transfers')->where('id',$id)->update(['status'=>'approved','approved_by'=>$actor,'max_fee'=>$fee,'confirmations'=>$confirmations,'updated_at'=>now()]);
            $this->audit('transfer.approved',['max_fee_before'=>$r->max_fee,'max_fee'=>$fee,'confirmations'=>$confirmations],$id,$actor);
        });
    }
    public function cancel(int $id,int $actor): void {
        DB::transaction(function()use($id,$actor){
            $r=DB::table('custody_transfers')->where('id',$id)->lockForUpdate()->first();
            if (!$r || !in_array($r->status,['awaiting_approval','approved'],true) || $r->signed_payload || $r->purpose==='withdrawal') CustodyNetwork::fail('CUSTODY_CANNOT_CANCEL');
            DB::table('custody_transfers')->where('id',$id)->update(['status'=>'cancelled','updated_at'=>now()]);
            $this->audit('transfer.cancelled',[],$id,$actor);
        });
    }
    /** Per-sender lock survives DB transactions and serializes all assets on that chain. */
    public function run(int $id): void {
        $r=DB::table('custody_transfers')->find($id); if (!$r) return;
        $lock=$r->chain==='bitcoin'?'bitcoin:wallet-management':'custody:'.$r->chain.':'.($this->evm($r->chain)?strtolower($r->sender):$r->sender);
        if (!DB::selectOne('select pg_try_advisory_lock(hashtext(?)) as locked',[$lock])->locked) return;
        try {
            $r=DB::table('custody_transfers')->find($id);
            if (!in_array($r->status,['approved','prepared','confirming','review'],true)) return;
            // Reconcile every persisted signature even when another old send is uncertain.
            if (in_array($r->status,['confirming','review'],true)) {if($r->signed_payload)$this->reconcile($r);return;}
            // A signed or uncertain previous send must settle before another nonce/sequence is allocated.
            if ($r->chain!=='bitcoin' && DB::table('custody_transfers')->where('chain',$r->chain)->where('sender',$r->sender)->where('id','!=',$id)->whereIn('status',['prepared','confirming','review'])->whereNotNull('signed_payload')->exists()) return;
            $this->network($r->chain);
            if ($r->rule_id && !DB::table('cold_storage')->where('id',$r->rule_id)->where('status',true)->whereNotNull('approved_at')->exists()) throw new RuntimeException('CUSTODY_RULE_DISABLED');
            if ($r->status==='approved') {
                app(SweepAutomation::class)->assertRunnable($r);
                $this->validateSource($r);
                $payload=$this->intent($r); if($r->chain!=='bitcoin')$payload['private_key']=$r->wallet_address_id?WalletAddress::findOrFail($r->wallet_address_id)->private_key:setting($r->chain.'.private_key');
                $p=$this->bridge->call($r->chain,'prepare',$payload); unset($payload);
                if (empty($p['raw']) || empty($p['txn']) || bccomp((string)$p['fee'],(string)$r->max_fee,18)>0 || bccomp((string)$p['amount'],'0',18)<=0 || bccomp((string)$p['amount'],(string)$r->amount,18)>0) throw new RuntimeException('CUSTODY_INVALID_PREPARATION');
                if ($r->purpose!=='sweep' && bccomp((string)$p['amount'],(string)$r->amount,18)!==0) throw new RuntimeException('CUSTODY_AMOUNT_MISMATCH');
                $raw=Crypt::encryptString(json_encode($p['raw'],JSON_THROW_ON_ERROR)); unset($p['raw']);
                if(!DB::table('custody_transfers')->where('id',$id)->where('status','approved')->update(['signed_payload'=>$raw,'prepared'=>json_encode($p),'txn'=>$p['txn'],'sent_amount'=>$p['amount'],'fee'=>$p['fee'],'status'=>'prepared','last_error'=>null,'updated_at'=>now()]))return;
                $this->audit('transfer.prepared',['txn'=>$p['txn']],$id); $r=DB::table('custody_transfers')->find($id);
            }
            $this->validateSource($r);
            // Persist before broadcasting. An uncertain HTTP result never creates a new signature.
            DB::table('custody_transfers')->where('id',$id)->update(['status'=>'confirming','broadcast_at'=>now(),'updated_at'=>now()]);
            $this->audit('transfer.broadcast_requested',[],$id);
            $this->bridge->call($r->chain,'broadcast',$this->intent($r)+['txn'=>$r->txn,'raw'=>json_decode(Crypt::decryptString($r->signed_payload),true),'prepared'=>json_decode($r->prepared,true)]);
        } catch (\Throwable $e) {
            $code=$e instanceof \Illuminate\Validation\ValidationException ? (array_values($e->errors())[0][0]??'CUSTODY_VALIDATION_FAILED') : $e->getMessage();
            if (!preg_match('/^[A-Z_0-9]{3,100}$/D',$code)) $code='CUSTODY_RETRY_REQUIRED';
            DB::table('custody_transfers')->where('id',$id)->update(['last_error'=>$code,'updated_at'=>now()]);
            if ($code==='CUSTODY_INSUFFICIENT_GAS_OR_BALANCE' && $r->purpose==='sweep' && $r->contract && $r->status==='approved') {
                try {$this->fundGas($r);} catch (\Throwable $ignored) { $code=preg_match('/^CUSTODY_[A-Z_]+$/D',$ignored->getMessage())?$ignored->getMessage():'CUSTODY_GAS_REVIEW'; DB::table('custody_transfers')->where('id',$id)->update(['last_error'=>$code]); }
            }
        } finally { DB::select('select pg_advisory_unlock(hashtext(?))',[$lock]); }
    }
    private function validateSource(object $r): void {
        $asset=CustodyNetwork::asset($r->currency_id,$r->network_id);
        if(($asset['contract']??null)!==($r->contract??null))throw new RuntimeException('CUSTODY_CONTRACT_CHANGED');
        if($r->withdrawal_id) {
            $w=Withdrawal::findOrFail($r->withdrawal_id);
            if($w->fund_origin!=='user'||$w->status!==WITHDRAWAL_WAITING_PROVIDER_APPROVAL||$w->source_id!=='custody:'.$r->id)throw new RuntimeException('CUSTODY_WITHDRAWAL_STATE_CONFLICT');
            app(\App\Services\Wallet\WithdrawalNetworkPolicy::class)->assertSupported($w->currency_id,$w->network_id);
        }
        if ($r->wallet_address_id) {
            $a=WalletAddress::find($r->wallet_address_id);
            if (!$a || !$this->sameAddress($r->chain,$a->address,$r->sender)) throw new RuntimeException('CUSTODY_SOURCE_CHANGED');
        } elseif ($r->chain==='bitcoin') { $w=app(\App\Services\Wallet\BitcoinWalletManager::class)->wallet((int)$r->bitcoin_wallet_id);if($w->address!==$r->sender)throw new RuntimeException('CUSTODY_SOURCE_CHANGED'); } elseif (!$this->sameAddress($r->chain,(string)setting($r->chain.'.wallet'),$r->sender)) throw new RuntimeException('CUSTODY_SOURCE_CHANGED');
        if($r->chain==='bitcoin' && $r->purpose==='migration'){
            $active=app(\App\Services\Wallet\BitcoinWalletManager::class)->active();
            if(!$active || $active->address!==$r->destination || $active->id===(int)$r->bitcoin_wallet_id)throw new RuntimeException('CUSTODY_DESTINATION_CHANGED');
            if(DB::table('custody_transfers')->where('bitcoin_wallet_id',$r->bitcoin_wallet_id)->where('id','!=',$r->id)->whereIn('status',self::ACTIVE)->exists())throw new RuntimeException('BTC_ACTIVE_TASKS');
        }
        if ($r->purpose==='gas') {
            $reserved=(string)DB::table('withdrawals')->where('currency_id',$r->currency_id)->where('network_id',$r->network_id)->where('fund_origin','user')->whereIn('status',[WITHDRAWAL_WAITING_APPROVAL,WITHDRAWAL_WAITING_PROVIDER_APPROVAL,WITHDRAWAL_CONFIRMED_BY_SYSTEM])->sum('amount');
            $reserve=(string)(DB::table('cold_storage')->where('currency_id',$r->currency_id)->where('network_id',$r->network_id)->max('hot_reserve')??0);
            $b=$this->bridge->call($r->chain,'balance',$this->intent($r));
            if(bccomp((string)$b['balance'],bcadd(bcadd($r->amount,$r->max_fee,18),bcadd($reserved,$reserve,18),18),18)<0)throw new RuntimeException('CUSTODY_RESERVE_REQUIRED');
        }
        if ($r->rule_id) {
            $rule=DB::table('cold_storage')->find($r->rule_id);
            if(!$rule||!$rule->status||!$rule->approved_at)throw new RuntimeException('CUSTODY_RULE_DISABLED');
            if (!$this->sameAddress($r->chain,$rule->address,$r->destination)) throw new RuntimeException('CUSTODY_DESTINATION_CHANGED');
            $b=$this->bridge->call($r->chain,'balance',$this->intent($r));
            $pending=(string)DB::table('withdrawals')->where('currency_id',$r->currency_id)->where('network_id',$r->network_id)->where('fund_origin','user')->whereIn('status',[WITHDRAWAL_WAITING_APPROVAL,WITHDRAWAL_WAITING_PROVIDER_APPROVAL,WITHDRAWAL_CONFIRMED_BY_SYSTEM])->sum('amount');
            $need=bcadd(bcadd($r->amount,$rule->hot_reserve,18),$pending,18); if (!$r->contract) $need=bcadd($need,$r->max_fee,18);
            if (bccomp((string)$b['balance'],$need,18)<0) throw new RuntimeException('CUSTODY_RESERVE_REQUIRED');
        }
    }
    private function reconcile(object $r): void {
        $p=json_decode($r->prepared,true); $proof=$this->bridge->call($r->chain,'receipt',$this->intent($r)+['txn'=>$r->txn,'prepared'=>$p]);
        if (($proof['state']??'')==='pending') {
            // Re-broadcast only the identical, persisted signed transaction while valid.
            if ($r->status==='confirming' && time()-strtotime($r->broadcast_at)>45 && (!isset($p['expires_at']) || time()<(int)$p['expires_at'])) {
                $this->bridge->call($r->chain,'broadcast',$this->intent($r)+['txn'=>$r->txn,'raw'=>json_decode(Crypt::decryptString($r->signed_payload),true),'prepared'=>$p]);
            }
            return;
        }
        if (($proof['state']??'')==='failed' && ($proof['final']??false)===true && ($proof['txn']??null)===$r->txn) { $this->failed($r,$proof); return; }
        if (($proof['state']??'')!=='confirmed') {
            DB::table('custody_transfers')->where('id',$r->id)->update(['status'=>'review','last_error'=>'CUSTODY_CHAIN_REVIEW','receipt'=>json_encode($proof),'updated_at'=>now()]);
            $this->audit('transfer.review',['state'=>$proof['state']??'unknown'],$r->id); return;
        }
        if (($proof['txn']??null)!==$r->txn || bccomp((string)($proof['amount']??'0'),(string)$r->sent_amount,18)!==0 || (int)($proof['confirmations']??0)<$r->confirmations) throw new RuntimeException('CUSTODY_RECEIPT_MISMATCH');
        DB::transaction(function()use($r,$proof) {
            $row=DB::table('custody_transfers')->where('id',$r->id)->lockForUpdate()->first(); if (!in_array($row->status,['confirming','review'],true)) return;
            if ($row->withdrawal_id) {
                $w=Withdrawal::whereKey($row->withdrawal_id)->lockForUpdate()->firstOrFail();
                if ($w->fund_origin!=='user' || $w->status!==WITHDRAWAL_WAITING_PROVIDER_APPROVAL || $w->source_id!=='custody:'.$row->id) throw new RuntimeException('CUSTODY_WITHDRAWAL_STATE_CONFLICT');
                $wallets=Wallet::where('user_id',$w->user_id)->where('currency_id',$w->currency_id)->orderBy('id')->lockForUpdate()->get();
                if($wallets->count()!==1)throw new RuntimeException('CUSTODY_WITHDRAWAL_BALANCE_CONFLICT');$wallet=$wallets->first();
                if (bccomp((string)$wallet->balance_in_withdraw,(string)$w->amount,18)<0) throw new RuntimeException('CUSTODY_WITHDRAWAL_BALANCE_CONFLICT');
                app(WalletService::class)->decrease($wallet,$w->amount,'withdraw');
                $w->status=WITHDRAWAL_CONFIRMED_BY_PROVIDER; $w->txn=$proof['sender_tx']??$r->txn; $w->confirms=$proof['confirmations']; $w->initial_raw=json_encode(['custody_receipt'=>$proof]); $w->save();
            }
            if ($row->deposit_id) DB::table('deposits')->where('id',$row->deposit_id)->update(['wallet_transfer_status'=>'processed']);
            DB::table('custody_transfers')->where('id',$row->id)->update(['status'=>'completed','receipt'=>json_encode($proof),'last_error'=>null,'completed_at'=>now(),'updated_at'=>now()]);
            $this->audit('transfer.completed',['txn'=>$r->txn],$r->id);
        });
    }
    private function failed(object $r,array $proof): void {
        DB::transaction(function()use($r,$proof) {
            $row=DB::table('custody_transfers')->where('id',$r->id)->lockForUpdate()->first();
            if (!in_array($row->status,['confirming','review'],true))return;
            if($row->withdrawal_id) {
                $w=Withdrawal::whereKey($row->withdrawal_id)->lockForUpdate()->firstOrFail();
                if($w->fund_origin!=='user'||$w->status!==WITHDRAWAL_WAITING_PROVIDER_APPROVAL||$w->source_id!=='custody:'.$row->id)throw new RuntimeException('CUSTODY_WITHDRAWAL_STATE_CONFLICT');
                $wallets=Wallet::where('user_id',$w->user_id)->where('currency_id',$w->currency_id)->orderBy('id')->lockForUpdate()->get();
                if($wallets->count()!==1)throw new RuntimeException('CUSTODY_WITHDRAWAL_BALANCE_CONFLICT');$wallet=$wallets->first();
                if(bccomp((string)$wallet->balance_in_withdraw,(string)$w->amount,18)<0)throw new RuntimeException('CUSTODY_WITHDRAWAL_BALANCE_CONFLICT');
                app(WalletService::class)->decrease($wallet,$w->amount,'withdraw');
                app(WalletService::class)->increase($wallet,$w->amount,'wallet');
                $w->status=WITHDRAWAL_FAILED;$w->txn=$r->txn;$w->rejected_reason='CUSTODY_CHAIN_FAILED';$w->initial_raw=json_encode(['custody_receipt'=>$proof]);$w->save();
            }
            DB::table('custody_transfers')->where('id',$row->id)->update(['status'=>'failed','receipt'=>json_encode($proof),'last_error'=>'CUSTODY_CHAIN_FAILED','completed_at'=>now(),'updated_at'=>now()]);
            $this->audit('transfer.failed',['txn'=>$r->txn,'withdrawal_refunded'=>(bool)$row->withdrawal_id],$r->id);
        });
    }
    public function resume(int $id,int $actor): void {
        DB::transaction(function()use($id,$actor){
            $r=DB::table('custody_transfers')->where('id',$id)->lockForUpdate()->first();
            if(!$r||!in_array($r->status,['cancelled','failed'],true)||$r->purpose==='withdrawal')CustodyNetwork::fail('CUSTODY_STATE_CONFLICT');
            if($r->signed_payload) {
                $proof=json_decode($r->receipt??'',true);
                if($r->status!=='failed'||($proof['final']??false)!==true)CustodyNetwork::fail('CUSTODY_CHAIN_REVIEW');
                DB::table('custody_transfer_attempts')->insert(['transfer_id'=>$id,'signed_payload'=>$r->signed_payload,'txn'=>$r->txn,'prepared'=>$r->prepared,'receipt'=>$r->receipt,'created_at'=>now()]);
            }
            $n=$this->network($r->chain);
            DB::table('custody_transfers')->where('id',$id)->update(['max_fee'=>CustodyNetwork::feeCap($n,$r->contract),'confirmations'=>$n->confirmations,'status'=>'awaiting_approval','requested_by'=>$actor,'approved_by'=>null,'signed_payload'=>null,'txn'=>null,'prepared'=>null,'receipt'=>null,'sent_amount'=>null,'fee'=>null,'completed_at'=>null,'broadcast_at'=>null,'last_error'=>null,'updated_at'=>now()]);
            $this->audit('transfer.resumed',[],$id,$actor);
        });
    }
    public function intent(object $r): array {
        return ['id'=>$r->id,'chain'=>$r->chain,'sender'=>$r->sender,'destination'=>$r->destination,'contract'=>$r->contract,
            'amount'=>(string)($r->sent_amount??$r->amount),'max_fee'=>(string)$r->max_fee,'confirmations'=>(int)$r->confirmations,'bitcoin_wallet_id'=>$r->bitcoin_wallet_id??null,'sweep'=>$r->purpose==='sweep'];
    }
    public function hotSender(string $chain): string {return $chain==='bitcoin'?app(\App\Services\Wallet\BitcoinWalletManager::class)->sender():trim((string)setting($chain.'.wallet'));}
    private function fundGas(object $r): void {
        DB::transaction(function()use($r) {
            $n=DB::table('custody_networks')->where('chain',$r->chain)->lockForUpdate()->first();
            if (!$n->enabled || bccomp((string)$n->daily_gas_limit,'0',18)<=0) throw new RuntimeException('CUSTODY_GAS_FUNDING_DISABLED');
            if ($gas=DB::table('custody_transfers')->where('key','gas:'.$r->id)->first()) {if($gas->status==='completed')throw new RuntimeException('CUSTODY_GAS_REQUOTE_REQUIRED');if(in_array($gas->status,['failed','cancelled','review'],true))throw new RuntimeException('CUSTODY_GAS_REVIEW');return;}
            $balance=$this->bridge->call($r->chain,'balance',$this->intent($r));
            if (bccomp((string)$balance['balance'],$r->amount,18)<0) return;
            // Only an actual read-only quote can size automatic gas funding; a ceiling is not an estimate.
            $quote=$this->bridge->call($r->chain,'estimate',$this->intent($r));
            if(!isset($quote['fee']) || !is_numeric($quote['fee']) || bccomp((string)$quote['fee'],'0',18)<0 || bccomp((string)$quote['fee'],(string)$r->max_fee,18)>0) throw new RuntimeException('CUSTODY_RESOURCE_ESTIMATE_UNAVAILABLE');
            $needed=bcsub((string)$quote['fee'],(string)($balance['native_balance']??0),18); if (bccomp($needed,'0',18)<=0) return;
            $spent=(string)DB::table('custody_transfers')->where('chain',$r->chain)->where('purpose','gas')->where('created_at','>=',now()->startOfDay())->whereNotIn('status',['failed','cancelled'])->selectRaw('COALESCE(SUM(amount + max_fee),0) as total')->value('total');
            if (bccomp(bcadd(bcadd($spent,$needed,18),CustodyNetwork::feeCap($n),18),(string)$n->daily_gas_limit,18)>0) throw new RuntimeException('CUSTODY_GAS_DAILY_LIMIT');
            $slug=array_search($r->chain,['eth'=>'ethereum','bnb'=>'bnb','matic'=>'polygon','xlayer'=>'xlayer','trx'=>'tron','sol'=>'solana','ton'=>'ton'],true);
            $network=DB::table('networks')->where('slug',$slug)->first();$symbol=['ethereum'=>'ETH','bnb'=>'BNB','polygon'=>'MATIC','xlayer'=>'OKB','tron'=>'TRX','solana'=>'SOL','ton'=>'TON'][$r->chain];
            $currency=DB::table('currencies')->whereIn('symbol',$r->chain==='polygon'?['POL','MATIC']:[$symbol])->whereNull('deleted_at')->first(); if (!$currency || !$network) return;
            $asset=CustodyNetwork::asset($currency->id,$network->id);
            $this->create($asset,['key'=>'gas:'.$r->id,'purpose'=>'gas','parent_id'=>$r->id,'sender'=>trim((string)setting($r->chain.'.wallet')),'destination'=>$r->sender,'amount'=>$needed,'status'=>'approved','approved_by'=>$r->approved_by]);
        });
    }
    private function minimum(array $values): string {usort($values,fn($a,$b)=>bccomp($a,$b,18));return $values[0];}
    private function evm(string $chain): bool {return in_array($chain,['ethereum','bnb','polygon','xlayer'],true);}
    private function sameAddress(string $chain,string $a,string $b): bool {return $this->evm($chain)?strtolower($a)===strtolower($b):$a===$b;}
}
