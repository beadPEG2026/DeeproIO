<?php
namespace Tests\Feature\Deepro;

use App\Models\{Market\Market,Order\Order,User\User,Wallet\Wallet};
use App\Services\Market\{PlatformCredit,FundedLiquidity,StablecoinOrientation,StablecoinMarketCutover};
use Illuminate\Support\Facades\{DB,Cache,Event,Http,Queue,Route};
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class InverseStablecoinTest extends TestCase
{
    private Market $market;
    private User $user;
    protected function setUp():void
    {
        parent::setUp();$this->assertSame('deepro_test',DB::connection()->getDatabaseName());
        config(['cache.default'=>'array','broadcasting.default'=>'log']);
        DB::beginTransaction();Event::fake();Queue::fake();Http::preventStrayRequests();
        $old=Market::whereName('USDC-USDT')->firstOrFail();
        DB::table('platform_credit_pools')->where('id',1)->update(['default_for_usdt'=>true]);
        $this->market=app(StablecoinMarketCutover::class)->prepare();$this->market->update(['liq'=>true,'trade_status'=>true,'status'=>true]);
        DB::table('orders')->where('market_id',$this->market->id)->delete();
        $this->user=User::withoutEvents(fn()=>User::factory()->create(['email_verified_at'=>now(),'vip'=>0,'referral_id'=>null]));
        foreach ([$this->market->base_currency_id,$this->market->quote_currency_id] as $id)Wallet::factory()->create(['user_id'=>$this->user->id,'currency_id'=>$id,'balance_in_trade'=>$id===$this->market->base_currency_id?'1000':'10000']);
        DB::table('platform_credit_pools')->where('id',1)->update(['enabled'=>true,'default_for_usdt'=>false,'credit_limit'=>'5000000','quote_position'=>'0','realized_pnl'=>'0']);
        DB::table('platform_credit_positions')->delete();
        DB::table('platform_reference_consumption')->where('market_id',$this->market->id)->delete();
        DB::table('market_execution_policies')->updateOrInsert(['market_id'=>$this->market->id],['mode'=>'platform_credit','maker_user_id'=>null,'max_quote_per_fill'=>'1000','created_at'=>now(),'updated_at'=>now()]);
        $this->book();Sanctum::actingAs($this->user,['trade']);
    }
    protected function tearDown():void {while(DB::transactionLevel()>0)DB::rollBack();parent::tearDown();}
    private function book(string $ask='0.8',string $bid='0.79',string $quantity='5000'):void
    {
        Cache::put("markets_liquidity.{$this->market->name}.received_at",time());
        Cache::put("markets_liquidity.{$this->market->name}.asks",[['price'=>$ask,'quantity'=>$quantity]]);
        Cache::put("markets_liquidity.{$this->market->name}.bids",[['price'=>$bid,'quantity'=>$quantity]]);
    }
    private function place(string $side,string $type,array $d) {return $this->postJson('/api/v1/orders',['market'=>$this->market->name,'side'=>$side,'type'=>$type]+$d);}
    private function wallet(int $id):Wallet {return Wallet::where('user_id',$this->user->id)->where('currency_id',$id)->firstOrFail();}
    private function state():array {return app(PlatformCredit::class)->summary();}
    public function test_history_endpoint_preserves_true_inverse_ohlc_without_legacy_wick_transforms(): void
    {
        $raw=['s'=>'ok','t'=>[1791019800,1791020700],'o'=>[0.8,1.1],'h'=>[2,1.5],'l'=>[0.5,0.9],'c'=>[1.25,1.2],'v'=>[200,100],'qv'=>[180,120]];
        $this->mock(\App\Services\Chart\ExternalCandleService::class)->shouldReceive('getCandles')->once()->andReturn($raw);
        $r=$this->getJson('/tradingview-chart/history?symbol=USDT-USDC&resolution=15&from=1791019799&to=1791021600')->assertOk();
        $this->assertEquals(StablecoinOrientation::candles($raw),$r->json());
        $this->assertNotEquals($r->json('c.0'),$r->json('o.1'));
        Http::assertNothingSent();
    }
    public function test_cutover_review_ignores_ticker_updates_but_keeps_configuration_and_orders_exact(): void
    {
        $s=app(StablecoinMarketCutover::class);$hash=$s->snapshot()['sha256'];
        Market::whereName('USDC-USDT')->update(['last'=>'1.00014','updated_at'=>now()->addSecond()]);
        $this->market->update(['last'=>'0.99986','updated_at'=>now()->addSeconds(2)]);
        $this->assertSame($hash,$s->snapshot()['sha256']);
        $this->market->update(['quote_precision'=>7]);
        $this->assertNotSame($hash,$s->snapshot()['sha256']);
    }
    public function test_last_price_refresh_uses_source_reciprocal_and_skips_retired_market(): void
    {
        DB::table('markets')->where('id','!=',$this->market->id)->update(['status'=>false]);
        $old=Market::whereName('USDC-USDT')->firstOrFail();
        $oldLast=$old->last;
        Http::fake(['api.binance.com/*'=>Http::response(['prevClosePrice'=>'1.25'])]);
        $this->artisan('market:last-price-update')->assertExitCode(0);
        $this->assertEqualsWithDelta(0.8,(float)$this->market->fresh()->last,0.000000001);
        $this->assertSame($oldLast,$old->fresh()->last);
        Http::assertSentCount(1);
        Http::assertSent(fn($request)=>$request['symbol']==='USDCUSDT');
    }
    private function assertJournal():void
    {
        $this->assertSame(0,DB::table('platform_credit_entries')->whereRaw('platform_delta+user_delta+fee_delta != 0')->count());
        Http::assertNothingSent();
    }
    public function test_buy_usdt_spends_usdc_and_posts_exact_usdt_cash_and_usdc_position(): void
    {
        $id=$this->place('buy','limit',['quantity'=>'100','price'=>'0.8'])->assertOk()->json('message');
        $fill=DB::table('transactions')->where('order_id',$id)->sole();
        $this->assertSame(0,bccomp($this->wallet($this->market->base_currency_id)->balance_in_trade,'1100',18));
        $this->assertSame(0,bccomp($this->wallet($this->market->quote_currency_id)->balance_in_trade,bcsub('9920',$fill->fee,18),18));
        $pool=$this->state();$this->assertSame(0,bccomp($pool['quote_position'],'-100',18));
        $p=$pool['positions'][0];$this->assertEquals($this->market->quote_currency_id,$p['currency_id']);
        $this->assertSame(0,bccomp($p['quantity'],'80',18));$this->assertSame(0,bccomp($p['average_price'],'1.25',18));
        $this->assertSame(0,bccomp($pool['used'],'100',18));$this->assertJournal();
    }
    public function test_sell_usdt_receives_usdc_and_short_is_marked_in_usdt(): void
    {
        $id=$this->place('sell','market',['quantity'=>'100'])->assertOk()->json('message');
        $fill=DB::table('transactions')->where('order_id',$id)->sole();
        $this->assertSame(0,bccomp($this->wallet($this->market->base_currency_id)->balance_in_trade,'900',18));
        $this->assertSame(0,bccomp($this->wallet($this->market->quote_currency_id)->balance_in_trade,bcsub('10079',$fill->fee,18),18));
        $s=$this->state();$this->assertSame(0,bccomp($s['quote_position'],'100',18));
        $this->assertSame(0,bccomp($s['positions'][0]['quantity'],'-79',18));$this->assertTrue($s['marks_fresh']);
        $this->assertEqualsWithDelta(100,(float)$s['used'],0.00000001);$this->assertJournal();
    }
    public function test_shared_credit_limit_caps_base_usdt_and_cancel_refunds_usdc(): void
    {
        DB::table('platform_credit_pools')->where('id',1)->update(['credit_limit'=>'50']);
        $id=$this->place('buy','market',['quoteQuantity'=>'100'])->assertOk()->json('message');
        $this->assertSame(0,bccomp((string)DB::table('transactions')->where('order_id',$id)->sum('base_currency'),'50',8));
        $this->assertSame(0,bccomp($this->state()['used'],'50',18));
        $this->assertSame(0,bccomp($this->wallet($this->market->quote_currency_id)->balance_in_order,'0',18));
        $before=$this->wallet($this->market->quote_currency_id)->balance_in_trade;
        $id=$this->place('buy','limit',['quantity'=>'10','price'=>'0.7'])->assertOk()->json('message');
        $this->postJson('/api/v1/orders/cancel',['uuid'=>$id])->assertOk();
        $this->assertSame($before,$this->wallet($this->market->quote_currency_id)->balance_in_trade);$this->assertJournal();
    }
    public function test_fill_cap_retains_usdt_denomination_after_pair_reversal(): void
    {
        $book=app(FundedLiquidity::class)->levels($this->market);
        $this->assertSame(0,bccomp($book['asks'][0]['quantity'],'1000',18));
        $this->assertSame(0,bccomp($book['bids'][0]['quantity'],'1000',18));
    }
    public function test_non_unit_price_preserves_exact_cash_and_roundtrip_positions(): void
    {
        $this->book('0.99812345','0.99802345');
        $this->place('buy','limit',['quantity'=>'123.4567','price'=>'0.99812345'])->assertOk();
        $this->assertSame(0,bccomp($this->state()['quote_position'],'-123.4567',18));
        $this->place('sell','market',['quantity'=>'123.4567'])->assertOk();
        $s=$this->state();$this->assertSame(0,bccomp($s['quote_position'],'0',18));
        $this->assertSame(0,bccomp($s['positions'][0]['quantity'],'0.01234567',18));
        $this->assertSame(0,bccomp($s['used'],'0',18));$this->assertJournal();
    }
    public function test_old_usdc_short_is_marked_with_inverse_bid_after_retirement(): void
    {
        $old=Market::whereName('USDC-USDT')->firstOrFail();$old->update(['status'=>false,'trade_status'=>false]);
        DB::table('platform_credit_positions')->insert(['pool_id'=>1,'currency_id'=>$old->base_currency_id,'market_id'=>$old->id,'quantity'=>'-79','average_price'=>'1','risk_price'=>'1','created_at'=>now(),'updated_at'=>now()]);
        $s=$this->state();$this->assertTrue($s['marks_fresh']);$this->assertEqualsWithDelta(100,(float)$s['used'],0.00000001);
        Cache::put("markets_liquidity.{$this->market->name}.received_at",time()-60);
        $this->assertFalse($this->state()['marks_fresh']);$this->place('sell','market',['quantity'=>'100'])->assertStatus(422);
    }
    public function test_depth_ticker_candles_swap_sides_extremes_and_true_volume(): void
    {
        $book=StablecoinOrientation::depth([['0.8','10']],[['1.25','12']]);
        $this->assertSame('0.800000000000000000',$book['bids'][0][0]);$this->assertSame('15.000000000000000000',$book['bids'][0][1]);
        $this->assertSame('1.250000000000000000',$book['asks'][0][0]);$this->assertSame('8.000000000000000000',$book['asks'][0][1]);
        $stats=StablecoinOrientation::ticker(['open'=>'0.8','close'=>'1.25','high'=>'2','low'=>'0.5','volume'=>'200','qVolume'=>'180']);
        $this->assertSame('1.250000000000000000',$stats['open']);$this->assertSame('0.800000000000000000',$stats['close']);
        $this->assertSame('2.000000000000000000',$stats['high']);$this->assertSame('0.500000000000000000',$stats['low']);
        $this->assertSame('180',$stats['volume']);$this->assertSame('200',$stats['qVolume']);
        $bars=StablecoinOrientation::candles(['s'=>'ok','t'=>[1],'o'=>[0.8],'h'=>[2],'l'=>[0.5],'c'=>[1.25],'v'=>[200],'qv'=>[180]]);
        $this->assertEquals([1.25],$bars['o']);$this->assertEquals([2],$bars['h']);$this->assertEquals([0.5],$bars['l']);$this->assertEquals([0.8],$bars['c']);$this->assertEquals([180],$bars['v']);
        $this->assertArrayNotHasKey('qv',$bars);
        $this->expectException(\InvalidArgumentException::class);StablecoinOrientation::reciprocal('0');
    }
    public function test_cutover_requires_fresh_reference_and_preserves_old_market_identity(): void
    {
        $service=app(StablecoinMarketCutover::class);$old=Market::whereName('USDC-USDT')->firstOrFail();$old->update(['status'=>true,'trade_status'=>true]);
        $newId=$this->market->id;$this->assertSame($newId,$service->prepare()->id);
        $this->market->update(['status'=>false,'trade_status'=>false]);
        try {$service->apply($service->snapshot()['sha256']);$this->fail('stale reference accepted');}catch(\RuntimeException $e){$this->assertSame('STABLECOIN_FRESH_REFERENCE_REQUIRED',$e->getMessage());}
        $this->assertTrue($old->fresh()->status);$this->assertFalse($this->market->fresh()->status);
        Cache::put('markets_liquidity.USDT-USDC.executable',['received_at'=>time(),'snapshot'=>'qa-cutover','bids'=>[['price'=>'0.79','quantity'=>'1000']],'asks'=>[['price'=>'0.8','quantity'=>'1000']]]);
        $result=$service->apply($service->snapshot()['sha256']);$this->assertEquals(0,$result['cancelled']);
        $this->assertFalse($old->fresh()->status);$this->assertEquals('USDC',$old->fresh()->baseCurrency->symbol);
        $this->assertFalse($old->fresh()->liq);
        $this->assertTrue($this->market->fresh()->trade_status);$this->assertSame($newId,$service->prepare()->id);
        $this->assertSame(0,$service->apply($service->snapshot()['sha256'])['cancelled']);
    }
    public function test_cutover_refunds_open_old_orders_and_rejects_changed_review(): void
    {
        $new=$this->market;$old=Market::whereName('USDC-USDT')->firstOrFail();
        $old->update(['status'=>true,'trade_status'=>true,'buy_order_status'=>true,'sell_order_status'=>true,'cancel_order_status'=>true]);
        $this->market=$old;$cashBefore=$this->wallet($old->quote_currency_id)->balance_in_trade;
        $id=$this->place('buy','limit',['quantity'=>'10','price'=>'0.7'])->assertOk()->json('message');
        $this->assertNotNull(Order::find($id));$this->market=$new;
        $new->update(['status'=>false,'trade_status'=>false]);
        Cache::put('markets_liquidity.USDT-USDC.executable',['received_at'=>time(),'snapshot'=>'qa-cancel','bids'=>[['price'=>'0.79','quantity'=>'1000']],'asks'=>[['price'=>'0.8','quantity'=>'1000']]]);
        $service=app(StablecoinMarketCutover::class);$hash=$service->snapshot()['sha256'];
        $old->update(['quote_precision'=>7]);
        try {$service->apply($hash);$this->fail('Changed review accepted');}catch(\RuntimeException $e){$this->assertSame('STABLECOIN_CUTOVER_STATE_CHANGED',$e->getMessage());}
        $this->assertNotNull(Order::find($id));
        $result=$service->apply($service->snapshot()['sha256']);$this->assertSame(1,$result['cancelled']);
        $this->assertNull(Order::find($id));$this->assertNotNull(DB::table('order_histories')->where('id',$id)->first());
        $this->assertSame($cashBefore,$this->wallet($old->quote_currency_id)->balance_in_trade);
        $this->assertSame(0,bccomp($this->wallet($old->quote_currency_id)->balance_in_order,'0',18));
        $this->market=$old;$this->place('buy','limit',['quantity'=>'10','price'=>'0.7'])->assertStatus(422);
    }

}
