<?php
namespace Tests\Feature\Deepro;
use App\Services\Market\{HongKongIntraday,HongKongMarketData};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\{Cache,Http,DB};
use Tests\TestCase;
final class HongKongIntradayTest extends TestCase
{
    protected function setUp():void {parent::setUp();$this->assertSame('deepro_test',DB::connection()->getDatabaseName());DB::beginTransaction();config(['cache.default'=>'array']);Cache::flush();Http::preventStrayRequests();CarbonImmutable::setTestNow('2026-10-01T02:00:00Z');}
    protected function tearDown():void {while(DB::transactionLevel()>0)DB::rollBack();CarbonImmutable::setTestNow();parent::tearDown();}
    private function body():array {
        $times=array_map(fn($time)=>CarbonImmutable::parse('2026-09-30 '.$time,'Asia/Hong_Kong')->timestamp,['09:30','09:35','12:05','13:00','13:05','16:05']);
        return ['chart'=>['error'=>null,'result'=>[['meta'=>['symbol'=>'8379.HK','currency'=>'HKD','exchangeName'=>'HKG','exchangeTimezoneName'=>'Asia/Hong_Kong','dataGranularity'=>'5m'],'timestamp'=>$times,'indicators'=>['quote'=>[['open'=>[.10,.11,.10,.12,null,.13],'high'=>[.11,.12,.10,.13,null,.14],'low'=>[.09,.10,.10,.11,null,.12],'close'=>[.11,.12,.10,.13,null,.14],'volume'=>[10,20,99,30,null,40]]]]]]]];
    }
    public function test_native_open_times_null_gaps_lunch_and_auction_are_handled_without_fake_bars():void {
        $f=app(HongKongIntraday::class);$body=$this->body();$rows=$f->normalize($body,'8379.HK','5m');
        $this->assertCount(4,$rows);$this->assertSame($body['chart']['result'][0]['timestamp'][0]*1000,$rows[0]['time']);
        $hours=$f->aggregateHours($rows);$this->assertCount(3,$hours);$this->assertSame(.10,$hours[0]['open']);$this->assertSame(.12,$hours[0]['close']);$this->assertSame(30.0,$hours[0]['volume']);
        $this->assertSame(30.0,$hours[1]['volume']);$this->assertSame(40.0,$hours[2]['volume']);
    }
    public function test_wrong_identity_currency_interval_and_malformed_ohlc_are_rejected():void {
        foreach (['symbol'=>'0700.HK','currency'=>'USD','exchangeName'=>'NMS','dataGranularity'=>'1d'] as $k=>$v) {
            $body=$this->body();$body['chart']['result'][0]['meta'][$k]=$v;
            try {app(HongKongIntraday::class)->normalize($body,'8379.HK','5m');$this->fail('invalid identity accepted');}catch(\RuntimeException $e){$this->assertSame('hk_intraday_identity_mismatch',$e->getMessage());}
        }
        $body=$this->body();$body['chart']['result'][0]['indicators']['quote'][0]['high'][0]=.01;
        $this->expectException(\RuntimeException::class);app(HongKongIntraday::class)->normalize($body,'8379.HK','5m');
    }
    public function test_minute_requests_use_native_source_and_cache_without_touching_daily_or_execution_quotes():void {
        Http::fake(['query1.finance.yahoo.com/*'=>Http::response($this->body())]);
        $asset=config('hk-price-products.assets.HK08379')+['symbol'=>'HK08379'];$f=app(HongKongMarketData::class);
        $first=$f->candles($asset,'5m',300,null);$second=$f->candles($asset,'5m',300,null);$hour=$f->candles($asset,'1h',300,null);
        $this->assertSame($first,$second);$this->assertCount(4,$first['data']);$this->assertCount(3,$hour['data']);$this->assertTrue($first['delayed']);
        Http::assertSentCount(1);$this->assertNull(Cache::get('hk-reference.quote.08379'));
    }
    public function test_old_minute_window_returns_no_data_without_daily_substitution():void {
        $r=app(HongKongIntraday::class)->candles(config('hk-price-products.assets.HK08379')+['symbol'=>'HK08379'],'1m',300,CarbonImmutable::now()->subDays(8)->timestamp);
        $this->assertSame([],$r['data']);Http::assertNothingSent();
    }
    public function test_valid_empty_source_window_is_no_data_not_an_outage():void {
        $body=$this->body();unset($body['chart']['result'][0]['timestamp']);
        $body['chart']['result'][0]['indicators']['quote']=[[]];
        Http::fake(['query1.finance.yahoo.com/*'=>Http::response($body)]);
        $result=app(HongKongMarketData::class)->candles(config('hk-price-products.assets.HK08379')+['symbol'=>'HK08379'],'5m',300,CarbonImmutable::now()->subDays(2)->timestamp);
        $this->assertSame([],$result['data']);$this->assertSame('HKD',$result['currency']);Http::assertSentCount(1);
    }
    public function test_chart_quotes_reference_and_local_trade_feeds_in_usdt():void {
        app(\App\Services\Market\HongKongProductListing::class)->apply('HK08379',false);
        $this->getJson('/tradingview-chart/symbols?symbol=HK08379-USDT')->assertOk()->assertJsonPath('has_intraday',true)->assertJsonPath('currency_code','USDT')->assertJsonPath('supported_resolutions',['1','5','15','60','1D']);
        $this->getJson('/tradingview-chart/trades/symbols?symbol=HK08379-USDT')->assertOk()->assertJsonPath('currency_code','USDT')->assertJsonPath('listed_exchange','Deepro');
    }
}
