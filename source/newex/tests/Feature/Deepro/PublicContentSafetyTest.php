<?php
namespace Tests\Feature\Deepro;

use App\Services\Content\SafeHtml;
use Illuminate\Support\Facades\{DB, Http};
use Tests\TestCase;

final class PublicContentSafetyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('deepro_test', DB::connection()->getDatabaseName());
        config(['cache.default'=>'array']);
        Http::preventStrayRequests();
        \Illuminate\Support\Facades\Route::getRoutes()->refreshNameLookups();
    }

    public function test_rich_content_keeps_structure_without_executable_markup(): void
    {
        $html=SafeHtml::render('<h2>说明</h2><p style="text-align:center;color:red;position:fixed">正文 <strong>重要</strong></p><table><tr><td>1</td></tr></table><img src="/images/test.png" alt="图" onerror="alert(1)"><script>alert(1)</script><iframe src="https://example.com"></iframe><form><input name="password"></form>');
        foreach (['<h2>说明</h2>','<strong>重要</strong>','text-align:center','<table>','/images/test.png'] as $value) $this->assertStringContainsString($value,$html);
        foreach (['onerror','script','iframe','<form','<input','color:red','position:fixed'] as $value) $this->assertStringNotContainsString($value,$html);
        Http::assertNothingSent();
    }

    public function test_encoded_urls_svg_and_overlay_attributes_are_removed(): void
    {
        $html=SafeHtml::render('<a href="jav&#x61;script:alert(1)">bad</a><a href="https://example.com" target="_blank">ok</a><svg onload="alert(1)"><a xlink:href="javascript:alert(1)">svg</a></svg><div id="app" class="fixed" style="background:url(javascript:alert(1));z-index:999">text</div>');
        foreach (['javascript:','<svg','onload','xlink','id="app"','class="fixed"','z-index','background:'] as $value) $this->assertStringNotContainsString($value,$html);
        $this->assertStringContainsString('href="https://example.com"',$html);
        $this->assertStringContainsString('noopener',$html);
        $this->assertStringContainsString('noreferrer',$html);
    }

    public function test_public_article_and_page_filter_legacy_content_without_mutating_models(): void
    {
        $payload='<p>历史内容</p><img src=x onerror="alert(1)">';
        $article=new \App\Models\Article\Article(['title'=>'Test','body'=>$payload,'created_at'=>now(),'updated_at'=>now()]);
        $article->id=123;$article->created_at=now();$article->updated_at=now();$article->setRelation('file',null);
        $data=(new \App\Http\Resources\Article\Article($article))->resolve();
        $this->assertStringNotContainsString('onerror',$data['body']);
        $this->assertSame($payload,$article->body);
        $page=new \App\Models\Page\Page(['title'=>'Test','is_html'=>true,'html_content'=>$payload]);
        $data=(new \App\Http\Resources\Page\Page($page))->resolve();
        $this->assertStringNotContainsString('onerror',$data['content']);
        $this->assertSame($payload,$page->html_content);
    }

    public function test_null_and_plain_unicode_round_trip_without_network(): void
    {
        $this->assertSame('',SafeHtml::render(null));
        $this->assertSame('安全 &amp; 数据',SafeHtml::render('安全 &amp; 数据'));
        Http::assertNothingSent();
    }

    public function test_chart_symbol_has_explicit_timezone_and_current_volume_metadata(): void
    {
        foreach (['BTC-USDT','UMI-USDT','AAPLon-USDT'] as $name) {
            $data=$this->getJson('/tradingview-chart/symbols?symbol='.$name)->assertOk()->json();
            $this->assertSame('Etc/UTC',$data['timezone']);
            $this->assertSame('ohlcv',$data['visible_plots_set']);
            $this->assertArrayNotHasKey('has_no_volume',$data);
        }
        Http::assertNothingSent();
    }
}
