<?php
namespace Tests\Feature\Deepro;
use App\Models\Market\Market;
use App\Models\Transaction\Transaction;
use Illuminate\Support\Facades\{DB,Http};
use Tests\TestCase;

final class StockMarketTradesTest extends TestCase
{
    private Market $market;
    protected function setUp(): void {
        parent::setUp();
        $this->assertSame('deepro_test', DB::connection()->getDatabaseName());
        DB::beginTransaction(); Http::preventStrayRequests();
        $this->market = Market::whereName('GOOGLon-USDT')->firstOrFail();
        DB::table('transactions')->where('market_id', $this->market->id)->delete();
    }
    protected function tearDown(): void {while(DB::transactionLevel()>0)DB::rollBack();parent::tearDown();}
    private function feed(): array {
        return ['source_symbol'=>'ALPHA_740USDT','received_at'=>now()->toIso8601String(),'stale'=>false,
            'data'=>[['id'=>'10','price'=>'342.17000000','quantity'=>'0.21920000','side'=>'buy',
                'timestamp'=>now()->getTimestampMs(),'created_at'=>now()->toIso8601String()]]];
    }
    private function endpoint(): string {return '/api/v1/markets/historical/trades?market=GOOGLon-USDT';}
    public function test_external_trades_display_without_local_fills_and_never_change_ledger(): void {
        Http::fake(['*/v1/stocks/trades'=>Http::response($this->feed())]);
        $wallets = DB::table('wallets')->sum('balance_in_trade');
        $fills = DB::table('platform_credit_fills')->count();
        for($i=0;$i<2;$i++) $this->getJson($this->endpoint())->assertOk()->assertJsonCount(1,'trades')
            ->assertJsonPath('trades.0.id','binance-alpha:10')->assertJsonPath('trades.0.quantity','0.21920000');
        $this->assertSame(0, DB::table('transactions')->where('market_id',$this->market->id)->count());
        $this->assertEquals($wallets,DB::table('wallets')->sum('balance_in_trade'));
        $this->assertSame($fills,DB::table('platform_credit_fills')->count());
        Http::assertSent(fn($r)=>str_ends_with($r->url(),'/v1/stocks/trades')&&$r['symbol']==='GOOGLon'&&$r['limit']===30);
    }
    private function localTrade(bool $maker=false): Transaction {
        return Transaction::create(['process_id'=>generate_uuid(),'market_id'=>$this->market->id,'is_maker'=>$maker,
            'order_side'=>'buy','order_type'=>'limit','price'=>'342','base_currency'=>'1','quote_currency'=>'342',
            'fee'=>'0','referral_fee'=>'0','is_volume'=>0]);
    }
    public function test_local_and_external_trades_merge_without_double_counting_maker(): void {
        Http::fake(['*/v1/stocks/trades'=>Http::response($this->feed())]);
        $local=$this->localTrade();$this->localTrade(true);
        $response=$this->getJson($this->endpoint())->assertOk()->assertJsonCount(2,'trades');
        $this->assertContains('deepro:'.$local->id,array_column($response->json('trades'),'id'));
        $this->getJson('/api/v1/markets/trades?market=GOOGLon-USDT')->assertOk()->assertJsonCount(1,'data');
    }
    public function test_provider_outage_keeps_local_fills_and_exposes_delayed_status(): void {
        Http::fake(['*/v1/stocks/trades'=>Http::response(['error'=>'offline'],502)]);
        $local=$this->localTrade();
        $this->getJson($this->endpoint())->assertOk()->assertJsonPath('stale',true)
            ->assertJsonCount(1,'trades')->assertJsonPath('trades.0.id','deepro:'.$local->id);
    }
    public function test_cached_provider_snapshot_keeps_original_trade_time(): void {
        $feed=$this->feed();$feed['stale']=true;$feed['data'][0]['timestamp']=1700000000000;
        $feed['data'][0]['created_at']='2023-11-14T22:13:20.000Z';
        Http::fake(['*/v1/stocks/trades'=>Http::response($feed)]);
        $this->getJson($this->endpoint())->assertOk()->assertJsonPath('stale',true)
            ->assertJsonPath('trades.0.timestamp',1700000000000);
    }
}
