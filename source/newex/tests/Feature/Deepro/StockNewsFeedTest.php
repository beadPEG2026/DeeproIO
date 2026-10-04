<?php
namespace Tests\Feature\Deepro;
use App\Services\Market\StockNews;
use Illuminate\Support\Facades\{Cache,Http};
use Tests\TestCase;

final class StockNewsFeedTest extends TestCase
{
    protected function setUp():void {parent::setUp();config(['cache.default'=>'array']);Cache::flush();Http::preventStrayRequests();}
    private function xml(string $host):string {return '<rss><channel><item><title>Company update</title><link>https://'.$host.'/news/update</link><pubDate>'.gmdate(DATE_RSS,time()-60).'</pubDate></item></channel></rss>';}
    private function feeds():array {return ['feeds.finance.yahoo.com/*'=>Http::response($this->xml('finance.yahoo.com')),'www.hkex.com.hk/*'=>Http::response($this->xml('www.hkex.com.hk')),'rthk.hk/*'=>Http::response($this->xml('news.rthk.hk'))];}
    public function test_page_requests_only_read_cache_and_command_refreshes_both_regions():void {
        $service=app(StockNews::class);$this->assertSame([],$service->snapshot('HK')['items']);Http::assertNothingSent();
        Http::fake($this->feeds());$this->artisan('market:refresh-stock-news')->assertSuccessful();
        Http::assertSentCount(3);$this->assertCount(1,$service->snapshot('US')['items']);$this->assertCount(2,$service->snapshot('HK')['items']);
        for($i=0;$i<10;$i++)$service->snapshot('HK');Http::assertSentCount(3);
        $this->artisan('market:refresh-stock-news')->assertSuccessful();Http::assertSentCount(3);
    }
    public function test_source_failure_keeps_original_headlines_and_does_not_fake_update_time():void {
        $key=StockNews::CACHE_PREFIX.'HK';$saved=['items'=>[['title'=>'Existing','publishedAt'=>'2026-09-29T00:00:00+00:00']],'updatedAt'=>'2026-09-29T00:05:00+00:00'];Cache::put($key,$saved,86400);
        Http::fake(['*'=>Http::response([],503)]);$service=app(StockNews::class);
        $this->assertSame($saved,$service->refresh('HK'));$this->assertSame($saved,$service->refresh('HK'));Http::assertSentCount(2);
    }
    public function test_partial_failure_retains_that_sources_previous_articles():void {
        $service=app(StockNews::class);Cache::put(StockNews::CACHE_PREFIX.'HK.hkex',$service->parse($this->xml('www.hkex.com.hk'),'HK'),86400);
        Http::fake(['www.hkex.com.hk/*'=>Http::response([],503),'rthk.hk/*'=>Http::response(str_replace('update','new',$this->xml('news.rthk.hk')))]);
        $result=$service->refresh('HK');$this->assertTrue($result['partial']);$this->assertCount(2,$result['items']);
        $this->assertContains('香港交易所',array_column($result['items'],'source'));
    }
    public function test_unknown_region_never_fetches_remote_url():void {
        try{app(StockNews::class)->snapshot('https://example.org');$this->fail('Arbitrary source accepted');}catch(\InvalidArgumentException $e){Http::assertNothingSent();}
    }
    public function test_scheduler_registers_five_minute_background_refresh():void {
        $schedule=app(\Illuminate\Console\Scheduling\Schedule::class);
        $events=collect($schedule->events())->filter(fn($e)=>str_contains($e->command??'','market:refresh-stock-news'));
        $this->assertCount(1,$events);$event=$events->first();$this->assertSame('*/5 * * * *',$event->expression);$this->assertTrue($event->runInBackground);
    }
}
