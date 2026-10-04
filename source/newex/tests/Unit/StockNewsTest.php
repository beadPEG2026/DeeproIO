<?php
namespace Tests\Unit;
use App\Services\Market\StockNews;
use PHPUnit\Framework\TestCase;

final class StockNewsTest extends TestCase
{
    private function item(string $url,string $title='Headline',?int $time=null):string {
        return '<item><title><![CDATA['.$title.']]></title><link>'.htmlspecialchars($url,ENT_XML1).'</link><pubDate>'.gmdate(DATE_RSS,$time??time()-60).'</pubDate></item>';
    }
    public function test_only_safe_source_links_sorted_deduplicated_and_no_article_html():void {
        $xml='<rss><channel>'.$this->item('https://finance.yahoo.com/news/test','<b>First</b>')
            .$this->item('https://finance.yahoo.com/news/test','<b>First</b>')
            .$this->item('https://hk.finance.yahoo.com/news/hk','港股资讯',time()-20)
            .$this->item('javascript:alert(1)').$this->item('https://yahoo.com.example.org/attack').'</channel></rss>';
        $items=(new StockNews)->parse($xml,'US');$this->assertCount(2,$items);$this->assertSame('港股资讯',$items[0]['title']);
        $this->assertSame('First',$items[1]['title']);$this->assertSame('US',$items[0]['region']);
    }
    public function test_xml_external_entities_are_rejected():void {
        $this->expectException(\UnexpectedValueException::class);
        (new StockNews)->parse('<!DOCTYPE a [<!ENTITY e SYSTEM "file:///etc/passwd">]><rss><channel/></rss>','US');
    }
    public function test_future_dated_items_and_credential_links_are_not_shown():void {
        $items=(new StockNews)->parse('<rss><channel>'.$this->item('https://finance.yahoo.com/future','Future',time()+86400)
            .$this->item('https://a:b@finance.yahoo.com/test').'</channel></rss>','US');$this->assertSame([],$items);
    }
    public function test_hong_kong_feed_only_accepts_exchange_headlines():void {
        $items=(new StockNews)->parse('<rss><channel>'.$this->item('https://www.hkex.com.hk/News/News-Release/2026/news','港交所市场公告')
            .$this->item('https://finance.yahoo.com/news/us','US headlines').$this->item('https://www.hkex.com.hk.example.org/news').'</channel></rss>','HK');
        $this->assertCount(1,$items);$this->assertSame('香港交易所',$items[0]['source']);
    }
    public function test_radio_feed_is_source_scoped_and_rejects_spoofed_hosts():void {
        $xml='<rss><channel>'.$this->item('https://news.rthk.hk/rthk/ch/component/k2/news.htm','港股收市')
            .$this->item('https://news.rthk.hk.example.org/news').$this->item('https://www.hkex.com.hk/news').'</channel></rss>';
        $items=(new StockNews)->parse($xml,'HK','rthk');$this->assertCount(1,$items);$this->assertSame('香港电台',$items[0]['source']);
    }
}
