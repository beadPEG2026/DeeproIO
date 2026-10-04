<?php
namespace Tests\Feature\Deepro;
use App\Models\{Market\Market,Transaction\Transaction};
use App\Services\Chart\TradeCandles;
use Illuminate\Support\Facades\{DB,Http};
use Tests\TestCase;
final class TradeCandlesTest extends TestCase
{
    private Market $market;
    protected function setUp():void {parent::setUp();$this->assertSame('deepro_test',DB::connection()->getDatabaseName());DB::beginTransaction();Http::preventStrayRequests();$this->market=app(\App\Services\Market\HongKongProductListing::class)->apply('HK08379',false);DB::table('transactions')->where('market_id',$this->market->id)->delete();}
    protected function tearDown():void {while(DB::transactionLevel()>0)DB::rollBack();parent::tearDown();}
    private function fill(string $time,string $price,string $qty,array $extra=[]):void {
        $domain=$extra['domain']??'real';unset($extra['domain']);
        $t=Transaction::create(array_replace(['market_id'=>$this->market->id,'process_id'=>generate_uuid(),'order_id'=>generate_uuid(),'user_id'=>1,'is_maker'=>false,'is_volume'=>0,'order_type'=>'limit','order_side'=>'buy','price'=>$price,'base_currency'=>$qty,'quote_currency'=>bcmul($price,$qty,18),'fee'=>'0','referral_fee'=>'0'],$extra));
        if ($t->order_id) DB::table('order_histories')->insert(['id'=>$t->order_id,'market_id'=>$this->market->id,'user_id'=>1,'type'=>'limit','side'=>'buy','base_currency_id'=>$this->market->base_currency_id,'quote_currency_id'=>$this->market->quote_currency_id,'settlement_domain'=>$domain]);
        $local=\Carbon\CarbonImmutable::parse($time,'UTC')->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
        DB::table('transactions')->where('id',$t->id)->update(['created_at'=>$local]);
    }
    public function test_committed_taker_fills_aggregate_once_without_volume_bots_or_empty_bars():void {
        $start=strtotime('2026-09-29T01:30:00Z');
        $this->fill('2026-09-29 01:30:01','.01','2');$this->fill('2026-09-29 01:30:01','.012','3');
        $this->fill('2026-09-29 01:30:01','.012','3',['is_maker'=>true]);
        $this->fill('2026-09-29 01:30:05','999','500',['is_volume'=>1]);
        $this->fill('2026-09-29 01:30:06','999','500',['order_id'=>null]);
        $this->fill('2026-09-29 01:30:07','999','500',['domain'=>'virtual']);
        $this->fill('2026-09-29 01:35:00','.011','4');
        $before=DB::table('transactions')->count();$f=app(TradeCandles::class);
        $minute=$f->history($this->market,$start,$start+600,'1');$this->assertSame([$start,$start+300],$minute['t']);
        $this->assertSame([5.0,4.0],$minute['v']);$this->assertSame(.01,$minute['o'][0]);$this->assertSame(.012,$minute['c'][0]);
        foreach(['15','60','240','1D'] as $resolution) {$bars=$f->history($this->market,$start,$start+600,$resolution);$this->assertCount(1,$bars['t']);$this->assertSame([9.0],$bars['v']);$this->assertSame([.012],$bars['h']);$this->assertSame([.011],$bars['c']);}
        $end=$f->history($this->market,$start,$start+300,'1');$this->assertCount(1,$end['t']);
        $this->assertSame('USDT',$minute['currency']);$this->assertFalse($minute['reference_only']);Http::assertNothingSent();$this->assertSame($before,DB::table('transactions')->count());
    }
    public function test_empty_book_does_not_create_trade_candles_and_countback_returns_actual_older_fills():void {
        $start=strtotime('2026-09-29T01:30:00Z');$f=app(TradeCandles::class);
        $this->assertSame('no_data',$f->history($this->market,$start,$start+3600,'1')['s']);
        $this->fill('2026-09-29 01:30:01','.01','2');
        $this->getJson('/tradingview-chart/trades/history?symbol=HK08379-USDT&from='.($start+60).'&to='.($start+3600).'&resolution=1&countback=10')->assertOk()->assertJsonPath('c.0',.01)->assertJsonPath('currency','USDT');
    }
}
