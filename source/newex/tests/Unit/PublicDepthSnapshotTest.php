<?php
namespace Tests\Unit;
use App\Services\Market\PublicDepthSnapshot;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\TestCase;
final class PublicDepthSnapshotTest extends TestCase
{
    public function test_scientific_low_price_and_unchanged_mapped_price(): void {
        $rows=[['price'=>'4.89E-5','quantity'=>'200000.00000000'],['price'=>'1.2350','quantity'=>'10']];
        $actual=PublicDepthSnapshot::plainPrices($rows,8);
        $this->assertSame('0.00004890',$actual[0]['price']);
        $this->assertSame($rows[0]['quantity'],$actual[0]['quantity']);
        $this->assertSame($rows[1],$actual[1]);
    }
    public function test_quiet_snapshot_requires_a_new_provider_read(): void {
        $this->assertFalse(PublicDepthSnapshot::due(100,109));
        $this->assertTrue(PublicDepthSnapshot::due(100,110));
        $this->assertTrue(PublicDepthSnapshot::due(null,110));
        Http::swap(new Factory());
        $book=['lastUpdateId'=>42,'bids'=>[['0.9998','100']],'asks'=>[['0.9999','200']]];
        Http::fake(['api.binance.com/api/v3/depth*'=>Http::response($book)]);
        $this->assertSame($book,PublicDepthSnapshot::fetch('USDSUSDT'));
        Http::assertSent(fn($r)=>$r['symbol']==='USDSUSDT'&&$r['limit']===20);
    }
    public function test_provider_failure_is_not_reported_as_fresh(): void {
        Http::swap(new Factory());Http::fake(['*'=>Http::response(['code'=>-1],503)]);
        $this->expectException(\Illuminate\Http\Client\RequestException::class);
        PublicDepthSnapshot::fetch('USDEUSDT');
    }
}
