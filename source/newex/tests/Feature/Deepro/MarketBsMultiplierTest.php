<?php

namespace Tests\Feature\Deepro;

use App\Console\Commands\Market\{MarketLiquidityCommand, MarketStatsWatcherCommand};
use App\Http\Controllers\Api\v1\MarketController as ApiMarketController;
use App\Http\Controllers\Web\Client\ChartController;
use App\Http\Controllers\Web\Admin\MarketController as AdminMarketController;
use App\Models\Market\{Market, MarketAdmin};
use App\Models\User\User;
use App\Services\Chart\ExternalCandleService;
use App\Services\Market\MarketPriceMultiplier;
use Illuminate\Support\Facades\{Cache, DB, Event, File, Http, Mail, Queue};
use Illuminate\Support\Str;
use Tests\TestCase;

final class MarketBsMultiplierTest extends TestCase
{
    private string $originalStorage;
    private string $isolatedStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('deepro_test', DB::connection()->getDatabaseName());
        config(['cache.default' => 'array', 'session.driver' => 'array', 'app.readonly' => false,
            'admin-controls.simulation_controls' => false]);
        DB::beginTransaction();
        Event::fake(); Mail::fake(); Queue::fake(); Http::preventStrayRequests();
        $this->originalStorage = $this->app->storagePath();
        $this->isolatedStorage = sys_get_temp_dir().'/deepro-bs-ratio-'.Str::uuid();
        mkdir($this->isolatedStorage, 0700, true);
        $this->app->useStoragePath($this->isolatedStorage);
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) DB::rollBack();
        $this->app->useStoragePath($this->originalStorage);
        File::deleteDirectory($this->isolatedStorage);
        parent::tearDown();
    }

    private function invokeMethod(object $object, string $method, ...$args)
    {
        return (new \ReflectionMethod($object, $method))->invoke($object, ...$args);
    }

    private function set(object $object, string $property, $value): void
    {
        (new \ReflectionProperty($object, $property))->setValue($object, $value);
    }

    public function test_fractional_ratio_is_shared_by_all_consumers_and_legacy_neutral_values_stay_neutral(): void
    {
        foreach (['0.3' => 0.3, '0.305' => 0.305, '1e-2' => 0.01, '1' => 1.0, '4.3' => 4.3] as $ratio => $expected) {
            $model = new Market(['bs' => $ratio]);
            $this->assertSame($expected, MarketPriceMultiplier::resolve($ratio));
            $this->assertSame($expected, $this->invokeMethod(new MarketStatsWatcherCommand(), 'getPriceMultiplierFromMarket', $model));
            $this->assertSame($expected, $this->invokeMethod(new MarketLiquidityCommand(), 'getSpecialMarketPriceMultiplier', $model));
            $this->assertSame($expected, $this->invokeMethod(app(ApiMarketController::class), 'getTradePriceMultiplier', $model));
            $this->assertSame($expected, $this->invokeMethod(app(AdminMarketController::class), 'getMarketBsMultiplier', new MarketAdmin(['bs' => $ratio])));
        }
        foreach ([null, '', 0, '0', '-0.3', 'invalid', INF, NAN, '1e999'] as $ratio) {
            $this->assertSame(1.0, MarketPriceMultiplier::resolve($ratio));
        }
    }

    public function test_stats_and_external_book_apply_ratio_once_but_local_orders_keep_their_price(): void
    {
        $market = new Market(['bs' => '0.3', 'quote_precision' => 8]);
        $watcher = new MarketStatsWatcherCommand();
        $this->set($watcher, 'bsMultipliers', [15 => $this->invokeMethod($watcher, 'getPriceMultiplierFromMarket', $market)]);
        $raw = ['close' => 100, 'high' => 120, 'low' => 90, 'volume' => 7, 'qVolume' => 700];
        for ($i = 0; $i < 2; $i++) {
            $stats = $this->invokeMethod($watcher, 'buildAdjustedStats', 15, $raw);
            $this->assertEquals(['last' => 30, 'high' => 36, 'low' => 27], array_intersect_key($stats, array_flip(['last', 'high', 'low'])));
            $this->assertEquals(7, $stats['volume']);
            $this->assertEquals(700, $stats['qVolume']);
        }
        $liquidity = new MarketLiquidityCommand();
        $this->assertEquals(30, $this->invokeMethod($liquidity, 'applySpecialMarketPriceMultiplier', 100, $market));
        $this->assertEquals(33, $this->invokeMethod($liquidity, 'applyPercentOffsetToPrice', 100, 1.1, $market));
        $this->assertEquals(100, $this->invokeMethod($liquidity, 'formatLocalOrderbookPrice', 100, $market));
        $this->set($watcher, 'bsMultipliers', [15 => 0.5]);
        $this->assertEquals(50, $this->invokeMethod($watcher, 'buildAdjustedStats', 15, $raw)['last']);
        $this->set($watcher, 'bsMultipliers', [15 => 0.3]);
        $this->assertEquals(30, $this->invokeMethod($watcher, 'buildAdjustedStats', 15, $raw)['last']);
    }

    public function test_candles_scale_ohlc_without_mutating_raw_data_volume_or_market_14_cutoff(): void
    {
        $chart = app(ChartController::class);
        $raw = ['s' => 'ok', 't' => [strtotime('2026-04-27 UTC'), strtotime('2026-04-28 UTC')],
            'o' => [100, 100], 'h' => [120, 120], 'l' => [90, 90], 'c' => [110, 110], 'v' => [7, 8]];
        $model = new Market(['bs' => '0.3']); $model->id = 15;
        $result = $this->invokeMethod($chart, 'multiplyKlinePricesForSpecialMarket', $raw, $model, 8);
        $this->assertEquals([30, 30], $result['o']); $this->assertEquals([36, 36], $result['h']);
        $this->assertEquals([27, 27], $result['l']); $this->assertEquals([33, 33], $result['c']);
        $this->assertSame($raw['v'], $result['v']); $this->assertSame($raw['t'], $result['t']);
        $this->assertEquals([100, 100], $raw['o']);
        $model->id = 14;
        $cutoff = $this->invokeMethod($chart, 'multiplyKlinePricesForSpecialMarket', $raw, $model, 8);
        $this->assertEquals([100, 30], $cutoff['o']);
    }

    public function test_bs_save_to_public_chart_refresh_uses_new_ratio_not_previous_candle_locks(): void
    {
        $admin = User::withoutEvents(fn () => User::factory()->create(['email' => 'bs-ratio-'.Str::uuid().'@example.invalid', 'deleted' => false, 'deactivated' => false]));
        $admin->assignRole('superadmin'); $this->actingAs($admin);
        $market = Market::whereName('BTC-USDT')->firstOrFail();
        $market->forceFill(['switch_chart' => true, 'chart_symbol' => 'BSFIXTUREUSDT', 'chart_source' => 'binance',
            'bs' => '1', 'custom_liquidity_t' => false, 'bot_price_ceiling' => 0, 'status' => true])->save();
        $timestamp = (int) floor(time() / 60) * 60 - 3600;
        $raw = ['s' => 'ok', 't' => [$timestamp, $timestamp + 60], 'o' => [100, 100],
            'h' => [100, 100], 'l' => [100, 100], 'c' => [100, 100], 'v' => [7, 8]];
        $this->mock(ExternalCandleService::class, function ($mock) use ($raw) {
            $mock->shouldReceive('getCandles')->andReturn($raw);
        });
        $url = '/tradingview-chart/history?'.http_build_query(['symbol' => $market->name, 'resolution' => '1', 'from' => $timestamp, 'to' => $timestamp + 120]);
        foreach ([['1', 100], ['0.5', 50], ['0.3', 30], ['0.300', 30], ['2', 200], ['0.3', 30]] as [$ratio, $price]) {
            $this->putJson(route('admin.markets.bs.update', $market->id), ['bs' => (string) $ratio])->assertOk();
            $this->assertSame((string) $ratio, $market->fresh()->bs);
            for ($i = 0; $i < 2; $i++) {
                $data = $this->getJson($url)->assertOk()->json();
                foreach (['o', 'c'] as $key) $this->assertEquals([$price, $price], $data[$key]);
                // Preserve the pre-existing wick presentation rule, which depends on the displayed price.
                foreach ($data['h'] as $high) {
                    $this->assertGreaterThanOrEqual($price, $high);
                    $this->assertLessThanOrEqual($price * 1.001, $high);
                }
                foreach ($data['l'] as $low) {
                    $this->assertLessThanOrEqual($price, $low);
                    $this->assertGreaterThanOrEqual($price * 0.999, $low);
                }
                $this->assertEquals([7, 8], $data['v']);
            }
        }
    }

    public function test_public_external_trades_apply_ratio_after_raw_cache_and_repeated_requests_do_not_compound(): void
    {
        $market = Market::whereName('BTC-USDT')->firstOrFail();
        $market->forceFill(['bs' => '0.3', 'chart_symbol' => 'BSFIXTUREUSDT'])->save();
        Http::fake(['*/api/v3/trades*' => Http::response([['price' => '100', 'qty' => '7', 'time' => time() * 1000, 'isBuyerMaker' => false]])]);
        $url = route('markets.api.historical.trades', ['market' => $market->name]);
        foreach (['0.3', '0.5', '0.3'] as $ratio) {
            $market->forceFill(['bs' => $ratio])->save();
            $data = $this->getJson($url)->assertOk()->json();
            $this->assertTrue($data['success']);
            $this->assertEquals(100 * (float) $ratio, $data['trades'][0]['price']);
            $this->assertEquals(7, $data['trades'][0]['quantity']);
        }
        Http::assertSentCount(1);
        $this->assertSame('100', Cache::get('public-market-trades:BSFIXTUREUSDT')[0]['price']);
    }

    public function test_config_reload_updates_public_ticker_and_current_candle_from_raw_price(): void
    {
        $market = Market::whereName('BTC-USDT')->firstOrFail();
        $market->forceFill(['bs' => '0.5', 'discount' => 0, 'liq' => true, 'switch_chart' => true,
            'custom_liquidity' => false, 'custom_liquidity_t' => false, 'bot_price_ceiling' => 0,
            'chart_symbol' => 'BSLIVEUSDT', 'chart_source' => 'binance', 'status' => true])->save();
        $time = (int) floor(time() / 60) * 60;
        $raw = ['s' => 'ok', 't' => [$time - 60, $time], 'o' => [100, 100], 'h' => [100, 100],
            'l' => [100, 100], 'c' => [100, 100], 'v' => [7, 8]];
        $this->mock(ExternalCandleService::class, fn ($mock) => $mock->shouldReceive('getCandles')->andReturn($raw));
        $watcher = new MarketStatsWatcherCommand();
        $stats = ['close' => 100, 'high' => 100, 'low' => 100, 'volume' => 7, 'qVolume' => 700];
        // This 5-second runtime-config cache may still contain the previous multiplier's price.
        Cache::put('market_kline_runtime_config_'.$market->id, ['last' => 50]);
        foreach ([['0.5', 50], ['0.3', 30], ['0.3', 30], ['1', 100]] as [$ratio, $expected]) {
            $market->forceFill(['bs' => $ratio])->save();
            $this->invokeMethod($watcher, 'prepareMarkets');
            $adjusted = $this->invokeMethod($watcher, 'buildAdjustedStats', $market->id, $stats);
            // Advance the real worker's write throttle as if the next ticker arrived.
            $this->set($watcher, 'runtimeStatsUpdatedAt', [$market->id => microtime(true) - 5]);
            $this->invokeMethod($watcher, 'updateMarketRuntimeStats', app(\App\Services\Market\MarketService::class), $market->id, $adjusted);
            $this->assertEquals($expected, market_get_stats($market->id, 'last'));
            $this->getJson(route('markets.api.ticker', ['market' => $market->name]))->assertOk()->assertJsonPath('data.last', math_formatter($expected, $market->quote_precision));
            $data = $this->getJson('/tradingview-chart/history?'.http_build_query(['symbol' => $market->name,
                'resolution' => '1', 'from' => $time - 60, 'to' => $time + 60]))->assertOk()->json();
            $this->assertEquals([$expected, $expected], $data['c']);
        }
    }
    public function test_ticker_open_price_drives_change_and_config_refresh_cannot_restore_fixed_zero(): void
    {
        $ticker=$this->invokeMethod(new \App\Services\Liquidity\Binance\BinanceApi(),'tickerStreamHandler',(object)[
            's'=>'CAKEUSDT','o'=>'2.8','c'=>'2.67','h'=>'2.9','l'=>'2.6','v'=>'10','q'=>'27']);
        $this->assertSame('2.8',$ticker['open']);
        $market=Market::whereName('BTC-USDT')->firstOrFail();
        $market->forceFill(['chart_symbol'=>'CAKEUSDT','liq'=>true,'custom_liquidity'=>false,'custom_liquidity_t'=>false,'bs'=>'0.5','discount'=>0,'change_percent_24h'=>0])->save();
        $watcher=new MarketStatsWatcherCommand();
        $this->invokeMethod($watcher,'prepareMarkets');
        $this->invokeMethod($watcher,'rememberLatestRawStats',$market->id,$ticker);
        for($i=0;$i<2;$i++){
            $this->invokeMethod($watcher,'prepareMarkets');
            $stats=$this->invokeMethod($watcher,'buildAdjustedStats',$market->id,$ticker);
            $this->assertEquals(1.335,$stats['last']);$this->assertEquals(1.4,$stats['open']);
            $this->assertSame('-4.64',$stats['change']);
            $this->set($watcher,'runtimeStatsUpdatedAt',[]);
            $this->invokeMethod($watcher,'updateMarketRuntimeStats',app(\App\Services\Market\MarketService::class),$market->id,$stats);
            $this->getJson(route('markets.api.ticker',['market'=>$market->name]))->assertOk()->assertJsonPath('data.change','-4.64');
        }
    }

    public function test_change_is_signed_scale_invariant_and_missing_open_is_not_a_zero_return(): void
    {
        $watcher=new MarketStatsWatcherCommand();
        foreach([0.3,0.5,2.0] as $bs){
            $this->set($watcher,'bsMultipliers',[15=>$bs]);
            foreach([[110,'10'],[90,'-10'],[100,'0']] as [$close,$change]){
                $stats=$this->invokeMethod($watcher,'buildAdjustedStats',15,['open'=>100,'close'=>$close,'high'=>120,'low'=>80]);
                $this->assertSame($change,$stats['change']);
            }
        }
        foreach([null,0,-1] as $open)$this->assertNull($this->invokeMethod($watcher,'buildAdjustedStats',15,['open'=>$open,'close'=>100])['change']);
        market_set_stats_force(15,'change',null);$this->assertNull(market_get_stats(15,'change'));
    }

    public function test_existing_additive_chart_anchor_adjusts_the_open_and_close_together(): void
    {
        $watcher=new MarketStatsWatcherCommand();$this->set($watcher,'bsMultipliers',[15=>0.5]);
        Cache::put('market_kline_adjusted_at_15',now()->subSeconds(31)->toIso8601String());
        Cache::put('market_kline_adjusted_price_15',['price'=>60]);
        $stats=$this->invokeMethod($watcher,'buildAdjustedStats',15,['open'=>100,'close'=>110,'high'=>120,'low'=>80]);
        $this->assertEquals(55,$stats['open']);$this->assertEquals(60,$stats['last']);$this->assertSame('9.09',$stats['change']);
    }

}
