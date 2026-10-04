<?php
namespace Tests\Feature\Deepro;
use Tests\TestCase;
use App\Services\Chart\{ExternalCandleService,HistoryWindow};
use App\Services\Market\TickerFreshness;
use App\Services\Math\ExactDecimal;
use Illuminate\Support\Facades\{Cache,Http};
final class MarketReliabilityTest extends TestCase {
    protected function setUp():void {parent::setUp();config(['cache.default'=>'array','liquidity.market_data_base'=>'https://quotes.test']);Http::preventStrayRequests();}
    public function test_chart_rejects_unbounded_and_invalid_ranges_without_http():void {
        $svc=new ExternalCandleService();foreach([[0,31536000,'1'],[10,1,'1'],[0,100,'unsupported']] as[$from,$to,$res])$this->assertSame('error',$svc->getCandles('BTCUSDT',$from,$to,$res)['s']);Http::assertNothingSent();
        $this->assertTrue(HistoryWindow::valid(0,5000*60,'1'));$this->assertSame(0,HistoryWindow::start(0,600,'1',3,590));
    }
    public function test_realtime_and_historical_cache_keys_keep_exact_requested_bounds():void {
        Http::fake(function($r){$t=(int)$r['startTime'];return Http::response([[$t,'1','2','1','2','3']]);});$svc=new ExternalCandleService();
        $now=time();$a=$svc->getCandles('BTCUSDT',$now-60,$now,'1');$b=$svc->getCandles('BTCUSDT',$now-120,$now,'1');$again=$svc->getCandles('BTCUSDT',$now-60,$now,'1');
        $this->assertSame($now-60,$a['t'][0]);$this->assertSame($now-120,$b['t'][0]);$this->assertSame($a,$again);
        $a=$svc->getCandles('BTCUSDT',1201,1801,'1');$b=$svc->getCandles('BTCUSDT',1202,1802,'1');$this->assertSame(1201,$a['t'][0]);$this->assertSame(1202,$b['t'][0]);Http::assertSentCount(4);
    }
    public function test_nonprogressing_upstream_pages_fail_instead_of_looping_or_returning_partial_data():void {
        $rows=[];for($i=0;$i<1000;$i++)$rows[]=[($i+1)*60000,'1','2','1','2','3'];Http::fake(['*'=>Http::response($rows)]);
        $result=(new ExternalCandleService())->getCandles('BTCUSDT',60,180000,'1');$this->assertSame('error',$result['s']);Http::assertSentCount(2);
    }
    public function test_bybit_backward_pagination_produces_complete_ascending_history():void {
        Http::fake(function($r){$last=min(400,(int)floor($r['end']/60000));$rows=[];for($i=$last;$i>max(0,$last-200);$i--)$rows[]=[(string)($i*60000),'1','2','1','2','3'];return Http::response(['result'=>['list'=>$rows]]);});
        $result=(new ExternalCandleService())->getCandles('BTCUSDT',60,24000,'1','bybit');$this->assertSame('ok',$result['s']);$this->assertCount(400,$result['t']);$this->assertSame(60,$result['t'][0]);$this->assertSame(24000,$result['t'][399]);Http::assertSentCount(2);
    }
    public function test_upstream_errors_are_cached_briefly_to_bound_retry_fanout():void {
        Http::fake(['*'=>Http::response([],503)]);$svc=new ExternalCandleService();$this->assertSame('error',$svc->getCandles('BTCUSDT',60,600,'1')['s']);$this->assertSame('error',$svc->getCandles('BTCUSDT',60,600,'1')['s']);Http::assertSentCount(1);
    }
    public function test_ticker_read_and_heartbeat_do_not_make_old_quotes_fresh():void {
        $svc=new TickerFreshness();$this->assertTrue($svc->snapshot(999999)['price_stale']);$svc->received(999999,'binance:BTCUSDT',now()->timestamp*1000);$first=$svc->snapshot(999999);
        $this->travel(20)->seconds();$later=$svc->snapshot(999999);$this->assertTrue($later['price_stale']);$this->assertSame($first['updated_at'],$later['updated_at']);$this->assertNotSame($first['served_at'],$later['served_at']);$this->travelBack();
        $svc->received(999998,'mexc:BTCUSDT');$this->assertSame('receipt_only',$svc->snapshot(999998)['price_timestamp_quality']);$this->assertNull($svc->snapshot(999998)['source_event_at']);
    }
    public function test_legacy_finance_and_option_decimal_paths_preserve_18_places():void {
        $values=['123456789012345.123456789123456789','0.000000000000000001','1.23456789123456789e-5'];
        $svc=new \App\Services\Wallet\AutoInvestOrderService();$option=new \App\Repositories\Option\OptionRepository();
        foreach($values as$value){$normal=ExactDecimal::normalize($value);foreach([[$svc,'decimal'],[$option,'safeDecimal']] as[$object,$method]){$f=new \ReflectionMethod($object,$method);$f->setAccessible(true);$this->assertSame($normal,$f->invoke($object,$value));}}
        $this->assertSame('123456789012345.123456789123456789',ExactDecimal::normalize($values[0]));$this->assertSame('0.000012345678912345',ExactDecimal::normalize($values[2]));
        $method=new \ReflectionMethod($svc,'add');$method->setAccessible(true);$this->assertSame('123456789012345.123456789123456790',$method->invoke($svc,$values[0],$values[1]));
        foreach(['NaN','1e999','garbage'] as$bad){try{ExactDecimal::normalize($bad);$this->fail();}catch(\InvalidArgumentException $e){$this->assertTrue(true);}}
    }
}
