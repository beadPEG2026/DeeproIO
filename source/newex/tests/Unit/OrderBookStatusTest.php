<?php
namespace Tests\Unit;
use App\Models\Market\Market;
use App\Services\Market\OrderBookStatus;
use Illuminate\Cache\{ArrayStore, Repository};
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\TestCase;
final class OrderBookStatusTest extends TestCase
{
    public function test_expired_reference_is_distinguished_from_a_genuinely_empty_book(): void {
        Cache::swap(new Repository(new ArrayStore()));
        $m=new Market();$m->name='BTC-USDT';$m->liq=true;$m->stock_token=false;$empty=['bids'=>[],'asks'=>[]];
        $this->assertSame('stale',OrderBookStatus::metadata($m,$empty)['state']);
        $this->assertSame('stale',OrderBookStatus::metadata($m,['bids'=>collect(),'asks'=>collect()])['state']);
        Cache::put('markets_liquidity.BTC-USDT.executable',['received_at'=>time()]);
        $this->assertSame('empty',OrderBookStatus::metadata($m,$empty)['state']);
        Cache::put('markets_liquidity.BTC-USDT.executable',['received_at'=>time()-30]);
        $this->assertSame('stale',OrderBookStatus::metadata($m,$empty)['state']);
        $this->assertSame('ready',OrderBookStatus::metadata($m,['bids'=>[['price'=>'10','quantity'=>'1']],'asks'=>[]])['state']);
        $m->liq=false;$this->assertSame('empty',OrderBookStatus::metadata($m,$empty)['state']);
    }
}
