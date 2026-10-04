<?php
namespace Tests\Feature\Deepro;

use App\Models\{User\User,Wallet\Wallet,Withdrawal\Withdrawal};
use App\Services\Wallet\{BitcoinWalletRpc,BitcoinWalletManager};
use App\Services\Custody\{CustodyService,CustodyNetwork,ColdRuleService};
use App\Services\Deposit\BitcoinWalletScanner;
use Illuminate\Support\Facades\{DB,Http,Event,Mail,Queue};
use Tests\TestCase;

/** Opt-in, isolated node only. No external chain credentials or endpoints accepted. */
final class BitcoinCoreRegtestTest extends TestCase
{
    private array $credentials;
    private string $miner;
    private int $currency;
    private int $network;
    protected function setUp():void {
        parent::setUp();
        if(!getenv('BTC_REGTEST_FIXTURE'))$this->markTestSkipped('Isolated Bitcoin Core regtest fixture required');
        $this->assertSame('deepro_test',DB::connection()->getDatabaseName());
        $this->credentials=json_decode(file_get_contents(getenv('BTC_REGTEST_FIXTURE')),true);
        config(['app.readonly'=>false,'cache.default'=>'array','bitcoind.default.host'=>'127.0.0.1','bitcoind.default.port'=>19443,'bitcoind.default.scheme'=>'http','bitcoind.default.user'=>$this->credentials['user'],'bitcoind.default.password'=>$this->credentials['password'],'bitcoind.management_chain'=>'regtest','bitcoind.management_backup_dir'=>'/regtest']);
        $this->assertSame('regtest',$this->fixture('getblockchaininfo')['chain']);
        foreach($this->fixture('listwallets') as $wallet)$this->fixture('unloadwallet',[$wallet]);
        $suffix=bin2hex(random_bytes(4));$this->miner='original_'.$suffix;$this->fixture('createwallet',[$this->miner]);
        $mine=$this->fixture('getnewaddress',[],$this->miner);$this->fixture('generatetoaddress',[101,$mine]);
        DB::beginTransaction();Event::fake();Mail::fake();Queue::fake();
        $this->app->instance(BitcoinWalletRpc::class,new class extends BitcoinWalletRpc {
            public function call(string $method,array $params=[],?string $wallet=null){
                // Private regtest has no public fee market. Signing/selection/send/receipts use real Core RPC.
                return $method==='estimatesmartfee'?['feerate'=>0.00001]:parent::call($method,$params,$wallet);
            }
        });
        DB::table('bitcoin_wallet_control')->where('id',1)->update(['active_wallet_id'=>null,'proposed_wallet_id'=>null,'legacy_wallet_name'=>null,'revision'=>0,'auto_cold'=>true]);
        DB::table('custody_networks')->where('chain','bitcoin')->update(['enabled'=>true,'max_fee'=>'0.0001','native_max_fee'=>'0.0001','confirmations'=>6]);
        $this->currency=DB::table('currencies')->where('symbol','BTC')->value('id');$this->network=DB::table('networks')->where('slug','btc')->value('id');
        DB::table('currencies')->where('id',$this->currency)->update(['status'=>true,'deposit_status'=>true,'withdraw_status'=>true,'disabled_deposit_networks'=>'','disabled_withdrawal_networks'=>'','min_deposit'=>0,'min_deposit_confirmation'=>6]);
        DB::table('networks')->where('id',$this->network)->update(['status'=>true,'deposit_status'=>true,'withdraw_status'=>true]);
    }
    protected function tearDown():void {while(DB::transactionLevel()>0)DB::rollBack();parent::tearDown();}
    private function fixture(string $method,array $params=[],?string $wallet=null){
        $url='http://127.0.0.1:19443'.($wallet!==null?'/wallet/'.rawurlencode($wallet):'');
        $r=Http::withBasicAuth($this->credentials['user'],$this->credentials['password'])->timeout(40)->post($url,['jsonrpc'=>'2.0','id'=>'regtest','method'=>$method,'params'=>$params])->json();
        if(!empty($r['error']))throw new \RuntimeException('Regtest RPC '.$method.' failed: '.($r['error']['code']??0));return $r['result'];
    }
    private function mine():void {$this->fixture('generatetoaddress',[6,$this->fixture('getnewaddress',[],$this->miner)]);}
    private function activate(int $id):void {$m=app(BitcoinWalletManager::class);$m->backup($id,144);$m->propose($id,144);$m->approve(DB::table('bitcoin_wallet_control')->find(1)->revision,152);}
    public function test_real_wallet_switch_backup_recovery_old_deposit_cold_and_withdrawal():void {
        $m=app(BitcoinWalletManager::class);$old=$m->wallet($m->connect($this->miner,false,144));$this->activate($old->id);
        $new=$m->wallet($m->connect('next_'.bin2hex(random_bytes(4)),true,144));$this->activate($new->id);
        $this->assertSame($new->id,$m->active()->id);$this->assertTrue($m->wallet($old->id)->scan_enabled);
        // Restore a Core-created backup and compare the original reference address ownership.
        $backup=$m->wallet($new->id)->backup_reference;$restored='restore_'.bin2hex(random_bytes(4));
        $this->fixture('restorewallet',[$restored,'/regtest/'.$backup]);
        $this->assertTrue($this->fixture('getaddressinfo',[$new->address],$restored)['ismine']);$this->fixture('unloadwallet',[$restored]);
        $u=User::withoutEvents(fn()=>User::factory()->create(['email'=>uniqid('btc-regtest').'@example.invalid','deleted'=>false,'deactivated'=>false,'is_xn'=>false]));
        $account=Wallet::firstOrCreate(['user_id'=>$u->id,'currency_id'=>$this->currency],['balance_in_wallet'=>0,'balance_in_order'=>0,'balance_in_trade'=>0,'balance_in_withdraw'=>0]);
        $oldAddress=$this->fixture('getnewaddress',[],$old->name);$newAddress=$this->fixture('getnewaddress',[],$new->name);
        foreach([$oldAddress,$newAddress] as $address)DB::table('wallet_addresses')->insert(['wallet_id'=>$account->id,'user_id'=>$u->id,'network_id'=>$this->network,'address'=>$address,'created_at'=>now(),'updated_at'=>now()]);
        $this->fixture('sendtoaddress',[$oldAddress,0.25,'','',false,false,null,'unset',false,1],$old->name);
        $this->fixture('sendtoaddress',[$newAddress,0.5,'','',false,false,null,'unset',false,1],$old->name);$this->mine();
        $result=app(BitcoinWalletScanner::class)->run();$this->assertSame('ok',$result['status'],json_encode($result));
        app(BitcoinWalletScanner::class)->run();$this->assertSame(0,bccomp((string)$account->fresh()->balance_in_wallet,'0.75',8));
        $coldWallet='cold_'.bin2hex(random_bytes(4));$this->fixture('createwallet',[$coldWallet]);$destination=$this->fixture('getnewaddress',[],$coldWallet);
        // Existing cold rules and custody executor, with actual inputs, signing and confirmations.
        DB::table('cold_storage')->where('currency_id',$this->currency)->where('network_id',$this->network)->delete();
        $rule=app(ColdRuleService::class)->save(['currency_id'=>$this->currency,'network_id'=>$this->network,'address'=>$destination,'cold_min_balance_amount'=>'0.1','cold_transfer_amount'=>'0.05','hot_reserve'=>'0.1','daily_limit'=>'0.1','status'=>true],144);
        app(ColdRuleService::class)->approve($rule,152);$service=app(CustodyService::class);$t=$service->cold($rule);$this->assertSame('approved',$t->status);
        $service->run($t->id);$r=DB::table('custody_transfers')->find($t->id);$this->assertSame('confirming',$r->status,$r->last_error??'');
        $this->assertNotNull($r->signed_payload);$this->mine();$service->run($t->id);$service->run($t->id);$this->assertSame('completed',DB::table('custody_transfers')->find($t->id)->status);
        $this->assertSame(0,bccomp((string)$this->fixture('getbalances',[],$coldWallet)['mine']['trusted'],'0.05',8));
        $account->refresh();$account->balance_in_withdraw='0.02';$account->save();
        $w=Withdrawal::create(['withdrawal_id'=>uniqid('btc-regtest'),'user_id'=>$u->id,'currency_id'=>$this->currency,'network_id'=>$this->network,'amount'=>'0.02','fee'=>'0.001','address'=>$destination,'type'=>'coin','status'=>WITHDRAWAL_WAITING_PROVIDER_APPROVAL,'fund_origin'=>'user']);
        $queued=$service->withdrawal($w);$w->source_id=$queued['source'];$w->save();$id=(int)substr($queued['source'],8);
        $service->run($id);$r=DB::table('custody_transfers')->find($id);$this->assertSame('confirming',$r->status,$r->last_error??'');$this->mine();$service->run($id);$service->run($id);
        $this->assertSame('completed',DB::table('custody_transfers')->find($id)->status);$this->assertSame(WITHDRAWAL_CONFIRMED_BY_PROVIDER,$w->fresh()->status);$this->assertSame(0,bccomp((string)$account->fresh()->balance_in_withdraw,'0',8));
        $this->assertSame(0,bccomp((string)$this->fixture('getbalances',[],$coldWallet)['mine']['trusted'],'0.069',8));
        // Migration retains the old signer and does not create customer credit for a platform transfer.
        $t=$service->create(CustodyNetwork::asset($this->currency,$this->network),['key'=>uniqid('migration'),'purpose'=>'migration','bitcoin_wallet_id'=>$old->id,'sender'=>$old->address,'destination'=>$new->address,'amount'=>'0.03','requested_by'=>144]);
        $service->approve($t->id,152);$service->run($t->id);$this->assertSame('confirming',DB::table('custody_transfers')->find($t->id)->status);$this->mine();$service->run($t->id);$this->assertSame('completed',DB::table('custody_transfers')->find($t->id)->status);
        app(BitcoinWalletScanner::class)->run();$this->assertSame(0,bccomp((string)$account->fresh()->balance_in_wallet,'0.75',8));
    }
}
