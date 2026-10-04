<?php
namespace Tests\Feature\Deepro;

use App\Models\{Market\Market,User\User,Wallet\Wallet};
use App\Services\Market\{HongKongProductListing,HongKongExecutionDepth,StockLiquidity,FundedLiquidity,PlatformCredit};
use Carbon\{Carbon,CarbonImmutable};
use Illuminate\Support\Facades\{DB,Event,Http,Queue};
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class HongKongUnifiedExecutionTest extends TestCase
{
    private Market $market;
    private User $buyer;
    protected function setUp():void
    {
        parent::setUp();$this->assertSame('deepro_test',DB::connection()->getDatabaseName());
        DB::beginTransaction();Event::fake();Queue::fake();Http::preventStrayRequests();
        Carbon::setTestNow('2026-09-29 10:00:00 Asia/Hong_Kong');CarbonImmutable::setTestNow('2026-09-29 10:00:00 Asia/Hong_Kong');
        config(['hk-price-products.platform_quotes.enabled'=>false,'cache.default'=>'array','broadcasting.default'=>'log','hk-price-products.trading_enabled'=>true,'hk-price-products.assets.HK08379.tradingEnabled'=>true]);
        DB::table('platform_credit_pools')->where('id',1)->update(['enabled'=>true,'default_for_usdt'=>true,'credit_limit'=>'50','quote_position'=>'0','realized_pnl'=>'0']);
        DB::table('platform_credit_positions')->delete();
        $this->market=app(HongKongProductListing::class)->apply('HK08379',true);
        $this->market->update(['liq'=>true,'min_trade_size'=>'0.00000001','max_trade_size'=>'100000','min_trade_value'=>'0.000001','max_trade_value'=>'100000','min_market_buy_amount'=>'0','base_ticker_size'=>'0.00000001']);
        $this->buyer=$this->user('0','100');Sanctum::actingAs($this->buyer,['trade']);
    }
    protected function tearDown():void {while(DB::transactionLevel()>0)DB::rollBack();Carbon::setTestNow();CarbonImmutable::setTestNow();parent::tearDown();}
    private function user(string $base,string $quote):User
    {
        $u=User::withoutEvents(fn()=>User::factory()->create(['email_verified_at'=>now(),'vip'=>0,'referral_id'=>null]));
        foreach ([$this->market->base_currency_id=>$base,$this->market->quote_currency_id=>$quote] as $id=>$balance)
            Wallet::factory()->create(['user_id'=>$u->id,'currency_id'=>$id,'balance_in_trade'=>$balance]);
        return $u;
    }
    private function raw(array $replace=[]):array
    {
        return array_replace(['security_code'=>'08379','currency'=>'HKD','quantity_unit'=>'share','delayed'=>false,'market_status'=>'open','source'=>'authorized test endpoint','source_time'=>CarbonImmutable::now()->timestamp,'snapshot_id'=>'123456','lot_size'=>10000,'bids'=>[['0.103','30000']],'asks'=>[['0.114','10000']]],$replace);
    }
    private function fx(array $replace=[]):array {return array_replace(['hkdPerUsdt'=>'7.8','eventTime'=>CarbonImmutable::now()->timestamp,'source'=>'test USDHKD × USDTUSD'],$replace);}
    private function store(?array $raw=null):array
    {
        $book=app(HongKongExecutionDepth::class)->normalize($this->market,$raw??$this->raw(),$this->fx());
        app(StockLiquidity::class)->store($this->market,$book);return $book;
    }
    private function place(string $side,string $type,array $input) {return $this->postJson('/api/v1/orders',['market'=>$this->market->name,'side'=>$side,'type'=>$type]+$input);}
    private function balance(User $u,int $id):string {return Wallet::where('user_id',$u->id)->where('currency_id',$id)->value('balance_in_trade');}

    public function test_shared_existing_credit_executes_and_journals_buy_and_sell_without_issuing_wallet_inventory():void
    {
        $before=DB::table('platform_credit_pools')->where('id',1)->first();
        $this->assertSame(0,DB::table('market_execution_policies')->where('market_id',$this->market->id)->count());
        $this->assertSame('platform_credit',app(FundedLiquidity::class)->policy($this->market)->mode);
        $book=$this->store();
        $this->assertSame('10000.000000000000000000',$book['asks'][0][1]); // No lot-size multiplication.
        $buy=$this->place('buy','limit',['quantity'=>'2','price'=>'0.02'])->assertOk()->json('message');
        $this->assertEquals(2,(float)$this->balance($this->buyer,$this->market->base_currency_id));
        $this->assertEquals((float)$book['asks'][0][0],(float)DB::table('transactions')->where('order_id',$buy)->sole()->price);
        $this->assertEquals(-2,(float)DB::table('platform_credit_positions')->where('currency_id',$this->market->base_currency_id)->value('quantity'));
        $sell=$this->place('sell','market',['quantity'=>'2'])->assertOk()->json('message');
        $this->assertEquals(0,(float)$this->balance($this->buyer,$this->market->base_currency_id));
        $this->assertSame(2,DB::table('platform_credit_fills')->whereIn('taker_order_id',[$buy,$sell])->where('status','settled')->count());
        $this->assertSame(0,DB::table('platform_credit_entries')->whereRaw('platform_delta+user_delta+fee_delta != 0')->count());
        $this->assertSame($before->credit_limit,DB::table('platform_credit_pools')->where('id',1)->value('credit_limit'));
        $this->assertEquals(0,(float)DB::table('platform_credit_positions')->where('currency_id',$this->market->base_currency_id)->value('quantity'));
        Http::assertNothingSent();
    }
    public function test_common_maker_uses_only_real_existing_inventory_and_finite_depth():void
    {
        $maker=$this->user('3','1');
        DB::table('market_execution_policies')->insert(['market_id'=>$this->market->id,'mode'=>'platform_maker','maker_user_id'=>$maker->id,'max_quote_per_fill'=>'0.05','created_at'=>now(),'updated_at'=>now()]);
        $this->store();
        $levels=app(FundedLiquidity::class)->levels($this->market);$this->assertEquals(3,(float)$levels['asks'][0]['quantity']);
        $id=$this->place('buy','market',['quoteQuantity'=>'1'])->assertOk()->json('message');
        $this->assertEquals(3,(float)DB::table('transactions')->where('order_id',$id)->sum('base_currency'));
        $this->assertEquals(0,(float)$this->balance($maker,$this->market->base_currency_id));
        $this->assertEquals(3,(float)$this->balance($this->buyer,$this->market->base_currency_id));
        $this->assertEmpty(app(FundedLiquidity::class)->levels($this->market)['asks']);
        $this->assertSame(0,DB::table('platform_credit_fills')->where('market_id',$this->market->id)->count());
    }
    public function test_depth_delay_source_time_fx_and_currency_fail_closed_and_no_source_means_no_fetch():void
    {
        $adapter=app(HongKongExecutionDepth::class);
        foreach ([['delayed'=>true],['source_time'=>CarbonImmutable::now()->timestamp-21],['currency'=>'USDT'],['quantity_unit'=>'lot'],['is_demo'=>true],['asks'=>[['0.114','0']]]] as $change) {
            try {$adapter->normalize($this->market,$this->raw($change),$this->fx());$this->fail('accepted invalid source');}
            catch (\RuntimeException $e) {$this->assertStringStartsWith('hk_execution_',$e->getMessage());}
        }
        try {$adapter->normalize($this->market,$this->raw(),$this->fx(['eventTime'=>CarbonImmutable::now()->timestamp-91]));$this->fail('accepted stale FX');}
        catch (\RuntimeException $e) {$this->assertSame('hk_execution_depth_expired',$e->getMessage());}
        try {$adapter->fetch($this->market);$this->fail('fetched unconfigured provider');}
        catch (\RuntimeException $e) {$this->assertSame('hk_execution_depth_not_configured',$e->getMessage());}
        $this->place('buy','market',['quoteQuantity'=>'1'])->assertStatus(422);
        $this->assertEquals(100,(float)$this->balance($this->buyer,$this->market->quote_currency_id));Http::assertNothingSent();
    }
    public function test_repeated_snapshot_cannot_refill_and_closure_stops_all_trading():void
    {
        $book=$this->store();$this->place('buy','limit',['quantity'=>'2','price'=>'0.02'])->assertOk();
        $left=app(StockLiquidity::class)->levels($this->market);
        app(StockLiquidity::class)->store($this->market,$book);$this->assertSame($left,app(StockLiquidity::class)->levels($this->market));
        Carbon::setTestNow('2026-09-29 12:00:00 Asia/Hong_Kong');CarbonImmutable::setTestNow('2026-09-29 12:00:00 Asia/Hong_Kong');
        $this->assertEmpty(app(FundedLiquidity::class)->levels($this->market)['asks']);
        $this->assertFalse(app(PlatformCredit::class)->summary()['marks_fresh']); // Existing shared-pool missing-short-mark protection.
        // Customer orders and automatic supply are both paused.
        $before=$this->balance($this->buyer,$this->market->base_currency_id);
        $this->place('sell','limit',['quantity'=>'1','price'=>'0.02'])->assertStatus(422)->assertJsonValidationErrors('market');
        $this->assertSame($before,$this->balance($this->buyer,$this->market->base_currency_id));
    }
    public function test_converted_book_cannot_smuggle_hkd_as_usdt_and_common_credit_limit_stays_enforced():void
    {
        $book=$this->store();$book['asks'][0][0]='0.114';
        try {app(StockLiquidity::class)->store($this->market,$book);$this->fail('HKD accepted as USDT');}
        catch (\RuntimeException $e) {$this->assertSame('hk_execution_depth_conversion_invalid',$e->getMessage());}
        DB::table('platform_credit_pools')->where('id',1)->update(['credit_limit'=>'0.01']);
        $id=$this->place('buy','market',['quoteQuantity'=>'1'])->assertOk()->json('message');
        $this->assertLessThanOrEqual(0.01000001,(float)DB::table('transactions')->where('order_id',$id)->sum('quote_currency'));
        $this->assertLessThanOrEqual(0.01000001,(float)app(PlatformCredit::class)->summary()['used']);
    }
}
