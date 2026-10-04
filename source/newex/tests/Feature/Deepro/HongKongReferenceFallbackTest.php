<?php
namespace Tests\Feature\Deepro;

use App\Services\Market\{HongKongMarketData,HongKongTencentReference};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\{Cache,DB,Http};
use Tests\TestCase;

final class HongKongReferenceFallbackTest extends TestCase
{
    protected function setUp():void
    {
        parent::setUp();$this->assertSame('deepro_test',DB::connection()->getDatabaseName());
        DB::beginTransaction();config(['cache.default'=>'array']);Http::preventStrayRequests();CarbonImmutable::setTestNow('2026-09-29 18:00:00 Asia/Hong_Kong');
    }
    protected function tearDown():void {while(DB::transactionLevel()>0)DB::rollBack();CarbonImmutable::setTestNow();parent::tearDown();}
    private function qt():array
    {
        $r=array_fill(0,78,'0');foreach([0=>'100',1=>'Observed quote',2=>'08379',3=>'0.103',30=>'2026/09/29 16:08:04',32=>'1.98',33=>'0.103',34=>'0.095',75=>'HKD'] as $k=>$v)$r[$k]=$v;return $r;
    }
    private function body():array {return ['code'=>0,'msg'=>'','data'=>['hk08379'=>['day'=>[['2026-09-28','0.101','0.101','0.102','0.100','300000.000'],['2026-09-29','0.101','0.103','0.103','0.095','270000.000']],'qt'=>['hk08379'=>$this->qt()]]]];}
    public function test_zero_trade_session_does_not_invalidate_real_history_or_invent_a_daily_range():void
    {
        $f=app(HongKongTencentReference::class);$body=$this->body();$q=$this->qt();$q[6]='0';$q[33]='0';$q[34]='0';$body['data']['hk08379']['qt']['hk08379']=$q;
        $this->assertCount(2,$f->normalizeCandles($body,'08379')['data']);
        $quote=$f->normalizeQuote($q,'08379');$this->assertNull($quote['high']);$this->assertNull($quote['low']);$this->assertSame('0.103',$quote['price']);
        $q[6]='10';$this->expectException(\RuntimeException::class);$f->normalizeQuote($q,'08379');
    }
    public function test_source_outage_falls_back_to_real_native_daily_bars_and_source_quote_time():void
    {
        Http::fake(function($r){
            if(str_contains($r->url(),'eastmoney.com'))return Http::response('',502);
            if(str_contains($r->url(),'qt.gtimg.cn'))return Http::response('v_hk08379="'.implode('~',$this->qt()).'";');
            if(str_contains($r->url(),'web.ifzq.gtimg.cn'))return Http::response($this->body());
            throw new \RuntimeException('Unexpected request');
        });
        $asset=config('hk-price-products.assets.HK08379')+['symbol'=>'HK08379'];$f=app(HongKongMarketData::class);
        $q=$f->quote($asset);$this->assertSame('Tencent public reference',$q['source']);$this->assertSame(1790669284,$q['eventTime']);$this->assertSame('0.103',$q['price']);
        $bars=$f->candles($asset,'1d',120,null);$this->assertTrue($bars['fallback']);$this->assertSame('Tencent public reference',$bars['source']);
        $this->assertSame('HKD',$bars['currency']);$this->assertSame('unadjusted',$bars['adjustment']);$this->assertCount(2,$bars['data']);
        $this->assertSame(270000.0,$bars['data'][1]['volume']);$this->assertSame(0.103,$bars['data'][1]['close']);Http::assertSentCount(3);
    }
    public function test_daily_polling_seconds_share_a_source_page_without_losing_exact_cutoff():void
    {
        Http::fake(['*eastmoney.com*'=>Http::response('',503),'https://web.ifzq.gtimg.cn/*'=>Http::response($this->body())]);
        $asset=config('hk-price-products.assets.HK08379')+['symbol'=>'HK08379'];$feed=app(HongKongMarketData::class);
        $time=CarbonImmutable::now()->timestamp;
        $first=$feed->candles($asset,'1d',300,$time);
        $second=$feed->candles($asset,'1d',300,$time+4);
        $future=$feed->candles($asset,'1d',300,$time+86400*5);
        $this->assertSame($first,$second);$this->assertSame($first,$future);
        $this->assertCount(2,$first['data']);$this->assertSame('Tencent public reference',$first['source']);
        Http::assertSentCount(2);
        // An older cutoff needs its own historical source page, not today's cached bars.
        $older=$feed->candles($asset,'1d',300,CarbonImmutable::parse('2026-09-29','UTC')->timestamp-1);
        $this->assertCount(1,$older['data']);Http::assertSentCount(3);
        Http::assertSent(fn($r)=>str_contains(urldecode($r->url()),'param=hk08379,day,,2026-09-28,300,'));
    }
    public function test_concurrent_primary_probe_does_not_make_another_request_wait():void
    {
        Http::fake(['https://web.ifzq.gtimg.cn/*'=>Http::response($this->body())]);
        $lock=Cache::lock('hk-reference.eastmoney.probe',10);$this->assertTrue($lock->get());
        try {
            $result=app(HongKongMarketData::class)->candles(config('hk-price-products.assets.HK08379')+['symbol'=>'HK08379'],'1d',300,null);
            $this->assertSame('Tencent public reference',$result['source']);
            Http::assertSentCount(1);Http::assertNotSent(fn($r)=>str_contains($r->url(),'eastmoney.com'));
        } finally {$lock->release();}
    }
    public function test_daily_fallback_never_masquerades_as_an_intraday_candle():void
    {
        Http::fake(['*eastmoney.com*'=>Http::response('',503)]);
        try {app(HongKongMarketData::class)->candles(config('hk-price-products.assets.HK08379')+['symbol'=>'HK08379'],'5m',120,null);$this->fail('daily masqueraded as intraday');}
        catch(\RuntimeException $e){$this->assertSame('hk_intraday_reference_unavailable',$e->getMessage());}
        Http::assertNotSent(fn($r)=>str_contains($r->url(),'gtimg.cn'));
    }
    public function test_historical_request_passes_inclusive_end_date_to_provider():void
    {
        $body=$this->body();$body['data']['hk08379']['day']=[['2026-02-27','0.101','0.103','0.103','0.095','270000.000']];
        Http::fake(['https://web.ifzq.gtimg.cn/*'=>Http::response($body)]);
        $to=CarbonImmutable::parse('2026-03-01 23:59:59','UTC')->timestamp;
        $result=app(HongKongTencentReference::class)->candles('08379',120,$to);
        $this->assertCount(1,$result['data']);
        $this->assertSame(CarbonImmutable::parse('2026-02-27 00:00:00','UTC')->timestamp*1000,$result['data'][0]['time']);
        Http::assertSent(fn($r)=>str_contains(urldecode($r->url()),'param=hk08379,day,,2026-03-01,120,'));
    }
    public function test_identity_currency_and_future_source_time_are_rejected():void
    {
        $f=app(HongKongTencentReference::class);
        foreach ([2=>'00700',75=>'USD',30=>'2026/09/29 19:00:00'] as $index=>$value) {
            $row=$this->qt();$row[$index]=$value;
            try {$f->normalizeQuote($row,'08379');$this->fail('invalid Tencent quote accepted');}
            catch(\RuntimeException $e){$this->assertNotEmpty($e->getMessage());}
        }
    }
    public function test_invalid_daily_rows_are_not_repaired_and_requested_end_is_respected():void
    {
        $body=$this->body();$body['data']['hk08379']['day'][]=['2026-04-21','0.182','0.172','0.180','0.172','100'];
        $r=app(HongKongTencentReference::class)->normalizeCandles($body,'08379',CarbonImmutable::parse('2026-09-28 23:59:00','UTC')->timestamp);
        $this->assertCount(1,$r['data']);$this->assertSame(1,$r['rejected_bars']);$this->assertSame(.101,$r['data'][0]['close']);
        $body['data']['hk08379']['day'][0][0]='2026-09-28 09:30';
        $this->expectException(\RuntimeException::class);app(HongKongTencentReference::class)->normalizeCandles($body,'08379');
    }
    public function test_batch_quotes_keep_exact_identity_and_retain_last_close_through_source_outage():void
    {
        $row=$this->qt();$bad=$row;$bad[2]='00700';
        Http::fake(['https://qt.gtimg.cn/*'=>Http::response('v_hk08379="'.implode('~',$row).'";v_hk00388="'.implode('~',$bad).'";')]);
        $asset=config('hk-price-products.assets.HK08379')+['symbol'=>'HK08379'];
        $f=app(HongKongMarketData::class);$f->warmQuotes([$asset]);$quote=$f->quote($asset);
        $this->assertSame('0.103',$quote['price']);Http::assertSentCount(1);
        Cache::forget('hk-reference.quote.08379');Http::fake(['*'=>Http::response('',503)]);
        $this->assertSame($quote,$f->quote($asset));
        CarbonImmutable::setTestNow('2026-10-08 18:00:00 Asia/Hong_Kong');
        $this->expectException(\RuntimeException::class);$f->quote($asset);
    }
    public function test_sina_fx_fallback_uses_actual_usdt_usd_and_rejects_wrong_identity():void
    {
        $row=array_fill(0,18,'0');foreach([0=>'18:00:00',1=>'7.799',2=>'7.801',8=>'7.8',17=>'2026-09-29'] as $k=>$v)$row[$k]=$v;
        $body='var hq_str_fx_susdhkd="'.implode(',',$row).'";';
        Http::fake(['*eastmoney.com*'=>Http::response('',503),'*sinajs.cn*'=>Http::response($body),'*kraken.com*'=>Http::response(['error'=>[],'result'=>['USDTZUSD'=>[['0.999','100',CarbonImmutable::now()->timestamp]]]])]);
        $f=app(HongKongMarketData::class);$fx=$f->fx();$this->assertSame('7.792200000000000000',$fx['hkdPerUsdt']);$this->assertSame(CarbonImmutable::now()->timestamp,$fx['eventTime']);
        foreach([str_replace('susdhkd','seurusd',$body),str_replace('7.799','8.2',$body),str_replace('2026-09-29','2026-09-31',$body)] as $invalid) {
            try {$f->normalizeSinaUsdHkd($invalid);$this->fail('invalid FX accepted');}catch(\RuntimeException $e){$this->assertNotEmpty($e->getMessage());}
        }
    }
}
