<?php
namespace Tests\Feature\Deepro;
use Tests\TestCase;
use App\Services\Market\DisplayExchangeRates;
use Illuminate\Support\Facades\Cache;
final class DisplayExchangeRatesTest extends TestCase
{
    protected function setUp():void{parent::setUp();config(['cache.default'=>'array']);Cache::forget('display.fx.history.v1');}
    public function test_all_currency_options_survive_an_outage_without_defaulting_to_one():void
    {
        $o=app(DisplayExchangeRates::class)->options();$this->assertSame(['USDT','USD','CNY','JPY','HKD','EUR'],array_column($o,'symbol'));$this->assertSame(1,$o[0]['rate']);$this->assertNull($o[2]['rate']);
    }
    public function test_historical_conversion_uses_preceding_available_observations_not_latest_rate():void
    {
        $s=['ecb'=>['2026-09-28'=>['USD'=>1.2,'HKD'=>9.6],'2026-09-29'=>['USD'=>1.1,'HKD'=>8.8]],'usdt'=>['2026-09-28'=>1.0,'2026-09-29'=>0.99],'received_at'=>time()];
        $f=app(DisplayExchangeRates::class);$this->assertEquals(.125,$f->historicalFactor(strtotime('2026-09-29 00:00:00 UTC'),$s));
        $this->assertNull($f->historicalFactor(strtotime('2026-10-12 00:00:00 UTC'),$s));
        Cache::put('display.fx.history.v1',$s,300);
        $h=$f->convertHongKongHistory(['s'=>'ok','t'=>[strtotime('2026-09-29 UTC')],'o'=>[8],'h'=>[16],'l'=>[4],'c'=>[12],'v'=>[200]]);
        $this->assertSame('USDT',$h['currency']);$this->assertSame([1.0],$h['o']);$this->assertSame([1.5],$h['c']);$this->assertSame([200],$h['v']);
    }
    public function test_reference_xml_requires_complete_valid_rates_and_rejects_external_entities():void
    {
        $f=app(DisplayExchangeRates::class);$x='<Envelope><Cube><Cube time="2026-09-29"><Cube currency="USD" rate="1.1"/><Cube currency="HKD" rate="8.58"/><Cube currency="CNY" rate="7.7"/><Cube currency="JPY" rate="165"/></Cube></Cube></Envelope>';
        $this->assertSame(8.58,$f->parseEcb($x)['2026-09-29']['HKD']);
        $this->expectException(\RuntimeException::class);$f->parseEcb('<!DOCTYPE x [<!ENTITY x SYSTEM "file:///etc/passwd">]>'.$x);
    }
}
