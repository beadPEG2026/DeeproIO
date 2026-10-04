<?php

namespace Tests\Feature\Deepro;

use App\Services\Wallet\{BitcoinChannelReadiness, BitcoinWalletManager};
use Illuminate\Support\Facades\{DB, Http};
use Tests\TestCase;

final class BitcoinChannelReadinessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('deepro_test', DB::connection()->getDatabaseName());
        DB::beginTransaction();
        config(['app.readonly'=>false, 'bitcoind.management_chain'=>'main', 'bitcoind.default.quicknode'=>'',
            'bitcoind.default.scheme'=>'http', 'bitcoind.default.host'=>'wallet.invalid', 'bitcoind.default.port'=>8332]);
        DB::table('bitcoin_wallet_control')->where('id', 1)->update(['active_wallet_id'=>null,'legacy_wallet_name'=>null]);
        $this->mock(BitcoinWalletManager::class, function ($mock) {
            $mock->shouldReceive('active')->andReturn(null);
            $mock->shouldReceive('sender')->andReturn('test-btc-reference');
            $mock->shouldNotReceive('connect');
            $mock->shouldNotReceive('approve');
        });
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) DB::rollBack();
        parent::tearDown();
    }

    private function fake(array $walletChanges=[], bool $owned=true): void
    {
        Http::fake(['*'=>function ($request) use ($walletChanges, $owned) {
            $result = match ($request['method']) {
                'getblockchaininfo'=>['chain'=>'main','blocks'=>900000,'initialblockdownload'=>false,'verificationprogress'=>1],
                'getwalletinfo'=>array_merge(['walletname'=>'existing','private_keys_enabled'=>true,'scanning'=>false],$walletChanges),
                'getaddressinfo'=>['address'=>'test-btc-reference','ismine'=>$owned,'iswatchonly'=>false],
                'getbalances'=>['mine'=>['trusted'=>0]],
                default=>throw new \RuntimeException('unexpected method'),
            };
            return Http::response(['result'=>$result]);
        }]);
    }

    public function test_existing_wallet_may_open_for_deposits_without_inventing_liquidity_or_switching_wallets(): void
    {
        $this->fake();
        $result=app(BitcoinChannelReadiness::class)->assertReady();
        $this->assertTrue($result['ready']);
        $this->assertSame('existing_core_wallet',$result['mode']);
        $this->assertFalse($result['wallet_has_confirmed_funds']);
        $this->assertFalse($result['financial_transactions_executed']);
        Http::assertNotSent(fn ($q)=>in_array($q['method'],['sendtoaddress','sendrawtransaction','getnewaddress','walletprocesspsbt']));
        $this->assertStringNotContainsString('test-btc-reference',json_encode($result));
    }

    public function test_rescanning_or_locked_wallet_cannot_enable_channel(): void
    {
        foreach ([['scanning'=>['progress'=>0.5]],['unlocked_until'=>0]] as $state) {
            Http::swap(new \Illuminate\Http\Client\Factory);$this->fake($state);
            $this->assertFalse(app(BitcoinChannelReadiness::class)->inspect()['ready']);
        }
    }

    public function test_reference_address_must_belong_to_current_private_wallet(): void
    {
        $this->fake([],false);
        $this->expectExceptionMessage('BTC_CHANNEL_NOT_READY');
        app(BitcoinChannelReadiness::class)->assertReady();
    }

    public function test_readonly_application_and_rpc_failure_remain_closed_and_redacted(): void
    {
        config(['app.readonly'=>true]);
        Http::fake(['*'=>fn()=>throw new \RuntimeException('private-credential-url')]);
        $result=app(BitcoinChannelReadiness::class)->inspect();
        $this->assertFalse($result['ready']);
        $this->assertStringNotContainsString('private-credential',json_encode($result));
    }

    public function test_dry_run_is_not_ready_when_any_managed_wallet_failed_or_no_wallet_is_scanned(): void
    {
        $this->assertTrue(BitcoinChannelReadiness::scannerReady(['status'=>'dry_run','candidates'=>0]));
        $this->assertTrue(BitcoinChannelReadiness::scannerReady(['status'=>'dry_run','wallets'=>[['status'=>'ok']]]));
        $this->assertFalse(BitcoinChannelReadiness::scannerReady(['status'=>'dry_run','wallets'=>[['status'=>'review']]]));
        $this->assertFalse(BitcoinChannelReadiness::scannerReady(['status'=>'dry_run','wallets'=>[]]));
        $this->assertFalse(BitcoinChannelReadiness::scannerReady(['status'=>'disabled']));
    }
}
