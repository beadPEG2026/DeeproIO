<?php
namespace Tests\Feature\Deepro;

use Tests\TestCase;
use App\Http\Middleware\WalletRequestReceipt;
use App\Models\Currency\Currency;
use App\Models\Deposit\DepositChannel;
use App\Models\User\User;
use App\Models\Wallet\{Wallet,WalletAddress};
use App\Services\Custody\CustodyService;
use App\Services\Deposit\{DepositReview,DepositRisk,ConfirmationPolicy,DepositChannelConfiguration};
use App\Services\Wallet\{WalletService,WalletReadiness};
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\{Artisan,Cache,DB,Event,Http,Schema};
use Illuminate\Validation\ValidationException;

/** Real middleware/accounting paths on an isolated DB; PG concurrency is covered separately. */
final class WalletReliabilityTest extends TestCase
{
    public function createApplication() { $app=require __DIR__.'/../../../bootstrap/app.php';$app->make(Kernel::class)->bootstrap();return $app; }
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key'=>'base64:'.base64_encode(str_repeat('w',32)),'app.readonly'=>false,'umi-v2.funded_enabled'=>false,
            'database.default'=>'wallet_reliability_memory','database.connections.wallet_reliability_memory'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>'','foreign_key_constraints'=>true],
            'cache.default'=>'array','deposits.evm.ethereum.rpc'=>'https://fixture.invalid','deposits.evm.ethereum.networks'=>[2,3]]);
        DB::purge('wallet_reliability_memory');
        // The financial code uses advisory SQL. These single-process no-ops let SQLite
        // exercise real transactions; this test deliberately does not claim PG lock coverage.
        DB::connection()->getPdo()->sqliteCreateFunction('hashtextextended',fn($text,$seed)=>1,2);
        DB::connection()->getPdo()->sqliteCreateFunction('hashtext',fn($text)=>1,1);
        DB::connection()->getPdo()->sqliteCreateFunction('pg_advisory_xact_lock',fn(...$args)=>1,-1);
        Event::fake();Http::fake();Http::preventStrayRequests();Cache::flush();
        Schema::create('users',function(Blueprint $t){$t->id();$t->boolean('is_xn')->default(false);$t->boolean('deleted')->default(false);$t->boolean('deactivated')->default(false);});
        Schema::create('currencies',function(Blueprint $t){$t->id();$t->string('symbol');$t->string('name')->nullable();$t->boolean('status')->default(true);$t->boolean('deposit_status')->default(true);$t->boolean('withdraw_status')->default(false);$t->string('disabled_deposit_networks')->nullable();$t->string('asset_category')->nullable();$t->text('asset_reference')->nullable();$t->softDeletes();});
        Schema::create('networks',function(Blueprint $t){$t->id();$t->string('slug');$t->string('name');$t->boolean('status')->default(true);$t->boolean('deposit_status')->default(true);});
        Schema::create('currency_networks',function(Blueprint $t){$t->integer('currency_id');$t->integer('network_id');});
        Schema::create('wallets',function(Blueprint $t){$t->id();$t->integer('user_id');$t->integer('currency_id');foreach(['wallet','trade','order','withdraw','lc'] as $b)$t->decimal('balance_in_'.$b,36,18)->default(0);$t->timestamps();});
        Schema::create('wallet_addresses',function(Blueprint $t){$t->id();$t->integer('user_id');$t->integer('wallet_id');$t->integer('network_id');$t->string('address');$t->timestamps();$t->softDeletes();});
        Schema::create('deposits',function(Blueprint $t){$t->id();foreach(['deposit_id','source_id','txn','type','amount','full_amount','network_fee','system_fee','address','status','initial_raw','wallet_transfer_status'] as $s)$t->text($s)->nullable();foreach(['currency_id','network_id','user_id','confirms'] as $n)$t->integer($n)->nullable();$t->timestamps();});
        Schema::create('custody_transfers',function(Blueprint $t){$t->id();$t->string('chain');$t->string('purpose');$t->string('status');$t->integer('deposit_id')->nullable();});
        Schema::create('custody_networks',function(Blueprint $t){$t->id();$t->string('chain');$t->boolean('enabled');$t->boolean('auto_sweep');$t->string('auto_sweep_scope');});
        Schema::create('custody_audits',function(Blueprint $t){$t->id();$t->integer('actor_id')->nullable();$t->string('action');$t->integer('transfer_id')->nullable();$t->text('detail');$t->timestamp('created_at');});
        Schema::create('cold_storage',function(Blueprint $t){$t->id();$t->boolean('status');$t->timestamp('approved_at')->nullable();});
        (require database_path('migrations/2026_09_21_030000_deposit_channels.php'))->up();
        (require database_path('migrations/2026_10_02_221000_wallet_operational_recovery.php'))->up();
        (require database_path('migrations/2026_10_02_221300_deposit_canonical_anchor.php'))->up();
        DB::table('users')->insert([['id'=>1,'deactivated'=>false],['id'=>2,'deactivated'=>true]]);
        DB::table('currencies')->insert(['id'=>1,'symbol'=>'ETH']);
        DB::table('networks')->insert(['id'=>2,'slug'=>'eth','name'=>'Ethereum']);
        DB::table('currency_networks')->insert(['currency_id'=>1,'network_id'=>2]);
        DB::table('wallets')->insert([['id'=>1,'user_id'=>1,'currency_id'=>1,'balance_in_wallet'=>'10'],['id'=>2,'user_id'=>2,'currency_id'=>1,'balance_in_wallet'=>'0']]);
    }
    protected function tearDown(): void { $this->travelBack();DB::disconnect('wallet_reliability_memory');parent::tearDown(); }
    private function request(string $key,string $amount='10'): Request {
        $r=Request::create('/fixture/transfer','POST',['amount'=>$amount,'request_id'=>$key]);
        $r->setUserResolver(fn()=>User::findOrFail(1));$route=(new Route('POST','fixture/transfer',fn()=>null))->name('fixture.wallet.transfer');$r->setRouteResolver(fn()=>$route);return $r;
    }
    private function balance(int $user=1): string {return bcadd((string)DB::table('wallets')->where('user_id',$user)->value('balance_in_wallet'),'0',8);}
    public function test_receipt_replays_before_balance_validation_and_risk_gate_and_rejects_changed_payload(): void
    {
        $handler=app(WalletRequestReceipt::class);$calls=0;
        $next=function($r)use(&$calls){$calls++;app(WalletService::class)->decrease(Wallet::findOrFail(1),$r->amount,'wallet');return response()->json(['receipt_id'=>17]);};
        $first=$handler->handle($this->request('wallet-key-1'),$next);self::assertSame(200,$first->getStatusCode());self::assertSame('0.00000000',$this->balance());
        $id=DB::table('deposits')->insertGetId(['user_id'=>1,'txn'=>'fixture-reorg']);$deposit=DB::table('deposits')->find($id);app(DepositRisk::class)->record($deposit,'ethereum',['canonical'=>false]);
        $again=$handler->handle($this->request('wallet-key-1'),$next);self::assertSame($first->getContent(),$again->getContent());self::assertSame('true',$again->headers->get('Idempotency-Replayed'));self::assertSame(1,$calls);
        self::assertSame(409,$handler->handle($this->request('wallet-key-1','9'),$next)->getStatusCode());
        self::assertSame(1,DB::table('wallet_request_receipts')->count());self::assertSame('0.00000000',$this->balance());
    }
    public function test_validation_failure_does_not_poison_request_key_and_retry_can_succeed(): void
    {
        $handler=app(WalletRequestReceipt::class);$calls=0;
        $next=function($r)use(&$calls){$calls++;if($calls===1)return response()->json(['message'=>'Insufficient balance'],422);app(WalletService::class)->decrease(Wallet::findOrFail(1),$r->amount,'wallet');return response()->json(['ok'=>true]);};
        self::assertSame(422,$handler->handle($this->request('retry-after-validation'),$next)->getStatusCode());self::assertSame(0,DB::table('wallet_request_receipts')->count());
        self::assertSame('10.00000000',$this->balance());self::assertSame(200,$handler->handle($this->request('retry-after-validation'),$next)->getStatusCode());self::assertSame(1,DB::table('wallet_request_receipts')->count());
        self::assertSame(422,$handler->handle($this->request('bad key'),$next)->getStatusCode());self::assertSame(2,$calls);
    }
    public function test_debit_is_atomic_nonnegative_and_wallet_assignment_is_unique(): void
    {
        $s=app(WalletService::class);$w=Wallet::findOrFail(1);$s->decrease($w,'7','wallet');
        try{$s->decrease($w,'4','wallet');self::fail('Overdraft must reject stale model balance');}catch(ValidationException){}
        try{$s->decrease($w,'-1','wallet');self::fail('Negative debit must reject');}catch(\InvalidArgumentException){}
        self::assertSame('3.00000000',$this->balance());
        (require database_path('migrations/2026_10_02_221100_wallet_balance_invariants.php'))->up();
        $s->assignWallet(Currency::findOrFail(1),[User::findOrFail(1),User::findOrFail(1)]);self::assertSame(1,DB::table('wallets')->where('user_id',1)->where('currency_id',1)->count());
        try{DB::table('wallets')->insert(['user_id'=>1,'currency_id'=>1]);self::fail('Duplicate wallet must reject at database boundary');}catch(\Illuminate\Database\QueryException){}
        self::assertSame('3.00000000',$this->balance());
    }
    public function test_insufficient_balance_exception_leaves_no_receipt_and_same_key_retries_after_funding(): void
    {
        $handler=app(WalletRequestReceipt::class);$calls=0;
        $next=function($r)use(&$calls){$calls++;app(WalletService::class)->decrease(Wallet::findOrFail(1),$r->amount,'wallet');return response()->json(['receipt'=>'funded-retry']);};
        try{$handler->handle($this->request('insufficient-retry','20'),$next);self::fail('Insufficient balance must throw before committing a receipt');}catch(ValidationException){}
        self::assertSame(0,DB::table('wallet_request_receipts')->count());self::assertSame('10.00000000',$this->balance());
        app(WalletService::class)->increase(Wallet::findOrFail(1),'10');
        $result=$handler->handle($this->request('insufficient-retry','20'),$next);self::assertSame(200,$result->getStatusCode());
        self::assertSame($result->getContent(),$handler->handle($this->request('insufficient-retry','20'),$next)->getContent());
        self::assertSame(2,$calls);self::assertSame('0.00000000',$this->balance());self::assertSame(1,DB::table('wallet_request_receipts')->count());
    }
    public function test_downstream_failure_rolls_back_debit_and_receipt_together(): void
    {
        try{app(WalletRequestReceipt::class)->handle($this->request('failed-after-debit'),function($r){app(WalletService::class)->decrease(Wallet::findOrFail(1),$r->amount,'wallet');throw new \RuntimeException('FIXTURE_AFTER_DEBIT');});self::fail('Downstream failure must propagate');}
        catch(\RuntimeException $e){self::assertSame('FIXTURE_AFTER_DEBIT',$e->getMessage());}
        self::assertSame('10.00000000',$this->balance());self::assertSame(0,DB::table('wallet_request_receipts')->count());
    }
    private function channel(): DepositChannel {
        $c=DepositChannel::create(['currency_id'=>1,'network_id'=>2,'chain'=>'ethereum','kind'=>'native','contract'=>null,'decimals'=>18,'confirmations'=>12,'minimum'=>'1','fee_fixed'=>'0','fee_percent'=>'0','start_block'=>1,'state'=>'active','acceptance_reference'=>'ISOLATED FIXTURE ONLY']);$c->config_digest=$c->digest();$c->save();return $c;
    }
    private function address(int $user): WalletAddress {
        $a=new WalletAddress();$a->forceFill(['user_id'=>$user,'wallet_id'=>$user,'network_id'=>2,'address'=>'0x'.str_repeat((string)$user,40),'created_at'=>now()->subHour()]);$a->save();return $a;
    }
    private function proof(WalletAddress $a): array {return ['chain'=>'ethereum','txn'=>'0x'.str_repeat((string)$a->user_id,64),'event_index'=>'native','address'=>$a->address,'contract'=>null,'confirmations'=>12,'timestamp'=>now()->subMinute()->getTimestamp()*1000,'raw_amount'=>'1000000000000000000'];}
    public function test_bad_account_is_isolated_good_event_is_credited_and_recovery_credits_once(): void
    {
        $c=$this->channel();$bad=$this->address(2);$good=$this->address(1);$s=app(DepositReview::class);$results=[];
        foreach([$bad,$good] as $a)$results[]=$s->process($c,$a,$this->proof($a));
        self::assertSame('review_required',$results[0]['result']);self::assertSame('credited',$results[1]['result']);self::assertSame('11.00000000',$this->balance());self::assertSame('0.00000000',$this->balance(2));
        self::assertSame(1,DB::table('chain_deposit_receipts')->count());self::assertSame('DEPOSIT_ACCOUNT_UNAVAILABLE',DB::table('deposit_review_events')->value('reason'));
        $s->process($c,$bad,$this->proof($bad));self::assertSame(1,DB::table('deposit_review_events')->count());self::assertSame(2,(int)DB::table('deposit_review_events')->value('attempts'));
        DB::table('users')->where('id',2)->update(['deactivated'=>false]);self::assertSame('credited',$s->process($c,$bad,$this->proof($bad))['result']);self::assertSame('already_processed',$s->process($c,$bad,$this->proof($bad))['result']);
        self::assertSame('1.00000000',$this->balance(2));self::assertSame(2,DB::table('chain_deposit_receipts')->count());self::assertSame('resolved',DB::table('deposit_review_events')->value('status'));
    }
    public function test_deposit_risk_is_idempotent_preserves_ledger_and_reopens_after_a_new_conflict(): void
    {
        $id=DB::table('deposits')->insertGetId(['user_id'=>1,'txn'=>'fixture-post-credit','amount'=>'10','status'=>'confirmed']);$d=DB::table('deposits')->find($id);$risk=app(DepositRisk::class);$before=(array)$d;
        $risk->record($d,'ethereum',['block_hash'=>'old']);$risk->record($d,'ethereum',['block_hash'=>'old']);self::assertSame(1,DB::table('deposit_review_events')->count());
        try{$risk->assertClear(1);self::fail('Open conflict must hold new outflow');}catch(ValidationException){}
        $risk->assertClear(2);DB::table('deposit_review_events')->update(['status'=>'resolved','resolved_at'=>now()]);$risk->assertClear(1);
        $risk->record($d,'ethereum',['block_hash'=>'new']);
        self::assertSame('open',DB::table('deposit_review_events')->value('status'));self::assertNull(DB::table('deposit_review_events')->value('resolved_at'));
        self::assertSame('deposit.risk_reopened',DB::table('custody_audits')->value('action'));
        try{$risk->assertClear(1);self::fail('Recurring conflict must reopen outflow hold');}catch(ValidationException){}
        self::assertSame($before,(array)DB::table('deposits')->find($id));self::assertSame('10.00000000',$this->balance());
    }
    public function test_recovery_evidence_preserves_nested_chain_proof_without_provider_secrets(): void
    {
        $secret='SENSITIVE_FIXTURE_SENTINEL';
        $proof=[
            'chain'=>'ethereum','raw_amount'=>'1000000000000000000','vout'=>0,
            'secret'=>$secret,'provider_metadata'=>['block_hash'=>$secret],
            'first_observed'=>['original_block'=>100,'original_hash'=>'original-public-hash','checked_at'=>'2026-10-03T10:00:00Z','private_key'=>$secret],
            'latest_observed'=>['block'=>101,'observed_hash'=>'current-public-hash','confirmations'=>12,'pkey'=>$secret],
            'conflict'=>['first_observed'=>['block_number'=>100,'block_hash'=>'original-public-hash','unknown'=>$secret]],
            'restored'=>['block_number'=>102,'block_hash'=>'restored-public-hash','amount'=>'1','provider_metadata'=>['secret'=>$secret]],
            // Known scalar fields must not smuggle arbitrary nested provider objects.
            'address'=>['secret'=>$secret],'destination'=>(object)['pkey'=>$secret],
        ];
        $filter=new \ReflectionMethod(\App\Http\Controllers\Web\Admin\WalletRecoveryController::class,'publicEvidence');
        $controller=app(\App\Http\Controllers\Web\Admin\WalletRecoveryController::class);
        $visible=$filter->invoke($controller,$proof);
        self::assertSame('original-public-hash',$visible['first_observed']['original_hash']);
        self::assertSame(100,$visible['first_observed']['original_block']);
        self::assertSame('current-public-hash',$visible['latest_observed']['observed_hash']);
        self::assertSame(101,$visible['latest_observed']['block']);
        self::assertSame('2026-10-03T10:00:00Z',$visible['first_observed']['checked_at']);
        self::assertSame('original-public-hash',$visible['conflict']['first_observed']['block_hash']);
        self::assertSame('restored-public-hash',$visible['restored']['block_hash']);
        self::assertSame('1000000000000000000',$visible['raw_amount']);self::assertSame(0,$visible['vout']);
        self::assertArrayNotHasKey('address',$visible);self::assertArrayNotHasKey('destination',$visible);
        self::assertStringNotContainsString($secret,json_encode($visible));
        $deep=['block_hash'=>'TOO_DEEP_PUBLIC_FIXTURE'];for($i=0;$i<5;$i++)$deep=['conflict'=>$deep];
        self::assertSame([],$filter->invoke($controller,$deep));
    }
    public function test_unverified_proof_is_not_acknowledged_as_an_isolated_account_issue(): void
    {
        $c=$this->channel();$a=$this->address(1);$proof=$this->proof($a);$proof['confirmations']=11;
        try{app(DepositReview::class)->process($c,$a,$proof);self::fail('Insufficient finality must remain a scanner error');}
        catch(\RuntimeException $e){self::assertSame('DEPOSIT_PROOF_MISMATCH',$e->getMessage());}
        self::assertSame(0,DB::table('deposit_review_events')->count());self::assertSame(0,DB::table('chain_deposit_receipts')->count());self::assertSame('10.00000000',$this->balance());
    }
    public function test_readiness_page_reads_only_cached_sample_and_marks_old_sample_stale(): void
    {
        $s=app(WalletReadiness::class);self::assertTrue($s->snapshot()['stale']);
        Cache::put('deepro.wallet-readiness',['checked_at'=>now()->toIso8601String(),'chains'=>[['chain'=>'ethereum','status'=>'awaiting_chain_acceptance']]],600);
        self::assertFalse($s->snapshot()['stale']);$this->travel(4)->minutes();self::assertTrue($s->snapshot()['stale']);Http::assertNothingSent();
    }
    public function test_channel_configuration_rejects_confirmation_floor_before_updating_channel(): void
    {
        $c=$this->channel();$before=(array)DB::table('deposit_channels')->find($c->id);
        self::assertSame(12,ConfirmationPolicy::minimum('ethereum'));self::assertSame(15,ConfirmationPolicy::minimum('bsc'));self::assertSame(6,ConfirmationPolicy::minimum('bitcoin'));self::assertSame(20,ConfirmationPolicy::minimum('tron'));
        try{app(DepositChannelConfiguration::class)->save(['currency_id'=>1,'network_id'=>2,'decimals'=>18,'confirmations'=>1,'minimum'=>'1','fee_fixed'=>'0','fee_percent'=>'0','start_block'=>1,'state'=>'draft'],1);self::fail('Low confirmation must reject');}
        catch(ValidationException $e){self::assertArrayHasKey('confirmations',$e->errors());}
        self::assertSame($before,(array)DB::table('deposit_channels')->find($c->id));Http::assertNothingSent();
    }
    public function test_fair_sweep_queue_skips_cooling_failures_and_reaches_later_deposits(): void
    {
        foreach([101,102,103] as $id)DB::table('deposits')->insert(['id'=>$id,'status'=>DEPOSIT_CONFIRMED,'wallet_transfer_status'=>'review']);
        $visited=[];$mock=\Mockery::mock(CustodyService::class);$mock->shouldReceive('sweep')->times(3)->andReturnUsing(function($id)use(&$visited){$visited[]=(int)$id;if($id<103)throw new \RuntimeException('CUSTODY_VERIFIED_RECEIPT_REQUIRED');DB::table('deposits')->where('id',$id)->update(['wallet_transfer_status'=>'planned']);return (object)['id'=>1];});$this->app->instance(CustodyService::class,$mock);
        for($i=0;$i<3;$i++)self::assertSame(0,Artisan::call('wallets:custody-run',['--limit'=>1]));
        self::assertSame([101,102,103],$visited);self::assertSame('planned',DB::table('deposits')->find(103)->wallet_transfer_status);self::assertNotNull(DB::table('deposits')->find(101)->sweep_retry_at);self::assertSame('CUSTODY_VERIFIED_RECEIPT_REQUIRED',DB::table('deposits')->find(102)->sweep_error);self::assertSame('10.00000000',$this->balance());
    }
    public function test_confirmation_floor_migration_preserves_channel_state_and_audits_old_and_new_values(): void
    {
        $c=$this->channel();$c->confirmations=1;$c->config_digest=$c->digest();$c->save();
        (require database_path('migrations/2026_10_02_221200_deposit_confirmation_floor.php'))->up();
        $c->refresh();self::assertSame(12,$c->confirmations);self::assertSame('active',$c->state);self::assertSame($c->digest(),$c->config_digest);
        $audit=DB::table('deposit_channel_audits')->first();self::assertNotNull($audit);self::assertSame(1,json_decode($audit->before,true)['confirmations']);self::assertSame(12,json_decode($audit->after,true)['confirmations']);
        (require database_path('migrations/2026_10_02_221200_deposit_confirmation_floor.php'))->up();self::assertSame(1,DB::table('deposit_channel_audits')->count());
    }
    public function test_legacy_bitcoin_confirmed_and_removed_receipts_are_rechecked_without_recrediting(): void
    {
        Schema::table('currencies',fn(Blueprint $t)=>$t->integer('min_deposit_confirmation')->default(1));
        Schema::create('bitcoin_wallet_control',function(Blueprint $t){$t->id();$t->integer('active_wallet_id')->nullable();$t->string('legacy_wallet_name')->nullable();});
        DB::table('bitcoin_wallet_control')->insert(['id'=>1,'active_wallet_id'=>null,'legacy_wallet_name'=>'legacy-fixture']);
        DB::table('currencies')->insert(['id'=>2,'symbol'=>'BTC','disabled_deposit_networks'=>'2']);
        DB::table('networks')->insert(['id'=>1,'slug'=>'btc','name'=>'Bitcoin']);DB::table('currency_networks')->insert(['currency_id'=>2,'network_id'=>1]);
        DB::table('wallets')->insert(['id'=>3,'user_id'=>1,'currency_id'=>2,'balance_in_wallet'=>'0.2']);
        DB::table('wallet_addresses')->insert(['user_id'=>1,'wallet_id'=>3,'network_id'=>1,'address'=>'btc-fixture','created_at'=>now()->subDay(),'updated_at'=>now()]);
        $good=str_repeat('a',64);$removed=str_repeat('b',64);
        foreach([$good,$removed] as $txn)DB::table('deposits')->insert(['user_id'=>1,'currency_id'=>2,'network_id'=>1,'txn'=>$txn,'amount'=>'0.1','status'=>DEPOSIT_CONFIRMED]);
        $before=DB::table('deposits')->orderBy('id')->get()->toJson();$walletBefore=DB::table('wallets')->orderBy('id')->get()->toJson();
        config(['bitcoind.default.scheme'=>'http','bitcoind.default.host'=>'btc-fixture.invalid','bitcoind.default.port'=>8332,'bitcoind.default.user'=>'fixture','bitcoind.default.password'=>'fixture']);
        Http::swap(new \Illuminate\Http\Client\Factory());Http::preventStrayRequests();
        Http::fake(['http://btc-fixture.invalid:8332*'=>function($r)use($good,$removed){return Http::response(['result'=>match($r['method']){
            'getblockchaininfo'=>['chain'=>'main','initialblockdownload'=>false,'verificationprogress'=>1,'blocks'=>100],
            'getwalletinfo'=>['walletname'=>'legacy-fixture','scanning'=>false],
            'listsinceblock'=>['transactions'=>[['category'=>'receive','address'=>'btc-fixture','amount'=>0.1,'txid'=>$good]],'removed'=>[['category'=>'receive','txid'=>$removed]],'lastblock'=>str_repeat('c',64)],
            'gettransaction'=>['txid'=>$r['params'][0],'confirmations'=>$r['params'][0]===$removed?-1:12,'walletconflicts'=>$r['params'][0]===$removed?[str_repeat('d',64)]:[]],
            default=>throw new \RuntimeException('UNEXPECTED_FIXTURE_RPC')
        }]);}]);
        $this->mock(\App\Services\PaymentGateways\Coin\Bitcoin\Services\BitcoinService::class)->shouldNotReceive('handleCallback');
        $scanner=app(\App\Services\Deposit\BitcoinWalletScanner::class);
        self::assertSame(2,$scanner->run()['processed']);self::assertSame(2,$scanner->run()['processed']);
        self::assertSame(1,DB::table('deposit_review_events')->count());self::assertSame($removed,DB::table('deposit_review_events')->value('txn'));
        self::assertSame($before,DB::table('deposits')->orderBy('id')->get()->toJson());self::assertSame($walletBefore,DB::table('wallets')->orderBy('id')->get()->toJson());
        Http::assertSent(fn($r)=>$r['method']==='listsinceblock' && $r['params'][1]===6);
        self::assertSame(str_repeat('c',64),Cache::get(\App\Services\Deposit\BitcoinWalletScanner::STATE)['block']);
    }
    public function test_recovery_uses_new_canonical_anchor_and_changed_evidence_cannot_clear_a_hold(): void
    {
        $c=$this->channel();$a=$this->address(1);$proof=$this->proof($a)+['block'=>100,'block_hash'=>'0x'.str_repeat('1',64)];
        app(DepositReview::class)->process($c,$a,$proof);
        $deposit=DB::table('deposits')->first();$receipt=DB::table('chain_deposit_receipts')->first();$originalEvidence=$receipt->evidence;
        app(DepositRisk::class)->record($deposit,'ethereum',['observed_hash'=>'0x'.str_repeat('9',64)]);
        $id=(int)DB::table('deposit_review_events')->value('id');$currentHash='0x'.str_repeat('2',64);$injectNewConflict=false;
        config(['deposits.evm.ethereum.chain_id'=>1,'deposits.evm.ethereum.finality'=>'finalized','deposits.evm.ethereum.rpc_fallbacks'=>[],'deposits.explorer.proxy_fallback'=>false]);
        Http::swap(new \Illuminate\Http\Client\Factory());Http::preventStrayRequests();
        Http::fake(['https://fixture.invalid'=>function($r)use($a,$proof,$deposit,&$currentHash,&$injectNewConflict){
            if($r['method']==='eth_getTransactionByHash' && $injectNewConflict){$injectNewConflict=false;app(DepositRisk::class)->record($deposit,'ethereum',['observed_hash'=>'concurrent-new-evidence']);}
            $result=match($r['method']){
                'eth_chainId'=>'0x1',
                'eth_getTransactionReceipt'=>['transactionHash'=>$proof['txn'],'status'=>'0x1','blockNumber'=>'0x65','blockHash'=>$currentHash],
                'eth_getTransactionByHash'=>['hash'=>$proof['txn'],'blockHash'=>$currentHash,'to'=>$a->address,'from'=>'0x'.str_repeat('f',40),'value'=>'0xde0b6b3a7640000'],
                'eth_getBlockByNumber'=>$r['params'][0]==='finalized'?['number'=>'0xc8']:['number'=>$r['params'][0],'hash'=>$r['params'][0]==='0x64'?'0x'.str_repeat('9',64):$currentHash,'timestamp'=>'0x'.dechex(now()->subMinute()->timestamp)],
                default=>throw new \RuntimeException('UNEXPECTED_FIXTURE_RPC')
            };return Http::response(['jsonrpc'=>'2.0','id'=>1,'result'=>$result]);
        }]);
        $service=app(\App\Services\Deposit\DepositRecovery::class);self::assertSame('resolved',$service->retry($id)['status']);
        $anchor=json_decode(DB::table('chain_deposit_receipts')->value('recheck_anchor'),true);self::assertSame(101,$anchor['block']);self::assertSame($currentHash,$anchor['block_hash']);
        self::assertSame(0,Artisan::call('deepro:recheck-credited-deposits'));self::assertSame('resolved',DB::table('deposit_review_events')->value('status'));
        $currentHash='0x'.str_repeat('3',64);self::assertSame(0,Artisan::call('deepro:recheck-credited-deposits'));self::assertSame('open',DB::table('deposit_review_events')->value('status'));
        $injectNewConflict=true;
        try{$service->retry($id);self::fail('Fresh conflict during proof verification must prevent clearing the hold');}catch(ValidationException $e){self::assertArrayHasKey('event',$e->errors());}
        self::assertSame('open',DB::table('deposit_review_events')->value('status'));self::assertSame($anchor,json_decode(DB::table('chain_deposit_receipts')->value('recheck_anchor'),true));
        self::assertSame($originalEvidence,DB::table('chain_deposit_receipts')->value('evidence'));self::assertSame('11.00000000',$this->balance());self::assertSame(1,DB::table('deposits')->count());
    }
    private function bitcoinThresholdFixture(): string
    {
        Schema::table('currencies',function(Blueprint $t){$t->integer('min_deposit_confirmation')->default(1);$t->string('min_deposit')->default('0');$t->string('alt_symbol')->nullable();$t->string('type')->default('coin');});
        Schema::table('custody_transfers',fn(Blueprint $t)=>$t->string('txn')->nullable());
        Schema::create('bitcoin_wallet_control',function(Blueprint $t){$t->id();$t->integer('active_wallet_id')->nullable();$t->string('legacy_wallet_name')->nullable();});
        Schema::create('bitcoin_deposit_outputs',function(Blueprint $t){$t->id();$t->string('txn');$t->integer('vout');$t->integer('wallet_id');$t->integer('deposit_id');$t->timestamps();$t->unique(['txn','vout']);});
        DB::table('bitcoin_wallet_control')->insert(['id'=>1,'active_wallet_id'=>null,'legacy_wallet_name'=>'fixture']);
        DB::table('currencies')->insert(['id'=>2,'symbol'=>'BTC','disabled_deposit_networks'=>'2']);
        DB::table('networks')->insert(['id'=>1,'slug'=>'btc','name'=>'Bitcoin']);DB::table('currency_networks')->insert(['currency_id'=>2,'network_id'=>1]);
        DB::table('wallets')->insert(['id'=>3,'user_id'=>1,'currency_id'=>2,'balance_in_wallet'=>'0']);
        DB::table('wallet_addresses')->insert(['user_id'=>1,'wallet_id'=>3,'network_id'=>1,'address'=>'btc-floor-fixture','created_at'=>now()->subDay(),'updated_at'=>now()]);
        $txn=str_repeat('e',64);DB::table('deposits')->insert(['user_id'=>1,'currency_id'=>2,'network_id'=>1,'txn'=>$txn,'address'=>'btc-floor-fixture','amount'=>'0.1','system_fee'=>'0','status'=>DEPOSIT_PENDING]);
        return $txn;
    }
    public function test_managed_bitcoin_credit_waits_for_six_even_when_currency_setting_is_one(): void
    {
        $txn=$this->bitcoinThresholdFixture();$confirmations=5;
        // Use a closure by reference so later responses reflect new chain confirmations.
        $rpc=\Mockery::mock(\App\Services\Wallet\BitcoinWalletRpc::class);$rpc->shouldReceive('ready')->andReturn(['walletname'=>'fixture']);
        $rpc->shouldReceive('call')->with('gettransaction',[$txn],'fixture')->andReturnUsing(function()use($txn,&$confirmations){return ['txid'=>$txn,'confirmations'=>$confirmations,'details'=>[['category'=>'receive','vout'=>0,'address'=>'btc-floor-fixture','amount'=>'0.1']]];});
        $this->app->instance(\App\Services\Wallet\BitcoinWalletRpc::class,$rpc);$service=app(\App\Services\Deposit\BitcoinManagedDeposits::class);$wallet=(object)['id'=>1,'name'=>'fixture'];
        self::assertTrue($service->ingest($wallet,$txn));self::assertSame(DEPOSIT_PENDING,DB::table('deposits')->value('status'));self::assertSame(0,bccomp('0',(string)DB::table('wallets')->where('id',3)->value('balance_in_wallet'),8));
        $confirmations=6;self::assertTrue($service->ingest($wallet,$txn));self::assertTrue($service->ingest($wallet,$txn));self::assertSame(DEPOSIT_CONFIRMED,DB::table('deposits')->value('status'));self::assertSame(0,bccomp('0.1',(string)DB::table('wallets')->where('id',3)->value('balance_in_wallet'),8));
        $confirmations=1;$service->ingest($wallet,$txn);self::assertSame('open',DB::table('deposit_review_events')->value('status'));self::assertSame(0,bccomp('0.1',(string)DB::table('wallets')->where('id',3)->value('balance_in_wallet'),8));
    }
    public function test_legacy_bitcoin_callback_waits_for_six_even_when_currency_setting_is_one(): void
    {
        $txn=$this->bitcoinThresholdFixture();$response=new class {public int $confirmations=5;public function hasError(){return false;}public function result(){return ['confirmations'=>$this->confirmations,'details'=>[]];}};
        $factory=\Mockery::mock(\Denpa\Bitcoin\ClientFactory::class);$factory->shouldReceive('wallet')->with('fixture')->andReturnSelf();$factory->shouldReceive('gettransaction')->with($txn)->andReturn($response);$this->app->instance('bitcoind',$factory);
        $original=app('request');$this->app->instance('request',Request::create('/fixture/btc','GET',['txn'=>$txn]));
        try{$service=app(\App\Services\PaymentGateways\Coin\Bitcoin\Services\BitcoinService::class);
            self::assertTrue($service->handleCallback('BTC'));self::assertSame(DEPOSIT_PENDING,DB::table('deposits')->value('status'));self::assertSame(0,bccomp('0',(string)DB::table('wallets')->where('id',3)->value('balance_in_wallet'),8));
            $response->confirmations=6;self::assertTrue($service->handleCallback('BTC'));self::assertTrue($service->handleCallback('BTC'));self::assertSame(DEPOSIT_CONFIRMED,DB::table('deposits')->value('status'));self::assertSame(0,bccomp('0.1',(string)DB::table('wallets')->where('id',3)->value('balance_in_wallet'),8));
        }finally{$this->app->instance('request',$original);}
    }
}
