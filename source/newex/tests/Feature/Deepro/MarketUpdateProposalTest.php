<?php

namespace Tests\Feature\Deepro;

use App\Http\Controllers\Web\Admin\MarketController;
use App\Models\Market\Market;
use App\Models\User\User;
use Illuminate\Support\Facades\{Cache, DB, Event, File, Http, Mail, Queue};
use Illuminate\Support\Str;
use Tests\Fixtures\MarketUpdateProposalController;
use Tests\TestCase;

final class MarketUpdateProposalTest extends TestCase
{
    private string $originalStorage;
    private string $isolatedStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('deepro_test', DB::connection()->getDatabaseName());
        config(['app.readonly' => false, 'cache.default' => 'array', 'session.driver' => 'array',
            'broadcasting.default' => 'log', 'admin-controls.simulation_controls' => false]);
        $this->originalStorage = $this->app->storagePath();
        $this->isolatedStorage = sys_get_temp_dir().'/deepro-market-proposal-'.Str::uuid();
        mkdir($this->isolatedStorage, 0700, true);
        $this->app->useStoragePath($this->isolatedStorage);
        $this->travelTo(now()->startOfMinute()->addSeconds(30));
        DB::beginTransaction();
        Event::fake(); Mail::fake(); Queue::fake(); Http::preventStrayRequests();
        $admin = User::withoutEvents(fn () => User::factory()->create([
            'email' => 'proposal-'.Str::uuid().'@example.invalid', 'deleted' => false, 'deactivated' => false,
        ]));
        $admin->assignRole('superadmin');
        $this->actingAs($admin);
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) DB::rollBack();
        $this->app->useStoragePath($this->originalStorage);
        File::deleteDirectory($this->isolatedStorage);
        $this->travelBack();
        parent::tearDown();
    }

    private function useProposal(): void
    {
        $this->app->bind(MarketController::class, MarketUpdateProposalController::class);
    }

    private function fixture(float $floor = 0): Market
    {
        $market = Market::where('name', 'BTC-USDT')->firstOrFail();
        $market->forceFill(['bs' => '0.1', 'last' => '100', 'bot_price_floor' => $floor,
            'bot_price_ceiling' => 0, 'custom_liquidity_t' => false, 'switch_chart' => false])->save();
        market_set_stats_force($market->id, 'last', 100);
        return $market;
    }

    private function payload(Market $market): array
    {
        return [
            'id' => $market->id, 'name' => $market->name,
            'base_currency_id' => $market->base_currency_id, 'quote_currency_id' => $market->quote_currency_id,
            'base_precision' => 8, 'quote_precision' => 8, 'base_ticker_size' => '0.00000001', 'quote_ticker_size' => '0.00000001',
            'min_trade_size' => '0.00000001', 'max_trade_size' => '1000000', 'min_trade_value' => '0.00000001', 'max_trade_value' => '1000000',
            'status' => true, 'trade_status' => true, 'buy_order_status' => true, 'sell_order_status' => true, 'cancel_order_status' => true,
            'discount' => 0, 'discount_bid' => 0, 'bs' => '0.5',
        ];
    }

    public function test_proposed_method_still_rejects_zero_limits_before_saving_bs(): void
    {
        $this->useProposal();
        $market = $this->fixture();
        $zeroLimits = array_fill_keys(['min_trade_size', 'max_trade_size', 'min_trade_value', 'max_trade_value'], '0');
        $this->putJson(route('admin.markets.update', $market->id), array_merge($this->payload($market), $zeroLimits))
            ->assertUnprocessable()->assertJsonValidationErrors(array_keys($zeroLimits));
        $this->assertSame('0.1', $market->fresh()->getRawOriginal('bs'));
        $this->assertNull(Cache::get('market_kline_runtime_config_'.$market->id));
        $this->assertDirectoryDoesNotExist($this->isolatedStorage.'/app/market_kline_overrides');
    }

    public function test_proposed_method_saves_half_bs_but_does_not_halve_price(): void
    {
        $this->useProposal();
        $market = $this->fixture();
        $this->putJson(route('admin.markets.update', $market->id), $this->payload($market))->assertRedirect();
        $this->assertSame('0.5', $market->fresh()->getRawOriginal('bs'));
        $this->assertEquals(100, market_get_stats($market->id, 'last'));
        $this->assertEquals(100, $market->fresh()->last);
        // Unlike the production implementation, this ordinary save ran the K-line close path.
        $this->assertFalse(Cache::get('market_kline_runtime_config_'.$market->id)['custom_liquidity_t']);
        $this->assertFileExists($this->isolatedStorage.'/app/market_kline_overrides/'.$market->id.'/1.json');
    }

    public function test_proposed_method_repeats_existing_adjustment_on_ordinary_saves(): void
    {
        $this->useProposal();
        $market = $this->fixture(10);
        $payload = $this->payload($market); // No price adjustment was requested in this payload.
        $this->putJson(route('admin.markets.update', $market->id), $payload)->assertRedirect();
        $this->assertEquals(110, market_get_stats($market->id, 'last'));
        $this->putJson(route('admin.markets.update', $market->id), $payload)->assertRedirect();
        $this->assertEquals(121, market_get_stats($market->id, 'last'));
        $logs = json_decode(file_get_contents($this->isolatedStorage.'/app/market_kline_overrides/'.$market->id.'/change_logs.json'), true);
        $this->assertCount(2, $logs);
        $this->assertSame('0.5', $market->fresh()->getRawOriginal('bs'));
    }

    public function test_current_method_saves_same_payload_without_kline_side_effects(): void
    {
        $market = $this->fixture(10);
        $this->putJson(route('admin.markets.update', $market->id), $this->payload($market))->assertRedirect();
        $this->assertSame('0.5', $market->fresh()->getRawOriginal('bs'));
        $this->assertEquals(100, market_get_stats($market->id, 'last'));
        $this->assertNull(Cache::get('market_kline_runtime_config_'.$market->id));
        $this->assertDirectoryDoesNotExist($this->isolatedStorage.'/app/market_kline_overrides');
    }
}
