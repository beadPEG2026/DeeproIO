<?php
namespace Tests\Feature\Deepro;

use App\Models\{User\User,Wallet\Wallet};
use App\Services\Custody\{CustodyService,CustodyNetwork};
use App\Services\Deposit\{BitcoinManagedDeposits,BitcoinWalletScanner};
use App\Services\Wallet\{BitcoinWalletManager,BitcoinWalletRpc};
use Illuminate\Support\Facades\{DB,Http,Cache,Event,Mail,Queue};
use Tests\TestCase;

final class BitcoinWalletManagementTest extends TestCase
{
    private array $responses=[];
    private array $calls=[];
    private int $currency;
    private int $network;
    private object $old;
    private object $new;
    protected function setUp():void {
        parent::setUp();$this->assertSame('deepro_test',DB::connection()->getDatabaseName());DB::beginTransaction();
        config(['app.readonly'=>false,'cache.default'=>'array','session.driver'=>'array','bitcoind.default.host'=>'bitcoin.test','bitcoind.default.port'=>8332,'bitcoind.default.scheme'=>'http','bitcoind.management_backup_dir'=>'/bitcoin-data']);
        Event::fake();Mail::fake();Queue::fake();Http::swap(new \Illuminate\Http\Client\Factory);Http::preventStrayRequests();
        DB::table('bitcoin_wallet_control')->where('id',1)->update(['active_wallet_id'=>null,'proposed_wallet_id'=>null,'legacy_wallet_name'=>null,'revision'=>0,'auto_cold'=>false]);
        $this->currency=DB::table('currencies')->where('symbol','BTC')->value('id');$this->network=DB::table('networks')->where('slug','btc')->value('id');
        DB::table('currencies')->where('id',$this->currency)->update(['status'=>true,'deposit_status'=>true,'disabled_deposit_networks'=>'','min_deposit'=>0,'min_deposit_confirmation'=>6]);
        DB::table('networks')->where('id',$this->network)->update(['status'=>true,'deposit_status'=>true]);
        DB::table('currency_networks')->updateOrInsert(['currency_id'=>$this->currency,'network_id'=>$this->network],[]);
        DB::table('custody_networks')->where('chain','bitcoin')->update(['enabled'=>true,'native_max_fee'=>'0.0001','max_fee'=>'0.0001']);
        $this->responses=['getblockchaininfo'=>['chain'=>'main','initialblockdownload'=>false,'verificationprogress'=>1], 'getwalletinfo'=>['walletname'=>'original','scanning'=>false,'private_keys_enabled'=>true], 'listwalletdir'=>['wallets'=>[['name'=>'original'],['name'=>'replacement']]],'listwallets'=>['original','replacement'], 'getaddressinfo'=>['ismine'=>true], 'validateaddress'=>['isvalid'=>true], 'backupwallet'=>null,'getbalances'=>['mine'=>['trusted'=>10]]];
        Http::fake(['http://bitcoin.test:8332*'=>function($r){$this->calls[]=[$r['method'],$r->url(),$r['params']];
            if($r['method']==='getnewaddress')$v=str_contains($r->url(),'replacement')?'new-hot':'old-hot';
            elseif(array_key_exists($r['method'],$this->responses))$v=$this->responses[$r['method']];else throw new \RuntimeException('Unexpected RPC: '.$r['method']);
            return Http::response(['result'=>$v]);
        }]);
        $manager=app(BitcoinWalletManager::class);
        $this->old=$manager->wallet($manager->connect('original',false,144));
        $this->new=$manager->wallet($manager->connect('replacement',false,144));
    }
    protected function tearDown():void {while(DB::transactionLevel()>0)DB::rollBack();parent::tearDown();}
    private function switchTo(object $w,int $actor=144):void {app(BitcoinWalletManager::class)->backup($w->id,$actor);app(BitcoinWalletManager::class)->propose($w->id,$actor);}
    private function active():void {DB::table('bitcoin_wallet_control')->where('id',1)->update(['active_wallet_id'=>$this->new->id]);}
    private function account():Wallet {
        $u=User::withoutEvents(fn()=>User::factory()->create(['email'=>uniqid('btc').'@example.invalid','deleted'=>false,'deactivated'=>false,'is_xn'=>false]));
        $w=Wallet::firstOrCreate(['user_id'=>$u->id,'currency_id'=>$this->currency],['balance_in_wallet'=>0,'balance_in_trade'=>0,'balance_in_order'=>0,'balance_in_withdraw'=>0]);
        DB::table('wallet_addresses')->insert(['wallet_id'=>$w->id,'user_id'=>$u->id,'network_id'=>$this->network,'address'=>'deposit-address','created_at'=>now(),'updated_at'=>now()]);return $w;
    }
    private function receipt(array $overrides=[]):string {
        $txn=str_repeat('a',64);$this->responses['gettransaction']=array_replace(['txid'=>$txn,'confirmations'=>6,'details'=>[['category'=>'receive','address'=>'deposit-address','amount'=>0.1,'vout'=>0],['category'=>'receive','address'=>'deposit-address','amount'=>0.2,'vout'=>1]]],$overrides);return $txn;
    }
    public function test_capture_original_before_multiwallet_and_backup_required():void {
        $this->assertSame('original',DB::table('bitcoin_wallet_control')->find(1)->legacy_wallet_name);
        $this->assertSame('http://bitcoin.test:8332',$this->calls[1][1]);
        $this->expectExceptionMessage('BTC_BACKUP_REQUIRED');app(BitcoinWalletManager::class)->propose($this->new->id,144);
    }
    public function test_proposer_cannot_approve_switch():void {
        $this->switchTo($this->new);$this->expectExceptionMessage('CUSTODY_INDEPENDENT_APPROVAL_REQUIRED');app(BitcoinWalletManager::class)->approve(1,144);
    }
    public function test_stale_review_cannot_switch_a_changed_proposal():void {
        $this->switchTo($this->new);$this->switchTo($this->old);$this->expectExceptionMessage('BTC_SWITCH_CHANGED');app(BitcoinWalletManager::class)->approve(1,152);
    }
    public function test_approved_switch_keeps_original_scanned_and_audit():void {
        $this->switchTo($this->new);app(BitcoinWalletManager::class)->approve(1,152);
        $this->assertSame($this->new->id,app(BitcoinWalletManager::class)->active()->id);
        $old=DB::table('bitcoin_wallets')->find($this->old->id);$this->assertSame('draining',$old->status);$this->assertTrue($old->scan_enabled);
        $this->assertDatabaseHas('custody_audits',['action'=>'bitcoin.switch_approved','actor_id'=>152]);
    }
    public function test_pending_transfers_block_switch():void {
        $this->active();$this->switchTo($this->old);$this->task();$this->expectExceptionMessage('BTC_ACTIVE_TASKS');app(BitcoinWalletManager::class)->approve(1,152);
    }
    public function test_disabled_channel_cannot_activate_wallet():void {
        $this->switchTo($this->new);DB::table('custody_networks')->where('chain','bitcoin')->update(['enabled'=>false]);
        $this->expectExceptionMessage('CUSTODY_NETWORK_DISABLED');app(BitcoinWalletManager::class)->approve(1,152);
    }
    public function test_wallet_management_requires_role_and_recent_2fa():void {
        $u=User::withoutEvents(fn()=>User::factory()->create(['email'=>uniqid('btc-role').'@example.invalid','deleted'=>false,'deactivated'=>false,'is_leng'=>1]));
        $this->actingAs($u)->postJson('/exchange-control-panel/custody/bitcoin/connect',['name'=>'replacement','create'=>false])->assertForbidden();
        $u->assignRole('superadmin');$this->actingAs($u)->postJson('/exchange-control-panel/custody/bitcoin/connect',['name'=>'replacement','create'=>false])->assertStatus(422)->assertJsonValidationErrors('custody');
    }
    public function test_multiple_outputs_and_multiple_wallet_scans_credit_once():void {
        $w=$this->account();$txn=$this->receipt();$ingest=app(BitcoinManagedDeposits::class);
        $ingest->ingest($this->old,$txn);$ingest->ingest($this->new,$txn);$ingest->ingest($this->old,$txn);
        $this->assertSame(2,DB::table('bitcoin_deposit_outputs')->where('txn',$txn)->count());
        $this->assertSame(0,bccomp((string)$w->fresh()->balance_in_wallet,'0.3',8));
    }
    public function test_unconfirmed_receipt_waits_and_conflicting_receipt_is_rejected():void {
        $w=$this->account();$txn=$this->receipt(['confirmations'=>5]);app(BitcoinManagedDeposits::class)->ingest($this->old,$txn);
        $this->assertSame(0,bccomp((string)$w->fresh()->balance_in_wallet,'0',8));
        $ledgerCount=DB::table('wallet_balance_logs')->where('wallet_id',$w->id)->count();
        $this->receipt(['confirmations'=>-1]);$this->assertFalse(app(BitcoinManagedDeposits::class)->ingest($this->old,$txn));
        $this->assertSame(0,bccomp((string)$w->fresh()->balance_in_wallet,'0',8));
        $this->assertSame(0,DB::table('deposits')->where('txn',$txn)->where('status',DEPOSIT_CONFIRMED)->count());
        $this->assertSame($ledgerCount,DB::table('wallet_balance_logs')->where('wallet_id',$w->id)->count());
        app(\App\Services\Deposit\DepositRisk::class)->assertClear($w->user_id);
    }
    public function test_unmapped_legacy_aggregate_cannot_double_credit():void {
        $w=$this->account();$txn=$this->receipt();DB::table('deposits')->insert(['deposit_id'=>uniqid('legacy'),'txn'=>$txn,'address'=>'deposit-address','user_id'=>$w->user_id,'currency_id'=>$this->currency,'network_id'=>$this->network,'amount'=>'0.3','status'=>DEPOSIT_CONFIRMED,'type'=>'coin','created_at'=>now(),'updated_at'=>now()]);
        $this->expectExceptionMessage('BTC_LEGACY_DEPOSIT_REVIEW');app(BitcoinManagedDeposits::class)->ingest($this->old,$txn);
    }
    public function test_original_and_new_wallets_are_scanned_after_switch():void {
        $this->active();$this->responses['listsinceblock']=['transactions'=>[],'lastblock'=>str_repeat('b',64)];
        app(BitcoinWalletScanner::class)->run();
        $urls=array_column(array_filter($this->calls,fn($c)=>$c[0]==='listsinceblock'),1);
        $this->assertContains('http://bitcoin.test:8332/wallet/original',$urls);$this->assertContains('http://bitcoin.test:8332/wallet/replacement',$urls);
        $this->assertNotNull(Cache::get(BitcoinWalletScanner::STATE.':wallet:'.$this->old->id));
    }
    public function test_btc_cold_rule_rejects_sub_satoshi_amounts():void {
        $request=\App\Http\Requests\Web\ColdStorage\ColdStorageFormRequest::create('/', 'POST', ['status'=>false,'currency_id'=>$this->currency,'network_id'=>$this->network,'address'=>'cold-destination','cold_min_balance_amount'=>'0.1','cold_transfer_amount'=>'0.000000001','hot_reserve'=>'0.01','daily_limit'=>'0.2']);
        $validator=\Illuminate\Support\Facades\Validator::make($request->all(),$request->rules());$request->withValidator($validator);
        $this->assertTrue($validator->fails());$this->assertTrue($validator->errors()->has('cold_transfer_amount'));
    }
    public function test_confirmed_deposit_losing_confirmations_is_reviewed_without_recredit():void {
        $w=$this->account();$txn=$this->receipt();app(BitcoinManagedDeposits::class)->ingest($this->old,$txn);
        $ledgerCount=DB::table('wallet_balance_logs')->where('wallet_id',$w->id)->count();
        $this->receipt(['confirmations'=>1]);
        $this->assertTrue(app(BitcoinManagedDeposits::class)->ingest($this->old,$txn));
        $this->assertTrue(app(BitcoinManagedDeposits::class)->ingest($this->old,$txn));
        $this->assertSame(0,bccomp((string)$w->fresh()->balance_in_wallet,'0.3',8));
        $this->assertSame(2,DB::table('bitcoin_deposit_outputs')->where('txn',$txn)->count());
        $this->assertSame(2,DB::table('deposits')->where('txn',$txn)->where('status',DEPOSIT_CONFIRMED)->count());
        $this->assertSame($ledgerCount,DB::table('wallet_balance_logs')->where('wallet_id',$w->id)->count());
        $this->assertSame(2,DB::table('deposit_review_events')->where('txn',$txn)->where('reason','DEPOSIT_POST_CREDIT_CHAIN_CONFLICT')->where('status','open')->count());
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(\App\Services\Deposit\DepositRisk::class)->assertClear($w->user_id);
    }
    private function task():object {
        return app(CustodyService::class)->create(CustodyNetwork::asset($this->currency,$this->network),['key'=>uniqid('btc-test'),'purpose'=>'cold','sender'=>$this->new->address,'destination'=>'cold-destination','amount'=>'0.1','status'=>'approved']);
    }
    private function psbt(array $changes=[]):void {
        $this->responses=array_replace($this->responses,[
            'estimatesmartfee'=>['feerate'=>0.00001],
            'listunspent'=>[['txid'=>str_repeat('c',64),'vout'=>0,'amount'=>1,'safe'=>true,'spendable'=>true]],
            'walletcreatefundedpsbt'=>['psbt'=>'psbt-fixture','fee'=>0.000001],
            'walletprocesspsbt'=>['psbt'=>'signed-psbt'],'finalizepsbt'=>['complete'=>true,'hex'=>'aabb'],
            'decoderawtransaction'=>['txid'=>str_repeat('d',64),'vin'=>[['txid'=>str_repeat('c',64),'vout'=>0]],'vout'=>[['value'=>0.1,'scriptPubKey'=>['address'=>'cold-destination']],['value'=>0.899999,'scriptPubKey'=>['address'=>'owned-change']]]],
            'sendrawtransaction'=>str_repeat('d',64),
        ],$changes);
    }
    public function test_prepare_persists_signature_before_send_and_reserves_inputs():void {
        $this->active();$this->psbt();$t=$this->task();app(CustodyService::class)->run($t->id);$t=DB::table('custody_transfers')->find($t->id);
        $this->assertSame('confirming',$t->status,$t->last_error??'');$this->assertNotNull($t->signed_payload);
        $this->assertDatabaseHas('bitcoin_utxo_reservations',['transfer_id'=>$t->id,'txn'=>str_repeat('c',64)]);
        $this->responses['gettransaction']=['confirmations'=>0];DB::table('custody_transfers')->where('id',$t->id)->update(['broadcast_at'=>now()->subMinutes(1)]);
        app(CustodyService::class)->run($t->id);
        $this->assertCount(1,array_filter($this->calls,fn($c)=>$c[0]==='walletprocesspsbt'));
        $sends=array_values(array_filter($this->calls,fn($c)=>$c[0]==='sendrawtransaction'));$this->assertCount(2,$sends);$this->assertSame($sends[0][2],$sends[1][2]);
        $second=$this->task();app(CustodyService::class)->run($second->id);$this->assertSame('CUSTODY_INSUFFICIENT_GAS_OR_BALANCE',DB::table('custody_transfers')->find($second->id)->last_error);
    }
    public function test_excess_fee_or_foreign_change_never_broadcast():void {
        $this->active();$this->psbt(['walletcreatefundedpsbt'=>['psbt'=>'p','fee'=>0.1]]);$t=$this->task();app(CustodyService::class)->run($t->id);
        $this->assertSame('CUSTODY_FEE_LIMIT',DB::table('custody_transfers')->find($t->id)->last_error);
        $this->psbt(['getaddressinfo'=>['ismine'=>false]]);app(CustodyService::class)->run($t->id);
        $this->assertSame('BTC_CHANGE_NOT_OWNED',DB::table('custody_transfers')->find($t->id)->last_error);
        $this->assertCount(0,array_filter($this->calls,fn($c)=>$c[0]==='sendrawtransaction'));
        $this->assertSame(0,DB::table('bitcoin_utxo_reservations')->where('transfer_id',$t->id)->count());
    }
}
