<?php
namespace App\Services\Wallet;

use App\Services\Custody\CustodyService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BitcoinWalletManager {
    public function __construct(private BitcoinWalletRpc $rpc) {}
    public function active(): ?object {
        $id=DB::table('bitcoin_wallet_control')->where('id',1)->value('active_wallet_id');
        return $id?DB::table('bitcoin_wallets')->find($id):null;
    }
    public function sender(): string {return $this->active()->address??trim((string)setting('bitcoin.wallet'));}
    public function wallet(int $id): object {return DB::table('bitcoin_wallets')->find($id)??throw new RuntimeException('BTC_WALLET_NOT_FOUND');}
    public function connect(string $name,bool $create,int $actor): int {
        if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/D',$name))throw new RuntimeException('BTC_WALLET_NAME_INVALID');
                return $this->locked(function()use($name,$create,$actor){
            if($old=DB::table('bitcoin_wallets')->where('name',$name)->first())return $old->id;
            $control=DB::table('bitcoin_wallet_control')->find(1);
            if($control->legacy_wallet_name===null && !$control->active_wallet_id){
                $legacy=$this->rpc->ready(null,true);
                DB::table('bitcoin_wallet_control')->where('id',1)->update(['legacy_wallet_name'=>$legacy['walletname']]);
            }
            $names=array_column($this->rpc->call('listwalletdir')['wallets']??[],'name');
            if(!in_array($name,$names,true)) {
                if(!$create)throw new RuntimeException('BTC_WALLET_NOT_FOUND');
                // Dedicated descriptor wallet; keys stay inside the private Core service.
                $this->rpc->call('createwallet',[$name,false,false,'',false,true,true]);
            }elseif(!in_array($name,$this->rpc->call('listwallets'),true))$this->rpc->call('loadwallet',[$name,true]);
            $this->rpc->ready($name,true);
            $address=$this->rpc->call('getnewaddress',['deepro-hot-reference','bech32'],$name);
            $id=DB::table('bitcoin_wallets')->insertGetId(['name'=>$name,'address'=>$address,'created_by'=>$actor,'created_at'=>now(),'updated_at'=>now()]);
            app(CustodyService::class)->audit('bitcoin.wallet_connected',['chain'=>'bitcoin','wallet_id'=>$id,'name'=>$name,'created'=>$create],null,$actor);
            return $id;
        });
    }
    public function backup(int $id,int $actor): void {
        $w=$this->wallet($id);$reference='deepro-backup-'.bin2hex(random_bytes(16)).'.dat';
        // Absolute server-configured path is a persistent private Core volume.
        $directory=rtrim((string)config('bitcoind.management_backup_dir'),'/');
        if(!$directory || $directory[0]!=='/')throw new RuntimeException('BTC_BACKUP_DIRECTORY_NOT_CONFIGURED');
        $this->rpc->call('backupwallet',[$directory.'/'.$reference],$w->name);
        DB::table('bitcoin_wallets')->where('id',$id)->update(['backup_at'=>now(),'backup_reference'=>$reference,'updated_at'=>now()]);
        app(CustodyService::class)->audit('bitcoin.backup_created',['chain'=>'bitcoin','wallet_id'=>$id,'reference'=>$reference],null,$actor);
    }
    public function propose(int $id,int $actor): void {
        $this->locked(function()use($id,$actor){
            $w=$this->wallet($id);$this->rpc->ready($w->name,true);
            if(!$w->backup_at)throw new RuntimeException('BTC_BACKUP_REQUIRED');
            $control=DB::table('bitcoin_wallet_control')->find(1);
            // Capture the original default wallet before the first switch, even if different from the target.
            if(!$control->active_wallet_id) {
                $name=$control->legacy_wallet_name;
                if(!DB::table('bitcoin_wallets')->where('name',$name)->exists())throw new RuntimeException('BTC_LEGACY_WALLET_MUST_BE_REGISTERED');
            }
            DB::table('bitcoin_wallet_control')->where('id',1)->update(['proposed_wallet_id'=>$id,'proposed_by'=>$actor,'approved_by'=>null,'revision'=>$control->revision+1,'updated_at'=>now()]);
            app(CustodyService::class)->audit('bitcoin.switch_proposed',['chain'=>'bitcoin','wallet_id'=>$id,'from'=>$control->active_wallet_id,'revision'=>$control->revision+1],null,$actor);
        });
    }
    public function approve(int $revision,int $actor): void {
        $this->locked(function()use($revision,$actor){DB::transaction(function()use($revision,$actor){
            $c=DB::table('bitcoin_wallet_control')->where('id',1)->lockForUpdate()->first();
            if(!$c->proposed_wallet_id || $c->revision!==$revision)throw new RuntimeException('BTC_SWITCH_CHANGED');
            if((int)$c->proposed_by===$actor)throw new RuntimeException('CUSTODY_INDEPENDENT_APPROVAL_REQUIRED');
            $w=$this->wallet($c->proposed_wallet_id);$this->rpc->ready($w->name,true);
            $this->rpc->address($w->address);
            if(($this->rpc->call('getaddressinfo',[$w->address],$w->name)['ismine']??false)!==true)throw new RuntimeException('BTC_ADDRESS_NOT_OWNED');
            $network=DB::table('networks')->where('slug','btc')->value('id');
            if(DB::table('withdrawals')->where('network_id',$network)->whereIn('status',[WITHDRAWAL_CONFIRMED_BY_SYSTEM,WITHDRAWAL_WAITING_PROVIDER_APPROVAL])->exists()
                || DB::table('custody_transfers')->where('chain','bitcoin')->whereIn('status',CustodyService::ACTIVE)->exists())throw new RuntimeException('BTC_ACTIVE_TASKS');
            if(!DB::table('custody_networks')->where('chain','bitcoin')->where('enabled',true)->exists())throw new RuntimeException('CUSTODY_NETWORK_DISABLED');
            DB::table('bitcoin_wallets')->where(function($q)use($c){$q->where('status','active')->orWhere('name',$c->legacy_wallet_name);})->update(['status'=>'draining','updated_at'=>now()]);
            DB::table('bitcoin_wallets')->where('id',$w->id)->update(['status'=>'active','updated_at'=>now()]);
            DB::table('bitcoin_wallet_control')->where('id',1)->update(['active_wallet_id'=>$w->id,'proposed_wallet_id'=>null,'approved_by'=>$actor,'updated_at'=>now()]);
            app(CustodyService::class)->audit('bitcoin.switch_approved',['chain'=>'bitcoin','from'=>$c->active_wallet_id,'to'=>$w->id,'revision'=>$revision],null,$actor);
        });});
    }
    public function locked(callable $fn) {
        if(!DB::selectOne('select pg_try_advisory_lock(hashtext(?)) as locked',['bitcoin:wallet-management'])->locked)throw new RuntimeException('BTC_WALLET_BUSY');
        try{return $fn();}finally{DB::select('select pg_advisory_unlock(hashtext(?))',['bitcoin:wallet-management']);}
    }
}
