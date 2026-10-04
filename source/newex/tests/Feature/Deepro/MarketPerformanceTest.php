<?php
namespace Tests\Feature\Deepro;
use Tests\TestCase;
use App\Http\Controllers\Web\Client\{ExploreController,MarketDiscoveryController,ChartController};
use App\Services\Market\{HongKongMarketData,HongKongProductListing,StockCatalog};
use Illuminate\Support\Facades\{DB,Cache,Event,Queue,Http};
use Illuminate\Http\Request;
final class MarketPerformanceTest extends TestCase {
 protected function setUp():void {parent::setUp();$this->assertSame('deepro_test',DB::connection()->getDatabaseName());DB::beginTransaction();Event::fake();Queue::fake();Http::preventStrayRequests();config(['cache.default'=>'array']);Cache::flush();}
 protected function tearDown():void {while(DB::transactionLevel()>0)DB::rollBack();parent::tearDown();}
 public function test_holiday_hk_sparkline_uses_daily_prices_instead_of_unsupported_hourly_bars():void {
  app(HongKongProductListing::class)->apply('HK08379',true);
  $to=time();$times=[$to-6*86400,$to-5*86400,$to-2*86400];
  $chart=$this->mock(ChartController::class);$chart->shouldReceive('history')->once()->withArgs(fn($request)=>$request->get('symbol')==='HK08379-USDT' && $request->get('resolution')==='1D' && $request->get('from')<=$times[0])->andReturn(response()->json(['s'=>'ok','t'=>$times,'c'=>[0.1,0.11,0.102]]));
  $out=app(MarketDiscoveryController::class)->sparklines(Request::create('/','GET',['markets'=>['HK08379-USDT']]))->getData(true)['data']['HK08379-USDT'];
  $this->assertFalse($out['unavailable']);$this->assertSame('1D',$out['resolution']);$this->assertSame('HKD',$out['currency']);$this->assertCount(3,$out['points']);$this->assertSame($times[2],$out['points'][2]['time']);
 }
 public function test_fx_failure_preserves_recent_display_reference_and_does_not_cache_an_executable_quote():void {
  $fx=['hkdPerUsdt'=>'7.8','eventTime'=>now()->timestamp,'source'=>'test'];$feed=$this->mock(HongKongMarketData::class);$feed->shouldReceive('fx')->once()->andReturn($fx);
  $out=app(ExploreController::class)->stockFx($feed)->getData(true)['fx'];$this->assertSame('HKD',$out['base']);$this->assertSame('USDT',$out['quote']);$this->assertSame('7.8',$out['hkdPerUsdt']);
  $failed=\Mockery::mock(HongKongMarketData::class);$failed->shouldReceive('fx')->twice()->andThrow(new \RuntimeException('unavailable'));
  $this->assertSame($out,app(ExploreController::class)->stockFx($failed)->getData(true)['fx']);
  Cache::put('hk-display.last-fx',array_replace($fx,['eventTime'=>now()->timestamp-604801]));
  $this->assertNull(app(ExploreController::class)->stockFx($failed)->getData(true)['fx']);$this->assertNull(Cache::get('markets_liquidity.HK08379-USDT.executable'));
 }
 public function test_catalog_depth_query_does_not_grow_with_asset_count():void {
  DB::enableQueryLog();DB::flushQueryLog();$assets=app(StockCatalog::class)->assets();$queries=DB::getQueryLog();DB::disableQueryLog();
  $this->assertNotEmpty($assets);$depth=array_filter($queries,fn($q)=>str_contains($q['query'],'stock_liquidity_books'));$this->assertCount(1,$depth);
 }
}
