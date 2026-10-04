<?php
namespace Tests\Feature\Deepro;

use App\Models\Market\Market;
use App\Services\Chart\HistoryWindow;
use Illuminate\Support\Facades\{DB, Event, Http, Mail};
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class ChartPresentationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('deepro_test', DB::connection()->getDatabaseName());
        config(['cache.default'=>'array', 'broadcasting.default'=>'log', 'mail.default'=>'array']);
        \Illuminate\Support\Facades\Route::getRoutes()->refreshNameLookups();
        \Illuminate\Support\Facades\Route::getRoutes()->refreshActionLookups();
        DB::beginTransaction(); Event::fake(); Mail::fake(); Http::preventStrayRequests();
    }
    protected function tearDown(): void {while (DB::transactionLevel() > 0) DB::rollBack(); parent::tearDown();}
    public function test_countback_includes_enough_bars_when_to_is_in_next_minute(): void
    {
        $now = 1800000045; $to = 1800000105;
        $from = HistoryWindow::start($to - 300 * 60, $to, '1', 300, $now);
        $bars = range($from, intdiv($now, 60) * 60, 60);
        $this->assertCount(300, $bars); $this->assertLessThan($to, end($bars));
        $this->assertSame(0, $from % 60);
    }
    public function test_historical_window_excludes_to_and_keeps_requested_older_history(): void
    {
        $to = 1800000000;
        $this->assertSame($to - 300 * 60, HistoryWindow::start($to - 299 * 60, $to, '1', 300, $to + 999));
        $this->assertSame($to - 500 * 60, HistoryWindow::start($to - 500 * 60, $to, '1', 300, $to));
        $this->assertSame(30, HistoryWindow::start(30, 70, '1M', 300, 65));
        $this->assertSame(0, HistoryWindow::start(1, 70, '1', 300, 65));
    }
    public function test_invalid_countback_is_rejected_without_upstream_request(): void
    {
        $this->getJson('/tradingview-chart/history?symbol=BTC-USDT&from=10&to=100&resolution=1&countback=999999')->assertUnprocessable();
        $this->getJson('/tradingview-chart/history?symbol=BTC-USDT&from=100&to=10&resolution=1&countback=300')->assertUnprocessable();
        Http::assertNothingSent();
    }
    public function test_chart_bootstrap_is_local_and_keeps_platform_symbol(): void
    {
        $this->withoutExceptionHandling();
        $response = $this->get('/tradingview-chart/chart/BTC-USDT/false/light');
        $response->assertOk()->assertViewHas('chartBootstrap', fn($b) => $b['symbol']['ticker'] === 'BTC-USDT' && isset($b['config']['supported_resolutions']));
        Http::assertNothingSent();
    }
    public function test_stock_legacy_url_opens_shared_trade_with_metadata(): void
    {
        $this->get('/stocks/AAPLon')->assertRedirect(route('market', ['market'=>'AAPLon-USDT','asset'=>'info']));
        $this->get('/market/AAPLon-USDT')->assertInertia(fn(Assert $page) => $page->component('Market/Market')->where('stockAsset.symbol', 'AAPLon')->where('stockAsset.ticker','AAPL')->where('stockAsset.chainId',56)->etc());
        $this->get('/market/BTC-USDT')->assertInertia(fn(Assert $page) => $page->component('Market/Market')->where('stockAsset', null)->etc());
    }
    public function test_disabled_and_hidden_stock_do_not_gain_trading_entry(): void
    {
        DB::table('markets')->where('name','AAPLon-USDT')->update(['trade_status'=>false]);
        $this->get('/stocks/AAPLon')->assertInertia(fn(Assert $page) => $page->component('Explore/Stocks')->where('selectedSymbol','AAPLon')->etc());
        DB::table('currencies')->where('symbol','AAPLon')->update(['asset_display_enabled'=>false,'asset_chart_interval'=>'1h']);
        $this->get('/stocks/AAPLon')->assertNotFound();
    }
    public function test_error_pages_use_local_shared_assets_without_loading_app_bundle(): void
    {
        app()->setLocale('zh-cn');
        foreach ([404,429,500,503] as $status) {
            $html = view('errors.'.$status)->render();
            $this->assertStringContainsString('class="dp-error"', $html);
            $this->assertStringContainsString('<h1>'.$status.'</h1>', $html);
            $this->assertStringNotContainsString('frontend/js/app.js', $html);
            $this->assertStringNotContainsString('bg-home.png', $html);
        }
    }
    public function test_shared_copy_is_available_in_all_supported_locales(): void
    {
        foreach (['en','zh-cn','zh-tw','fr','de','es','it','pt','nl','pl','cs','ro','bg','ru','uk','ja'] as $locale) {
            $catalogue = json_decode(file_get_contents(resource_path('lang/'.$locale.'.json')), true, 512, JSON_THROW_ON_ERROR);
            foreach (['Chart unavailable. Please retry.','Shares per token','Asset information','Reference price','Search name or symbol','Page not found.'] as $key) {
                $this->assertNotEmpty($catalogue[$key] ?? null, $locale.':'.$key);
            }
        }
    }
}
