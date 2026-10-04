<?php
namespace Tests\Feature\Deepro;
use App\Services\Market\StockCatalog;
use Illuminate\Support\Facades\Http;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
class StockCatalogTest extends TestCase {
 use DatabaseTransactions;
 public function test_ten_assets_are_visible_without_preview_mode(): void {
  config(['local-ui.enabled'=>false]);$a=app(StockCatalog::class)->assets();$this->assertCount(10,$a);$this->assertSame(8,count(array_filter($a,fn($r)=>$r['assetType']==='stock')));$this->assertCount(10,array_unique(array_column($a,'contract')));foreach($a as $r){$this->assertSame(56,$r['chainId']);$this->assertSame(18,$r['decimals']);$this->assertMatchesRegularExpression('/^0x[0-9a-f]{40}$/',$r['contract']);}
 }
 public function test_display_preference_persists_and_hidden_asset_has_no_candle_endpoint(): void {
  DB::table('currencies')->where('symbol','AAPLon')->update(['asset_display_enabled'=>false,'asset_chart_interval'=>'4h']);
  $this->assertCount(9,app(StockCatalog::class)->assets());$this->assertSame('4h',app(StockCatalog::class)->assets(true)[0]['defaultInterval']);
  $this->getJson('/stocks/data/AAPLon/candles?interval=1h')->assertNotFound();
 }
 public function test_unknown_asset_or_interval_cannot_reach_gateway(): void {
  Http::preventStrayRequests();$this->getJson('/stocks/data/UNKNOWN/candles?interval=1h')->assertNotFound();$this->getJson('/stocks/data/AAPLon/candles?interval=1second')->assertUnprocessable();
 }
 public function test_gateway_failure_returns_unavailable_not_fake_history(): void {
  Http::fake(['*'=>Http::response([],502)]);
  $this->getJson('/stocks/data/AAPLon/candles?interval=1h')->assertStatus(503)->assertJsonMissingPath('data');
 }
 public function test_asset_actions_follow_original_currency_and_market_switches():void {
  $asset=collect(app(StockCatalog::class)->assets())->firstWhere('symbol','AAPLon');
  $this->assertTrue($asset['tradeEnabled']);$this->assertTrue($asset['depositEnabled']);
  DB::table('currencies')->where('symbol','AAPLon')->update(['deposit_status'=>false]);
  DB::table('markets')->where('name','AAPLon-USDT')->update(['trade_status'=>false]);
  $asset=collect(app(StockCatalog::class)->assets())->firstWhere('symbol','AAPLon');
  $this->assertFalse($asset['depositEnabled']);$this->assertFalse($asset['tradeEnabled']);$this->assertTrue($asset['withdrawEnabled']);
 }
}
