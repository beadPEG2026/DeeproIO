<?php
namespace Tests\Feature\Deepro;

use App\Services\Deposit\BitcoinWalletScanner;
use App\Services\PaymentGateways\Coin\Bitcoin\Services\BitcoinService;
use Illuminate\Support\Facades\{Cache, DB, Http};
use Tests\TestCase;

final class BitcoinWalletScannerTest extends TestCase
{
    private int $currencyId;
    private int $networkId;
    private int $userId;
    private string $tx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('deepro_test', DB::connection()->getDatabaseName());
        DB::beginTransaction();
        config(['app.readonly'=>false, 'cache.default'=>'array', 'bitcoind.default.scheme'=>'http', 'bitcoind.default.host'=>'wallet.test', 'bitcoind.default.port'=>8332, 'bitcoind.default.user'=>'wallet-user', 'bitcoind.default.password'=>'test-password']);
        Http::swap(new \Illuminate\Http\Client\Factory); Http::preventStrayRequests();
        Cache::forget(BitcoinWalletScanner::STATE);
        $this->currencyId=DB::table('currencies')->where('symbol','BTC')->value('id');
        $this->networkId=DB::table('networks')->where('slug','btc')->value('id');
        $wallet=DB::table('wallets')->where('currency_id',$this->currencyId)->first();
        $this->userId=$wallet->user_id; $this->tx=str_repeat('a',64);
        DB::table('currencies')->where('id',$this->currencyId)->update(['status'=>true,'deposit_status'=>true,'disabled_deposit_networks'=>'','min_deposit_confirmation'=>1]);
        DB::table('networks')->where('id',$this->networkId)->update(['status'=>true,'deposit_status'=>true]);
        DB::table('currency_networks')->updateOrInsert(['currency_id'=>$this->currencyId,'network_id'=>$this->networkId],[]);
        DB::table('wallet_addresses')->insert(['wallet_id'=>$wallet->id,'user_id'=>$this->userId,'network_id'=>$this->networkId,'address'=>'btc-monitor-test','created_at'=>now(),'updated_at'=>now()]);
    }
    protected function tearDown(): void { while(DB::transactionLevel()>0) DB::rollBack(); parent::tearDown(); }

    private function fake(array $transactions=[], bool $synced=true): void
    {
        Http::fake(['http://wallet.test:8332'=>fn($r)=>Http::response(['result'=>match($r['method']) {
            'getblockchaininfo'=>['chain'=>'main','initialblockdownload'=>!$synced,'verificationprogress'=>$synced?1:0.4,'blocks'=>123],
            'getwalletinfo'=>['walletname'=>'test','scanning'=>false],
            'listsinceblock'=>['transactions'=>$transactions,'lastblock'=>str_repeat('b',64)],
            'gettransaction'=>['txid'=>$this->tx,'confirmations'=>12,'walletconflicts'=>[]],
            default=>throw new \RuntimeException('Unexpected RPC')
        }])]);
    }
    private function receipt():array {return ['category'=>'receive','address'=>'btc-monitor-test','amount'=>0.01,'txid'=>$this->tx];}
    private function pending():void {
        DB::table('deposits')->insert(['user_id'=>$this->userId,'currency_id'=>$this->currencyId,'network_id'=>$this->networkId,'type'=>'coin','deposit_id'=>'btc-qa-'.uniqid(),'txn'=>$this->tx,'address'=>'btc-monitor-test','amount'=>'0.01','status'=>DEPOSIT_PENDING,'created_at'=>now(),'updated_at'=>now()]);
    }
    public function test_discovery_deduplicates_receipts_and_restores_request(): void
    {
        $this->fake([$this->receipt(),$this->receipt(),array_merge($this->receipt(),['category'=>'send']),array_merge($this->receipt(),['address'=>'unassigned','txid'=>str_repeat('c',64)])]);
        $original=app('request');
        $this->mock(BitcoinService::class)->shouldReceive('handleCallback')->once()->with('BTC')->andReturnUsing(function(){ $this->assertSame($this->tx,request('txn'));return true; });
        $r=app(BitcoinWalletScanner::class)->run();
        $this->assertSame(1,$r['processed']);$this->assertSame($original,app('request'));
        $this->assertSame(str_repeat('b',64),Cache::get(BitcoinWalletScanner::STATE)['block']);
        Http::assertSentCount(3);
    }
    public function test_pending_receipts_retry_when_no_new_wallet_transactions(): void
    {
        $this->pending();$this->fake();
        $this->mock(BitcoinService::class)->shouldReceive('handleCallback')->once()->andReturn(true);
        $this->assertSame(1,app(BitcoinWalletScanner::class)->run()['processed']);
    }
    public function test_settled_receipts_are_not_replayed(): void
    {
        $this->pending();DB::table('deposits')->where('txn',$this->tx)->update(['status'=>DEPOSIT_CONFIRMED]);$this->fake([$this->receipt()]);
        $this->mock(BitcoinService::class)->shouldNotReceive('handleCallback');
        $this->assertSame(1,app(BitcoinWalletScanner::class)->run()['processed']);
        $this->assertSame(DEPOSIT_CONFIRMED,DB::table('deposits')->where('txn',$this->tx)->value('status'));
        $this->assertSame(0,DB::table('deposit_review_events')->where('txn',$this->tx)->count());
    }
    public function test_failure_retains_checkpoint_for_retry(): void
    {
        $before=['block'=>str_repeat('c',64)];Cache::forever(BitcoinWalletScanner::STATE,$before);$this->fake([$this->receipt()]);
        $original=app('request');$this->mock(BitcoinService::class)->shouldReceive('handleCallback')->once()->andReturn(false);
        try{app(BitcoinWalletScanner::class)->run();$this->fail('Failure accepted');}catch(\RuntimeException $e){$this->assertSame('BTC_DEPOSIT_CALLBACK_FAILED',$e->getMessage());}
        $this->assertSame($before,Cache::get(BitcoinWalletScanner::STATE));$this->assertSame($original,app('request'));
    }
    public function test_unsynced_wallet_blocks_processing(): void
    {
        $this->fake([],false);$this->mock(BitcoinService::class)->shouldNotReceive('handleCallback');
        $this->expectExceptionMessage('BTC_WALLET_NOT_READY');app(BitcoinWalletScanner::class)->run();
    }
    public function test_dry_run_does_not_mutate_checkpoint_or_call_credit_service(): void
    {
        $this->fake([$this->receipt()]);$this->mock(BitcoinService::class)->shouldNotReceive('handleCallback');
        $this->assertSame(1,app(BitcoinWalletScanner::class)->run(true)['candidates']);$this->assertNull(Cache::get(BitcoinWalletScanner::STATE));
    }
    public function test_disabled_channel_and_overlap_do_not_call_rpc(): void
    {
        DB::table('currencies')->where('id',$this->currencyId)->update(['deposit_status'=>false]);
        $this->assertSame('disabled',app(BitcoinWalletScanner::class)->run()['status']);
        $lock=Cache::lock(BitcoinWalletScanner::STATE.':lock',60);$this->assertTrue($lock->get());
        $this->assertSame('busy',app(BitcoinWalletScanner::class)->run()['status']);$lock->release();Http::assertNothingSent();
    }
    public function test_large_pending_batch_rotates_without_skipping_checkpoint(): void
    {
        $receipts=[];for($i=0;$i<30;$i++)$receipts[]=array_merge($this->receipt(),['txid'=>str_pad(dechex($i+1),64,'0',STR_PAD_LEFT)]);
        $this->fake($receipts);$seen=[];
        $this->mock(BitcoinService::class)->shouldReceive('handleCallback')->times(50)->andReturnUsing(function()use(&$seen){$seen[request('txn')]=true;return true;});
        $this->assertTrue(app(BitcoinWalletScanner::class)->run()['backlog']);
        $this->assertSame('',Cache::get(BitcoinWalletScanner::STATE)['block']);
        app(BitcoinWalletScanner::class)->run();$this->assertCount(30,$seen);
    }
    public function test_rpc_failure_is_redacted_and_does_not_advance_progress(): void
    {
        Http::fake(['*'=>fn()=>throw new \RuntimeException('private-credential-url')]);
        try{app(BitcoinWalletScanner::class)->run();$this->fail('RPC failure accepted');}catch(\RuntimeException $e){$this->assertSame('BTC_WALLET_RPC_UNAVAILABLE',$e->getMessage());}
        $this->assertNull(Cache::get(BitcoinWalletScanner::STATE));
    }
}
