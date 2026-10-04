<?php
namespace Tests\Feature\Deepro;

use App\Services\Market\{ExternalReferenceOrderBook,HongKongPriceProduct};
use App\Services\Market\ExternalReferenceDepth\LongbridgeDepthProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\{Cache,DB,Http};
use Tests\TestCase;

final class ExternalReferenceDepthTest extends TestCase
{
    private array $asset;
    protected function setUp():void
    {
        parent::setUp();$this->assertSame('deepro_test',DB::connection()->getDatabaseName());
        DB::beginTransaction();config(['cache.default'=>'array']);Http::preventStrayRequests();
        CarbonImmutable::setTestNow('2026-09-29 17:00:00 Asia/Hong_Kong');
        $this->asset=config('hk-price-products.assets.HK08379')+['symbol'=>'HK08379'];
    }
    protected function tearDown():void {while(DB::transactionLevel()>0)DB::rollBack();CarbonImmutable::setTestNow();parent::tearDown();}
    private function observed():array {return json_decode(file_get_contents(base_path('tests/Fixtures/hk08379-depth-observed.json')),true,64,JSON_THROW_ON_ERROR);}
    private function html(array $data):string
    {
        return '<script>window.__TANSTACK_DEHYDRATED__.queries.push('.json_encode(['queryKey'=>['stock','quote-overview','ST/HK/8379','detail'],'state'=>['data'=>['data'=>$data]]]).')</script>';
    }
    private function enable():void {config(['hk-price-products.external_depth.enabled'=>true,'hk-price-products.external_depth.source_permission_confirmed'=>true]);}
    public function test_default_disabled_and_missing_permission_make_no_requests_even_if_cached():void
    {
        Cache::put('external-reference-depth.longbridge_public.08379',['data'=>['bids'=>[['price'=>'1','quantity'=>'99']]]],100);
        $service=app(ExternalReferenceOrderBook::class);
        $this->assertSame('disabled',$service->get($this->asset)['state']);
        config(['hk-price-products.external_depth.enabled'=>true]);
        $result=$service->get($this->asset);$this->assertSame('disabled',$result['state']);$this->assertSame('source_permission_required',$result['reason']);
        $this->assertSame(['bids'=>[],'asks'=>[]],$result['data']);Http::assertNothingSent();
    }
    public function test_minimal_observed_html_is_parsed_without_multiplying_share_volume():void
    {
        $data=app(LongbridgeDepthProvider::class)->parseHtml($this->html($this->observed()),'08379');
        $this->assertSame(1790669284,$data['source_time']);$this->assertSame(10000,$data['lot_size']);
        $this->assertSame([['price'=>'0.103','quantity'=>'30000','raw_volume'=>'30000']],$data['data']['bids']);
        $this->assertSame([['price'=>'0.114','quantity'=>'10000','raw_volume'=>'10000']],$data['data']['asks']);
    }
    public function test_authorized_reference_has_real_timestamp_attribution_and_only_one_nonexecuting_level():void
    {
        $this->enable();Http::fake(['https://longbridge.com/*'=>Http::response($this->html($this->observed()),200,['Content-Type'=>'text/html'])]);
        $result=app(ExternalReferenceOrderBook::class)->get($this->asset);
        $this->assertSame('closed_snapshot',$result['state']);$this->assertSame('HKD',$result['currency']);$this->assertSame('share',$result['quantity_unit']);
        $this->assertSame(gmdate('c',1790669284),$result['source_time']);$this->assertSame(1,$result['depth_levels']);
        $this->assertTrue($result['reference_only']);$this->assertFalse($result['executable']);$this->assertFalse($result['is_demo']);
        $this->assertSame('https://longbridge.com/zh-CN/quote/8379.HK',$result['source']['source_url']);
        Http::assertSentCount(1);
    }
    public function test_fifteen_minute_delayed_intraday_reference_remains_read_only_but_older_reference_is_cleared():void
    {
        $this->enable();CarbonImmutable::setTestNow('2026-09-29 14:00:00 Asia/Hong_Kong');
        $raw=$this->observed();$raw['timestamp']=(string)(CarbonImmutable::now()->timestamp-900);$raw['trade_status']=100;
        Http::fake(['https://longbridge.com/*'=>Http::response($this->html($raw))]);
        $service=app(ExternalReferenceOrderBook::class);$r=$service->get($this->asset);
        $this->assertSame('delayed',$r['state']);$this->assertSame(900,$r['age_seconds']);$this->assertCount(1,$r['data']['bids']);
        $r=$service->get($this->asset);$this->assertSame(gmdate('c',(int)$raw['timestamp']),$r['source_time']);
        CarbonImmutable::setTestNow('2026-09-29 14:06:00 Asia/Hong_Kong');
        $r=$service->get($this->asset);$this->assertSame('stale',$r['state']);$this->assertSame(['bids'=>[],'asks'=>[]],$r['data']);
    }
    public function test_yesterdays_closed_reference_does_not_hide_a_missing_intraday_feed():void
    {
        $this->enable();CarbonImmutable::setTestNow('2026-09-30 10:00:00 Asia/Hong_Kong');
        Http::fake(['https://longbridge.com/*'=>Http::response($this->html($this->observed()))]);
        $r=app(ExternalReferenceOrderBook::class)->get($this->asset);$this->assertSame('stale',$r['state']);$this->assertEmpty($r['data']['asks']);
    }
    public function test_invalid_identity_zero_quantity_crossed_prices_and_extra_levels_are_rejected():void
    {
        $reader=app(LongbridgeDepthProvider::class);$raw=$this->observed();
        $mutations=[['currency'=>'USDT'],['counter_id'=>'ST/HK/700'],['latency'=>false],['timestamp'=>(string)(CarbonImmutable::now()->timestamp+60)],
            ['bid_depths'=>[['price_level'=>1,'price'=>'0.103','volume'=>'0']]],
            ['bid_depths'=>[['price_level'=>1,'price'=>'0.200','volume'=>'10']]],
            ['ask_depths'=>[$raw['ask_depths'][0],['price_level'=>2,'price'=>'0.120','volume'=>'1']]]];
        foreach ($mutations as $mutation) {
            try {$reader->parseHtml($this->html(array_replace($raw,$mutation)),'08379');$this->fail('Invalid source accepted');}
            catch(\RuntimeException $e){$this->assertNotEmpty($e->getMessage());}
        }
    }
    public function test_javascript_expressions_are_not_evaluated_and_failure_clears_depth():void
    {
        $this->enable();Http::fake(['https://longbridge.com/*'=>Http::response('<script>window.__TANSTACK_DEHYDRATED__.queries.push(alert(1))</script>')]);
        $r=app(ExternalReferenceOrderBook::class)->get($this->asset);$this->assertSame('unavailable',$r['state']);$this->assertEmpty($r['data']['bids']);
    }
    public function test_development_fixture_is_explicitly_labelled_and_production_cannot_use_it():void
    {
        config(['hk-price-products.external_depth.enabled'=>true,'hk-price-products.external_depth.provider'=>'development_fixture']);
        $r=app(ExternalReferenceOrderBook::class)->get($this->asset);$this->assertTrue($r['is_demo']);$this->assertSame('closed_snapshot',$r['state']);
        Http::assertNothingSent();$this->app->instance('env','production');
        $r=app(ExternalReferenceOrderBook::class)->get($this->asset);$this->assertSame('disabled',$r['state']);$this->assertEmpty($r['data']['bids']);Http::assertNothingSent();
    }
}
