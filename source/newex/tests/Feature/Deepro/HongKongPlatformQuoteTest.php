<?php
namespace Tests\Feature\Deepro;
use App\Models\{Market\Market,User\User,Wallet\Wallet};
use App\Services\Market\{HongKongProductListing,HongKongPlatformQuote,FundedLiquidity};
use Carbon\{Carbon,CarbonImmutable};
use Illuminate\Support\Facades\{DB,Event,Http,Queue,Cache};
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
final class HongKongPlatformQuoteTest extends TestCase
{
 private Market $market;
 protected function setUp():void {
  parent::setUp();$this->assertSame('deepro_test',DB::connection()->getDatabaseName());DB::beginTransaction();Event::fake();Queue::fake();Http::preventStrayRequests();
  Carbon::setTestNow('2026-09-30 10:00:00 Asia/Hong_Kong');CarbonImmutable::setTestNow('2026-09-30 10:00:00 Asia/Hong_Kong');
  config(['cache.default'=>'array','broadcasting.default'=>'log','hk-price-products.platform_quotes.enabled'=>true,'hk-price-products.trading_enabled'=>true,'hk-price-products.assets.HK08379.tradingEnabled'=>true]);
  DB::table('platform_credit_pools')->where('id',1)->update(['enabled'=>true,'default_for_usdt'=>true,'credit_limit'=>'50','quote_position'=>'0','realized_pnl'=>'0']);DB::table('platform_credit_positions')->delete();
  $this->market=app(HongKongProductListing::class)->apply('HK08379',true);$this->market->update(['liq'=>true,'min_trade_size'=>'0.00000001','max_trade_size'=>'100000','min_trade_value'=>'0.000001','max_trade_value'=>'100000','min_market_buy_amount'=>'0','base_ticker_size'=>'0.00000001']);
 }
 protected function tearDown():void {while(DB::transactionLevel()>0)DB::rollBack();Carbon::setTestNow();CarbonImmutable::setTestNow();parent::tearDown();}
 private function raw():array {return ['price'=>'0.102','currency'=>'HKD','eventTime'=>CarbonImmutable::now()->timestamp-60,'source'=>'test'];}
 private function fx():array {return ['hkdPerUsdt'=>'7.8','eventTime'=>CarbonImmutable::now()->timestamp,'source'=>'test'];}
 private function store():array {$book=app(HongKongPlatformQuote::class)->build($this->market,$this->raw(),$this->fx());Cache::put('markets_liquidity.'.$this->market->name.'.executable',$book,30);Cache::put('hk-platform-quote.last.'.$this->market->id,$book,604800);return $book;}
 private function buyer():User {$u=User::withoutEvents(fn()=>User::factory()->create(['email_verified_at'=>now(),'vip'=>0,'referral_id'=>null]));foreach ([$this->market->base_currency_id=>'0',$this->market->quote_currency_id=>'100']as$id=>$v)Wallet::factory()->create(['user_id'=>$u->id,'currency_id'=>$id,'balance_in_trade'=>$v]);Sanctum::actingAs($u,['trade']);return $u;}
 public function test_own_quotes_use_five_levels_and_the_existing_credit_limit():void {
  $b=$this->store();$this->assertCount(5,$b['asks']);$this->assertCount(5,$b['bids']);$this->assertTrue($b['asks'][0]['price']>$b['bids'][0]['price']);
  $funded=app(FundedLiquidity::class)->levels($this->market);foreach(['bids','asks']as$s){$this->assertNotEmpty($funded[$s]);$cost='0';foreach($funded[$s]as$r)$cost=bcadd($cost,bcmul($r['price'],$r['quantity'],18),18);$this->assertLessThanOrEqual(50,(float)$cost);}
  $this->assertSame('50.000000000000000000',DB::table('platform_credit_pools')->where('id',1)->value('credit_limit'));
 }
 public function test_buy_sell_execute_through_shared_ledger_without_minting_wallet_inventory():void {
  $u=$this->buyer();$this->store();$this->postJson('/api/v1/orders',['market'=>$this->market->name,'side'=>'buy','type'=>'limit','quantity'=>'2','price'=>'0.02'])->assertOk();
  $this->assertEquals(2,(float)Wallet::where('user_id',$u->id)->where('currency_id',$this->market->base_currency_id)->value('balance_in_trade'));
  $this->assertEquals(-2,(float)DB::table('platform_credit_positions')->where('currency_id',$this->market->base_currency_id)->value('quantity'));
  $this->postJson('/api/v1/orders',['market'=>$this->market->name,'side'=>'sell','type'=>'market','quantity'=>'2'])->assertOk();
  $this->assertEquals(0,(float)Wallet::where('user_id',$u->id)->where('currency_id',$this->market->base_currency_id)->value('balance_in_trade'));
  $this->assertSame(2,DB::table('platform_credit_fills')->where('market_id',$this->market->id)->where('status','settled')->count());
  $this->assertSame(0,DB::table('platform_credit_entries')->select('fill_id','currency_id')->groupBy('fill_id','currency_id')->havingRaw('SUM(platform_delta+user_delta+fee_delta) != 0')->get()->count());
  $this->assertGreaterThan(0,DB::table('platform_reference_consumption')->where('market_id',$this->market->id)->count());
 }
 public function test_closed_snapshot_visible_but_never_available_to_matching():void {
  $this->store();Carbon::setTestNow('2026-10-01 10:00:00 Asia/Hong_Kong');CarbonImmutable::setTestNow('2026-10-01 10:00:00 Asia/Hong_Kong');
  $this->assertNotEmpty(app(FundedLiquidity::class)->publicBook($this->market)['asks']);$this->assertSame([],app(FundedLiquidity::class)->levels($this->market)['asks']);
  $this->buyer();$this->postJson('/api/v1/orders',['market'=>$this->market->name,'side'=>'buy','type'=>'limit','quantity'=>'2','price'=>'0.02'])->assertStatus(422);
  $this->assertSame(0,DB::table('platform_credit_fills')->where('market_id',$this->market->id)->count());
 }
 public function test_close_time_snapshot_cannot_become_executable_when_market_reopens():void {
  Carbon::setTestNow('2026-10-01 10:00:00 Asia/Hong_Kong');CarbonImmutable::setTestNow('2026-10-01 10:00:00 Asia/Hong_Kong');$this->store();
  Carbon::setTestNow('2026-10-02 09:30:00 Asia/Hong_Kong');CarbonImmutable::setTestNow('2026-10-02 09:30:00 Asia/Hong_Kong');$this->assertSame([],app(FundedLiquidity::class)->levels($this->market)['asks']);
 }
 public function test_bad_fx_stale_or_future_quotes_fail_closed():void {
  foreach([[$this->raw()+[],array_replace($this->fx(),['eventTime'=>CarbonImmutable::now()->timestamp-91])],[array_replace($this->raw(),['eventTime'=>CarbonImmutable::now()->timestamp-1201]),$this->fx()],[array_replace($this->raw(),['eventTime'=>CarbonImmutable::now()->timestamp+60]),$this->fx()],[array_replace($this->raw(),['currency'=>'USD']),$this->fx()]]as[$q,$f]){try{app(HongKongPlatformQuote::class)->build($this->market,$q,$f);$this->fail('invalid quote accepted');}catch(\RuntimeException $e){$this->assertNotEmpty($e->getMessage());}}
 }
 public function test_closed_hk_position_does_not_disable_other_markets_sharing_credit():void {
  $u=$this->buyer();$this->store();$this->postJson('/api/v1/orders',['market'=>$this->market->name,'side'=>'buy','type'=>'limit','quantity'=>'2','price'=>'0.02'])->assertOk();
  $risk=DB::table('platform_credit_positions')->where('currency_id',$this->market->base_currency_id)->value('risk_price');
  Carbon::setTestNow('2026-10-01 10:00:00 Asia/Hong_Kong');CarbonImmutable::setTestNow('2026-10-01 10:00:00 Asia/Hong_Kong');
  $btc=Market::whereName('BTC-USDT')->firstOrFail();$btc->update(['status'=>true,'trade_status'=>true,'liq'=>true]);
  DB::table('market_execution_policies')->where('market_id',$btc->id)->delete();
  Cache::put('markets_liquidity.'.$btc->name.'.executable',['received_at'=>now()->timestamp,'snapshot'=>'btc-test','bids'=>[['price'=>'99','quantity'=>'1']],'asks'=>[['price'=>'100','quantity'=>'1']]]);
  $this->assertNotEmpty(app(FundedLiquidity::class)->levels($btc)['asks']);
  $this->assertSame([],app(FundedLiquidity::class)->levels($this->market)['asks']);
  $this->assertSame($risk,DB::table('platform_credit_positions')->where('currency_id',$this->market->base_currency_id)->value('risk_price'));
 }
 public function test_pending_order_processor_refreshes_hk_quotes_without_a_browser():void {
  $feed=$this->mock(\App\Services\Market\HongKongMarketData::class);
  $feed->shouldReceive('quote')->once()->andReturn($this->raw());$feed->shouldReceive('fx')->once()->andReturn($this->fx());
  $order=new \App\Models\Order\Order();$order->setRelation('market',$this->market);
  $command=app(\App\Console\Commands\Market\MarketOrderProcessCommand::class);
  $method=new \ReflectionMethod($command,'refreshPlatformQuote');$method->invoke($command,$order);
  $this->assertNotEmpty(app(FundedLiquidity::class)->levels($this->market)['asks']);
 }
 public function test_suspension_revokes_cached_platform_quotes():void {
  $this->store();config(['hk-price-products.assets.HK08379.suspended'=>true]);
  $this->assertSame([],app(FundedLiquidity::class)->levels($this->market)['asks']);
 }
 public function test_disabled_pool_and_switches_never_offer_execution():void {
  $this->store();DB::table('platform_credit_pools')->where('id',1)->update(['enabled'=>false]);$this->assertSame([],app(FundedLiquidity::class)->levels($this->market)['asks']);
  $this->market->update(['trade_status'=>false]);$this->assertSame([],app(FundedLiquidity::class)->reference($this->market)['asks']);
 }
}
