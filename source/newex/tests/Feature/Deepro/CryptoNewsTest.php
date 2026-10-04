<?php

namespace Tests\Feature\Deepro;

use App\Services\Market\CryptoNews;
use Illuminate\Support\Facades\{Cache, Http};
use Tests\TestCase;

final class CryptoNewsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('t', 32)), 'cache.default' => 'array', 'database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        Http::preventStrayRequests();
        $this->travelTo(\Carbon\Carbon::parse('2026-10-03T01:00:00Z'));
    }

    protected function tearDown(): void { $this->travelBack(); parent::tearDown(); }

    private function item(string $slug = 'real-headline', string $date = 'Fri, 02 Oct 2026 20:46:58 +0000', ?string $url = null): string
    {
        return '<item><title><![CDATA[<b>Real</b> &amp; verified headline]]></title><link>'.htmlspecialchars($url ?? 'https://cointelegraph.com/news/'.$slug, ENT_XML1).'</link><pubDate>'.$date.'</pubDate><description>NEVER_COPY_ARTICLE_BODY</description></item>';
    }

    private function feed(string $items): string { return '<rss version="2.0"><channel><title>Cointelegraph.com News</title>'.$items.'</channel></rss>'; }

    public function test_localized_feeds_are_isolated_and_never_fall_back_to_english(): void
    {
        $news=new CryptoNews();
        foreach (['zh-cn'=>'zh','zh-tw'=>'zh-hant','ja'=>'ja'] as $locale=>$path) {
            $url='https://www.panewslab.com/'.$path.'/articles/valid-local-headline';
            Http::fake([$news->source($locale)['url']=>Http::response($this->feed($this->item(url:$url).$this->item()))]);
            $snapshot=$news->refresh($locale);
            $this->assertCount(1,$snapshot['items']);$this->assertSame($locale,$snapshot['items'][0]['locale']);
            $this->assertSame('PANews',$snapshot['source']['name']);$this->assertSame($url,$snapshot['items'][0]['url']);
            app()->setLocale($locale);
            $this->withoutMiddleware()->getJson('/markets/data/crypto-news')->assertOk()->assertJsonPath('locale',$locale)->assertJsonPath('items.0.url',$url);
        }
        $this->assertSame([],$news->snapshot('en')['items']);$this->assertSame([],$news->snapshot('de')['items']);
    }

    public function test_public_endpoint_is_cache_only_and_does_not_invent_empty_times(): void
    {
        $response = $this->withoutMiddleware()->getJson('/markets/data/crypto-news');
        $response->assertOk()->assertJsonPath('items', [])->assertJsonPath('fetchedAt', null)
            ->assertJsonPath('lastAttemptAt', null)->assertJsonPath('state', 'unavailable');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        Http::assertNothingSent();
    }

    public function test_refresh_separates_source_publication_and_fetch_time_and_deduplicates_tracking_links(): void
    {
        Http::fake([CryptoNews::FEED_URL => Http::response($this->feed($this->item().$this->item(url:'https://cointelegraph.com/news/real-headline?utm_source=rss#fragment')))]);
        $news = new CryptoNews(); $first = $news->refresh();
        $this->assertCount(1, $first['items']);
        $this->assertSame('Real & verified headline', $first['items'][0]['title']);
        $this->assertSame('https://cointelegraph.com/news/real-headline', $first['items'][0]['url']);
        $this->assertSame('2026-10-02T20:46:58+00:00', $first['items'][0]['publishedAt']);
        $this->assertSame(now()->toIso8601String(), $first['fetchedAt']);
        $this->assertSame('fresh', $first['state']);
        $this->assertStringNotContainsString('NEVER_COPY_ARTICLE_BODY', json_encode($first));
        $this->travel(299)->seconds(); $this->assertSame($first, $news->refresh());
        $news->snapshot(); Http::assertSentCount(1);
    }

    public function test_unsafe_links_invalid_dates_old_and_future_content_are_rejected(): void
    {
        $bad = '';
        foreach (['javascript:alert(1)', 'https://evil.test/news/a', 'https://cointelegraph.com.evil.test/news/a', 'https://user:pass@cointelegraph.com/news/a', 'https://cointelegraph.com:443/news/a', 'https://cointelegraph.com/news/../login', 'https://cointelegraph.com/news/%2e%2e', '//cointelegraph.com/news/a', "https://cointelegraph.com/news/a\n"] as $url) {
            // Raw surrounding whitespace is harmless after trim; embed controls to exercise URL validation.
            if (str_contains($url, "\n")) $url = "https://cointelegraph.com/news/a\nb";
            $bad .= $this->item(url:$url);
        }
        foreach (['', 'now', 'Fri, 02 Oct 2026 20:46:58', 'Sat, 03 Oct 2026 02:00:00 +0000', 'Fri, 18 Sep 2026 20:46:58 +0000'] as $date) $bad .= $this->item(date:$date);
        $this->assertSame([], (new CryptoNews())->parse($this->feed($bad)));
        $good = (new CryptoNews())->parse($this->feed($this->item(date:'Sat, 03 Oct 2026 04:46:58 +0800')));
        $this->assertSame('2026-10-02T20:46:58+00:00', $good[0]['publishedAt']);
    }

    public function test_xml_entities_malformed_and_oversized_responses_are_rejected(): void
    {
        foreach (['<!DOCTYPE rss [<!ENTITY secret SYSTEM "file:///etc/passwd">]><rss/>', '<rss><broken>', str_repeat('x', 2000001)] as $xml) {
            try { (new CryptoNews())->parse($xml); $this->fail('Unsafe feed accepted'); }
            catch (\UnexpectedValueException $e) { $this->assertTrue(true); }
        }
    }

    public function test_failure_keeps_last_good_headlines_marks_stale_and_backs_off_then_recovers(): void
    {
        Http::fake([CryptoNews::FEED_URL => Http::sequence()->push($this->feed($this->item()))->push('', 503)->push('', 429)->push($this->feed($this->item('new-headline')))]);
        $news = new CryptoNews(); $first = $news->refresh();
        $this->travel(300)->seconds(); $failed = $news->refresh();
        $this->assertSame('stale', $failed['state']); $this->assertSame($first['items'], $failed['items']);
        $this->assertSame($first['fetchedAt'], $failed['fetchedAt']); $this->assertNotSame($first['lastAttemptAt'], $failed['lastAttemptAt']);
        $this->travel(300)->seconds(); $news->refresh();
        $this->travel(599)->seconds(); $news->refresh(); Http::assertSentCount(3);
        $this->travel(1)->seconds(); $recovered = $news->refresh(); Http::assertSentCount(4);
        $this->assertSame('fresh', $recovered['state']); $this->assertNotSame($first['fetchedAt'], $recovered['fetchedAt']);
        $this->assertSame('2026-10-02T20:46:58+00:00', $recovered['items'][0]['publishedAt']);
    }

    public function test_reading_old_cache_never_refreshes_timestamp_or_contacts_provider(): void
    {
        Http::fake([CryptoNews::FEED_URL => Http::response($this->feed($this->item()))]);
        $news = new CryptoNews(); $first = $news->refresh();
        $this->travel(900)->seconds(); $old = $news->snapshot();
        $this->assertSame('stale', $old['state']); $this->assertSame($first['fetchedAt'], $old['fetchedAt']);
        $this->withoutMiddleware()->getJson('/markets/data/crypto-news')->assertOk()->assertJsonPath('state', 'stale');
        Http::assertSentCount(1);
    }

    public function test_lock_and_failed_cold_source_bound_requests_without_fake_content(): void
    {
        $lock = Cache::lock(CryptoNews::CACHE_KEY.'.lock', 30); $this->assertTrue($lock->get());
        $news = new CryptoNews(); $this->assertSame('unavailable', $news->refresh()['state']); Http::assertNothingSent(); $lock->release();
        Http::fake([CryptoNews::FEED_URL => Http::response('<rss><channel/></rss>')]);
        $failed = $news->refresh(); $this->assertSame([], $failed['items']); $this->assertNull($failed['fetchedAt']);
        $this->assertNotNull($failed['lastAttemptAt']); $news->refresh(); Http::assertSentCount(1);
    }

    public function test_timeout_and_redirect_cannot_erase_last_good_or_expose_raw_provider_errors(): void
    {
        Http::fake([CryptoNews::FEED_URL => Http::response($this->feed($this->item()))]);
        $news = new CryptoNews(); $first = $news->refresh();
        $this->travel(300)->seconds();
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('PRIVATE_PROVIDER_ERROR_SENTINEL'));
        $failed = $news->refresh();
        $this->assertSame($first['items'], $failed['items']); $this->assertSame('stale', $failed['state']);
        $this->assertSame($first['fetchedAt'], $failed['fetchedAt']);
        $this->assertStringNotContainsString('PRIVATE_PROVIDER_ERROR_SENTINEL', json_encode(Cache::get(CryptoNews::CACHE_KEY)));
        $this->travel(300)->seconds();
        Http::fake([CryptoNews::FEED_URL => Http::response('', 302, ['Location' => 'http://127.0.0.1/private'])]);
        $this->assertSame($first['items'], $news->refresh()['items']);
        Http::assertNotSent(fn ($request) => $request->url() !== CryptoNews::FEED_URL);
    }

    public function test_expired_content_is_hidden_even_when_repeated_failures_extend_cache_lifetime(): void
    {
        Cache::put(CryptoNews::CACHE_KEY, ['items' => [['publishedAt' => '2026-09-25T00:00:00Z', 'title' => 'Old']],
            'fetchedAt' => '2026-09-25T01:00:00Z', 'lastError' => 'source_unavailable'], 604800);
        $snapshot = (new CryptoNews())->snapshot();
        $this->assertSame([], $snapshot['items']); $this->assertSame('unavailable', $snapshot['state']);
        $this->assertSame('2026-09-25T01:00:00Z', $snapshot['fetchedAt']); Http::assertNothingSent();
    }

    public function test_completion_based_natural_cycles_refresh_each_time_without_bypassing_existing_cooldown(): void
    {
        $durations = [2, 8, 1, 6, 3, 8, 2, 7, 1, 4, 8, 2, 5, 1, 7, 3, 8, 1, 4, 6];
        $requests = 0;
        Http::fake(function ($request) use (&$requests, $durations) {
            if ($request->url() !== CryptoNews::FEED_URL) {
                parse_str(parse_url($request->url(),PHP_URL_QUERY),$query);
                return Http::response($this->feed($this->item(url:'https://www.panewslab.com/'.$query['lang'].'/articles/local-headline')));
            }
            // The real command/service writes cooldown at response completion.
            $this->travel($durations[$requests++])->seconds();
            return Http::response($this->feed($this->item()));
        });
        $this->travel(30)->seconds(); // Initial OnActiveSec, not a manual warm.
        $previousFetched = null;
        foreach ($durations as $index => $duration) {
            $started = now()->toIso8601String();
            $this->artisan('market:refresh-crypto-news')->assertSuccessful();
            $saved = Cache::get(CryptoNews::CACHE_KEY);
            $this->assertSame($index + 1, $requests, 'Every natural invocation must actually fetch');
            $this->assertSame($started, $saved['lastAttemptAt']);
            $this->assertSame(now()->toIso8601String(), $saved['fetchedAt']);
            $this->assertNotSame($previousFetched, $saved['fetchedAt']);
            $this->assertSame(now()->timestamp + 300, $saved['nextAttemptAt'], 'Existing successful cooldown is preserved');
            $previousFetched = $saved['fetchedAt'];
            // OnUnitInactiveSec starts here, after the command finishes, not at
            // the previous start/calendar boundary. Runtime cannot accumulate.
            $this->travel(300)->seconds();
        }
        Http::assertSentCount(count($durations)*count(CryptoNews::LOCALES));
    }

    public function test_refresh_command_is_registered_and_limits_sorted_headlines_to_twelve(): void
    {
        $items = '';
        for ($i = 0; $i < 20; $i++) $items .= $this->item('headline-'.$i, sprintf('Fri, 02 Oct 2026 20:%02d:00 +0000', $i));
        Http::fake(function($request) use($items) {
            if($request->url()===CryptoNews::FEED_URL)return Http::response($this->feed($items));
            parse_str(parse_url($request->url(),PHP_URL_QUERY),$query);
            return Http::response($this->feed($this->item(url:'https://www.panewslab.com/'.$query['lang'].'/articles/local-headline')));
        });
        $this->artisan('market:refresh-crypto-news')->assertSuccessful();
        $snapshot = (new CryptoNews())->snapshot();
        $this->assertCount(12, $snapshot['items']);
        $this->assertSame('https://cointelegraph.com/news/headline-19', $snapshot['items'][0]['url']);
        $this->assertSame('https://cointelegraph.com/news/headline-8', $snapshot['items'][11]['url']);
    }
}
