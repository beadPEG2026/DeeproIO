<?php

namespace Tests\Feature\Deepro;

use App\Http\Controllers\Api\v1\WalletController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Http, Mail};
use Tests\TestCase;

final class DepositNetworkOptionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('deepro_test', DB::connection()->getDatabaseName());
        config(['cache.default' => 'array']);
        Http::fake(['*' => Http::response([], 503)]);
        Mail::fake();
        DB::beginTransaction();
        if (!\Illuminate\Support\Facades\Schema::hasColumn('chain_deposit_scan_states','realtime_from')) (require database_path('migrations/2026_09_24_100000_evm_scan_lanes.php'))->up();
        if (!\Illuminate\Support\Facades\Schema::hasColumn('chain_deposit_scan_states','realtime_last_success_at')) (require database_path('migrations/2026_09_24_120000_evm_live_health.php'))->up();
        if(!\Illuminate\Support\Facades\Schema::hasTable('deposit_channels'))(require database_path('migrations/2026_09_21_030000_deposit_channels.php'))->up();
        config(['deposits.evm.bsc.rpc'=>'https://fixture.invalid']);
        $c=\App\Models\Deposit\DepositChannel::updateOrCreate(['currency_id'=>2,'network_id'=>6],['chain'=>'bsc','kind'=>'token','contract'=>'0x'.str_repeat('a',40),'decimals'=>18,'confirmations'=>20,'minimum'=>30,'fee_fixed'=>0,'fee_percent'=>0,'start_block'=>100,'state'=>'active','acceptance_reference'=>'ISOLATED FIXTURE ONLY']);
        DB::table('currencies')->where('id',2)->update(['bep_contract'=>$c->contract]);
        $c->refresh();$c->config_digest=$c->digest();$c->save();
        DB::table('chain_deposit_scan_states')->updateOrInsert(['chain'=>'bsc','scope'=>'channel:'.$c->id],['last_success_at'=>now(),'last_error'=>null]);
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) DB::rollBack();
        parent::tearDown();
    }

    private function networkOptions(?string $purpose): array
    {
        $request = Request::create('/api/v1/wallets/deposit/networks', 'GET', array_filter([
            'symbol' => 'USDT', 'purpose' => $purpose,
        ]));
        return app(WalletController::class)->loadNetworks($request)->getData(true);
    }

    public function test_deposit_options_exclude_disabled_networks_without_changing_withdrawal_options(): void
    {
        DB::table('networks')->update(['deposit_status' => false]);
        DB::table('networks')->where('id', 6)->update(['deposit_status' => true]);
        DB::table('currencies')->where('symbol', 'USDT')->update([
            'deposit_status' => true, 'disabled_deposit_networks' => '',
        ]);
        $this->assertSame([6], array_keys($this->networkOptions('deposit')));
        $all = $this->networkOptions(null);
        $this->assertArrayHasKey(3, $all);
        $this->assertArrayHasKey(6, $all);
    }

    public function test_currency_network_override_prevents_single_network_auto_selection(): void
    {
        DB::table('networks')->update(['deposit_status' => false]);
        DB::table('networks')->where('id', 6)->update(['deposit_status' => true]);
        DB::table('currencies')->where('symbol', 'USDT')->update([
            'deposit_status' => true, 'disabled_deposit_networks' => '6',
        ]);
        $this->assertSame([], $this->networkOptions('deposit'));
    }

    public function test_disabled_currency_never_offers_an_automatic_deposit_network(): void
    {
        DB::table('networks')->update(['deposit_status' => true]);
        DB::table('currencies')->where('symbol', 'USDT')->update(['deposit_status' => false]);
        $this->assertSame([], $this->networkOptions('deposit'));
    }
}
