<?php
namespace Tests\Feature\Deepro;

use App\Models\{Market\Market,Order\Order,User\User,Wallet\Wallet};
use App\Services\Market\{PlatformCredit,FundedLiquidity,StockLiquidity,StockAssets};
use Illuminate\Support\Facades\{DB,Cache,Event,Http,Queue,Route};
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class PlatformCreditTest extends TestCase
{
    private Market $market;
    private User $user;
    protected function setUp():void
    {
        parent::setUp();$this->assertSame('deepro_test',DB::connection()->getDatabaseName());
        config(['cache.default'=>'array','broadcasting.default'=>'log']);
        DB::beginTransaction();Event::fake();Queue::fake();Http::preventStrayRequests();
        $this->market=Market::whereName('BTC-USDT')->firstOrFail();$this->market->update(['liq'=>true,'trade_status'=>true,'status'=>true]);
        DB::table('orders')->where('market_id',$this->market->id)->delete();
        $this->user=User::withoutEvents(fn()=>User::factory()->create(['email_verified_at'=>now(),'vip'=>0,'referral_id'=>null]));
        foreach ([$this->market->base_currency_id,$this->market->quote_currency_id] as $id)Wallet::factory()->create(['user_id'=>$this->user->id,'currency_id'=>$id,'balance_in_trade'=>$id===$this->market->base_currency_id?'10':'10000']);
        DB::table('platform_credit_pools')->where('id',1)->update(['enabled'=>true,'default_for_usdt'=>false,'credit_limit'=>'5000000','quote_position'=>'0','realized_pnl'=>'0']);
        DB::table('platform_credit_positions')->delete();
        DB::table('platform_reference_consumption')->where('market_id',$this->market->id)->delete();
        DB::table('market_execution_policies')->updateOrInsert(['market_id'=>$this->market->id],['mode'=>'platform_credit','maker_user_id'=>null,'max_quote_per_fill'=>'1000','created_at'=>now(),'updated_at'=>now()]);
        $this->book();Sanctum::actingAs($this->user,['trade']);
    }
    protected function tearDown():void {while(DB::transactionLevel()>0)DB::rollBack();parent::tearDown();}
    private function book(string $ask='100',string $bid='99',string $quantity='100'):void
    {
        Cache::put("markets_liquidity.{$this->market->name}.received_at",time());
        Cache::put("markets_liquidity.{$this->market->name}.asks",[['price'=>$ask,'quantity'=>$quantity]]);
        Cache::put("markets_liquidity.{$this->market->name}.bids",[['price'=>$bid,'quantity'=>$quantity]]);
    }
    private function place(string $side,string $type,array $d) {return $this->postJson('/api/v1/orders',['market'=>$this->market->name,'side'=>$side,'type'=>$type]+$d);}
    private function wallet(int $id):Wallet {return Wallet::where('user_id',$this->user->id)->where('currency_id',$id)->firstOrFail();}
    private function state():array {return app(PlatformCredit::class)->summary();}
    private function assertJournal():void
    {
        $this->assertSame(0,DB::table('platform_credit_entries')->whereRaw('platform_delta+user_delta+fee_delta != 0')->count());
        Http::assertNothingSent();
    }
    public function test_five_million_is_credit_not_a_created_customer_wallet():void
    {
        $s=$this->state();$this->assertEquals(5000000,(float)$s['credit_limit']);$this->assertEquals(0,(float)$s['quote_position']);$this->assertEquals(0,(float)$s['used']);
        $this->assertSame(0,DB::table('users')->where('email','platform-credit@deepro.io')->count());
        $this->assertFalse(Route::has('platform-credit.withdraw'));
    }
    public function test_customer_buy_creates_real_asset_and_matching_platform_short():void
    {
        $id=$this->place('buy','limit',['quantity'=>'1','price'=>'100'])->assertOk()->json('message');
        $t=DB::table('transactions')->where('order_id',$id)->sole();$receipt=DB::table('platform_credit_fills')->where('taker_order_id',$id)->sole();
        $this->assertEquals(11,(float)$this->wallet($this->market->base_currency_id)->balance_in_trade);
        $this->assertEqualsWithDelta(10000-100-(float)$t->fee,(float)$this->wallet($this->market->quote_currency_id)->balance_in_trade,1e-8);
        $this->assertEquals(-1,(float)$this->state()['positions'][0]['quantity']);$this->assertEquals(100,(float)$this->state()['used']);
        $this->assertEquals(0,(float)$this->wallet($this->market->base_currency_id)->balance_in_virtual_trade);
        $this->assertNull(DB::table('order_histories')->where('id',$receipt->maker_order_id)->value('user_id'));
        $maker=DB::table('transactions')->where('order_id',$receipt->maker_order_id)->sole();$this->assertSame(1,(int)$maker->platform_pool_id);$this->assertEquals(0,(float)$maker->fee);$this->assertEquals(0,(float)$maker->referral_fee);
        $this->assertSame('settled',$receipt->status);$this->assertJournal();
    }
    public function test_customer_sell_creates_platform_usdt_debt():void
    {
        $id=$this->place('sell','market',['quantity'=>'1'])->assertOk()->json('message');
        $fill=DB::table('transactions')->where('order_id',$id)->sole();
        $this->assertEquals(9,(float)$this->wallet($this->market->base_currency_id)->balance_in_trade);
        $this->assertEqualsWithDelta(10000+99-(float)$fill->fee,(float)$this->wallet($this->market->quote_currency_id)->balance_in_trade,1e-8);
        $this->assertEquals(-99,(float)$this->state()['quote_position']);$this->assertEquals(99,(float)$this->state()['used']);$this->assertEquals(1,(float)$this->state()['positions'][0]['quantity']);$this->assertJournal();
    }
    public function test_roundtrip_releases_credit_and_records_profit_without_resetting_losses():void
    {
        $this->place('buy','limit',['quantity'=>'1','price'=>'100'])->assertOk();
        $this->place('sell','market',['quantity'=>'1'])->assertOk();
        $this->assertEquals(0,(float)$this->state()['used']);$this->assertEquals(1,(float)$this->state()['realized_pnl']);$this->assertEquals(1,(float)$this->state()['quote_position']);
        $this->place('buy','limit',['quantity'=>'1','price'=>'100'])->assertOk();$this->book('111','110');
        $this->place('sell','market',['quantity'=>'1'])->assertOk();
        $this->assertEquals(-9,(float)$this->state()['realized_pnl']);$this->assertEquals(9,(float)$this->state()['used']);$this->assertJournal();
    }
    public function test_shared_limit_caps_partial_fill_and_refunds_remainder():void
    {
        DB::table('platform_credit_pools')->where('id',1)->update(['credit_limit'=>'50']);
        $id=$this->place('buy','market',['quoteQuantity'=>'100'])->assertOk()->json('message');
        $this->assertEqualsWithDelta(.5,DB::table('transactions')->where('order_id',$id)->sum('base_currency'),1e-8);
        $this->assertEquals(50,(float)$this->state()['used']);$this->assertEquals(0,(float)$this->wallet($this->market->quote_currency_id)->balance_in_order);
        $eth=Market::whereName('ETH-USDT')->firstOrFail();$eth->update(['liq'=>true]);
        Cache::put("markets_liquidity.{$eth->name}.received_at",time());Cache::put("markets_liquidity.{$eth->name}.asks",[['price'=>'10','quantity'=>'100']]);Cache::put("markets_liquidity.{$eth->name}.bids",[['price'=>'9','quantity'=>'100']]);
        DB::table('market_execution_policies')->updateOrInsert(['market_id'=>$eth->id],['mode'=>'platform_credit','max_quote_per_fill'=>'1000','created_at'=>now(),'updated_at'=>now()]);
        $this->assertEmpty(app(FundedLiquidity::class)->levels($eth)['asks']);
        $this->assertNotEmpty(app(FundedLiquidity::class)->levels($eth)['bids']); // Existing positive book USDT may buy inventory without new debt.
    }
    public function test_same_snapshot_cannot_be_reused_or_reset_by_restarting_pool():void
    {
        $this->book('100','99','0.1');$this->place('buy','market',['quoteQuantity'=>'100'])->assertOk();
        $this->book('100','99','0.1');
        DB::table('platform_credit_pools')->where('id',1)->update(['enabled'=>false]);DB::table('platform_credit_pools')->where('id',1)->update(['enabled'=>true]);
        $this->assertEmpty(app(FundedLiquidity::class)->levels($this->market)['asks']);$this->assertEquals(10,(float)$this->state()['used']);
    }
    public function test_limit_wait_cancel_and_price_improvement_keep_reservations_correct():void
    {
        $before=$this->wallet($this->market->quote_currency_id)->balance_in_trade;
        $id=$this->place('buy','limit',['quantity'=>'1','price'=>'90'])->assertOk()->json('message');
        $this->assertEquals(0,(float)$this->state()['used']);$this->postJson('/api/v1/orders/cancel',['uuid'=>$id])->assertOk();
        $this->assertEquals($before,$this->wallet($this->market->quote_currency_id)->balance_in_trade);
        $id=$this->place('buy','limit',['quantity'=>'1','price'=>'105'])->assertOk()->json('message');
        $fill=DB::table('transactions')->where('order_id',$id)->sole();
        $this->assertEquals(100,(float)$fill->price);$this->assertEqualsWithDelta((float)$before-100-(float)$fill->fee,(float)$this->wallet($this->market->quote_currency_id)->balance_in_trade,1e-8);$this->assertEquals(0,(float)$this->wallet($this->market->quote_currency_id)->balance_in_order);
    }
    public function test_pausing_pool_does_not_change_customer_balances_or_obligations():void
    {
        $this->place('buy','limit',['quantity'=>'1','price'=>'100'])->assertOk();$before=DB::table('wallets')->where('user_id',$this->user->id)->orderBy('id')->get()->toJson();
        DB::table('platform_credit_pools')->where('id',1)->update(['enabled'=>false]);
        $this->place('buy','market',['quoteQuantity'=>'100'])->assertStatus(422);
        $this->assertSame($before,DB::table('wallets')->where('user_id',$this->user->id)->orderBy('id')->get()->toJson());$this->assertEquals(100,(float)$this->state()['used']);
    }
    public function test_virtual_customer_and_stale_or_crossed_quotes_are_rejected():void
    {
        $this->wallet($this->market->quote_currency_id)->update(['balance_in_virtual_trade'=>'1000']);$this->place('buy','market',['quoteQuantity'=>'100'])->assertStatus(422);
        $this->wallet($this->market->quote_currency_id)->update(['balance_in_virtual_trade'=>'0']);
        Cache::put("markets_liquidity.{$this->market->name}.received_at",time()-60);$this->place('buy','market',['quoteQuantity'=>'100'])->assertStatus(422);
        $this->book('98','99');$this->place('buy','market',['quoteQuantity'=>'100'])->assertStatus(422);$this->assertEquals(0,(float)$this->state()['used']);
    }
    public function test_stock_tokens_use_the_same_pool_and_settle():void
    {
        $this->market=Market::whereName('AAPLon-USDT')->firstOrFail();$this->market->update(['liq'=>true]);DB::table('orders')->where('market_id',$this->market->id)->delete();
        DB::table('stock_liquidity_books')->where('market_id',$this->market->id)->delete();DB::table('market_execution_policies')->where('market_id',$this->market->id)->delete();
        DB::table('platform_credit_pools')->where('id',1)->update(['default_for_usdt'=>true]);Wallet::factory()->create(['user_id'=>$this->user->id,'currency_id'=>$this->market->base_currency_id,'balance_in_trade'=>'0']);
        $a=StockAssets::find('AAPLon');app(StockLiquidity::class)->store($this->market,['symbol'=>'AAPLon','source'=>'binance-alpha','chain_id'=>56,'contract'=>$a['contract'],'quote_currency'=>'USDT','quantity_unit'=>'token','denomination'=>1,'mul_point'=>1,'received_at'=>gmdate('c'),'event_time'=>time()*1000,'last_update_id'=>'999','bids'=>[['99','2']],'asks'=>[['100','2']]]);
        $id=$this->place('buy','limit',['quantity'=>'1','price'=>'100'])->assertOk()->json('message');$this->assertSame(1,DB::table('platform_credit_fills')->where('taker_order_id',$id)->count());$this->assertEquals(1,(float)$this->wallet($this->market->base_currency_id)->balance_in_trade);$this->assertJournal();
    }
    public function test_failure_after_customer_and_pool_posting_rolls_back_everything():void
    {
        $id=$this->place('buy','limit',['quantity'=>'1','price'=>'90'])->assertOk()->json('message');$this->book('89','88');
        $before=DB::table('wallets')->where('user_id',$this->user->id)->orderBy('id')->get()->toJson();$count=DB::table('platform_credit_fills')->count();
        // Inject at a database trigger: even the journal failure must unwind the entire fill.
        DB::unprepared("CREATE FUNCTION pg_temp.reject_credit_entry() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'injected credit journal failure'; END; $$; CREATE TRIGGER test_credit_journal BEFORE INSERT ON platform_credit_entries FOR EACH ROW EXECUTE FUNCTION pg_temp.reject_credit_entry()");
        try {app(\App\Services\Order\OrderService::class)->processOrder(Order::findOrFail($id));$this->fail('Expected failure');}catch(\RuntimeException $e){$this->assertNotEmpty($e->getMessage());}
        DB::unprepared('DROP TRIGGER test_credit_journal ON platform_credit_entries');
        $this->assertSame($before,DB::table('wallets')->where('user_id',$this->user->id)->orderBy('id')->get()->toJson());$this->assertSame($count,DB::table('platform_credit_fills')->count());$this->assertEquals(0,(float)$this->state()['used']);
        $order=Order::findOrFail($id);app(\App\Services\Order\OrderService::class)->processOrder($order);app(\App\Services\Order\OrderService::class)->processOrder($order);
        $this->assertSame(1,DB::table('platform_credit_fills')->where('taker_order_id',$id)->count());$this->assertJournal();
    }
    public function test_only_admin_and_superadmin_can_configure_pool_without_balance_reset():void
    {
        if(!Route::has('admin.spot-credit-pool'))Route::middleware('web')->group(base_path('routes/admin.php'));Route::getRoutes()->refreshNameLookups();
        $input=['enabled'=>true,'default_for_usdt'=>true,'credit_limit'=>'5000000','reason'=>'QA pool setup'];
        $this->actingAs($this->user,'web')->put('/exchange-control-panel/spot-credit-pool',$input)->assertForbidden();
        foreach (['admin','superadmin'] as $role) {
            \Spatie\Permission\Models\Role::findOrCreate($role,'web');$this->user->syncRoles([$role]);
            $this->actingAs($this->user,'web')->put('/exchange-control-panel/spot-credit-pool',$input)->assertRedirect();
        }
        $this->assertEquals(0,(float)$this->state()['quote_position']);$this->assertTrue($this->state()['default_for_usdt']);$this->assertGreaterThanOrEqual(2,DB::table('platform_credit_audits')->count());
        $this->place('buy','limit',['quantity'=>'1','price'=>'100'])->assertOk();
        $this->putJson('/exchange-control-panel/spot-credit-pool',array_replace($input,['credit_limit'=>'50']))->assertStatus(422);
        $this->putJson('/exchange-control-panel/spot-credit-pool',array_replace($input,['credit_limit'=>'5000001']))->assertStatus(422);
    }
    public function test_earned_assets_transfer_to_normal_funding_account_without_virtual_flags():void
    {
        $this->wallet($this->market->base_currency_id)->update(['balance_in_trade'=>'0']);
        $this->place('buy','limit',['quantity'=>'1','price'=>'100'])->assertOk();
        $this->postJson('/api/v1/wallets/transfer',['currency_id'=>$this->market->base_currency_id,'amount'=>'1','direction'=>'to_funding'])->assertOk();
        $w=$this->wallet($this->market->base_currency_id);$this->assertEquals(.98,(float)$w->balance_in_wallet); // Existing 2% trade-to-funding fee remains unchanged.$this->assertEquals(0,(float)$w->balance_in_virtual_wallet);
        $this->assertFalse((bool)$this->user->fresh()->is_xn);$this->assertEquals(-1,(float)$this->state()['positions'][0]['quantity']);
    }
    public function test_full_credit_and_price_shock_still_allow_short_cover_and_preserve_loss():void
    {
        DB::table('platform_credit_pools')->where('id',1)->update(['credit_limit'=>'100']);
        $this->place('buy','limit',['quantity'=>'1','price'=>'100'])->assertOk();$this->book('151','150');
        $this->assertGreaterThan(100,(float)$this->state()['used']);
        $this->assertEmpty(app(FundedLiquidity::class)->levels($this->market)['asks']);
        $this->place('sell','market',['quantity'=>'1'])->assertOk();
        $this->assertEquals(50,(float)$this->state()['used']);$this->assertEquals(-50,(float)$this->state()['realized_pnl']);$this->assertEquals(0,(float)$this->state()['positions'][0]['quantity']);
    }
    public function test_pool_lock_serializes_independent_database_sessions():void
    {
        app(PlatformCredit::class)->pool(true);
        $config=config('database.connections.pgsql');config(['database.connections.credit_lock_probe'=>$config]);
        $other=DB::connection('credit_lock_probe');
        try {$other->transaction(fn()=>$other->select('SELECT id FROM platform_credit_pools WHERE id=1 FOR UPDATE NOWAIT'));$this->fail('Concurrent connection acquired shared pool lock');}
        catch(\Illuminate\Database\QueryException $e){$this->assertSame('55P03',$e->errorInfo[0]);}
        finally {DB::purge('credit_lock_probe');}
    }
    public function test_market_buy_rounding_and_walked_levels_leave_no_pending_reservations():void
    {
        $this->book('333.33333333','332.99999999','0.02');
        Cache::put("markets_liquidity.{$this->market->name}.asks",[['price'=>'333.33333333','quantity'=>'0.02'],['price'=>'334.12345678','quantity'=>'10']]);
        $id=$this->place('buy','market',['quoteQuantity'=>'50'])->assertOk()->json('message');
        $this->assertGreaterThanOrEqual(2,DB::table('platform_credit_fills')->where('taker_order_id',$id)->count());
        $this->assertSame(0,DB::table('platform_credit_fills')->where('taker_order_id',$id)->where('status','reserved')->count());
        $this->assertEquals(0,(float)$this->wallet($this->market->quote_currency_id)->balance_in_order);$this->assertJournal();
    }
    public function test_user_orders_keep_price_priority_and_do_not_use_credit():void
    {
        $seller=User::withoutEvents(fn()=>User::factory()->create(['email_verified_at'=>now(),'vip'=>0,'referral_id'=>null]));
        foreach([$this->market->base_currency_id,$this->market->quote_currency_id] as $id)Wallet::factory()->create(['user_id'=>$seller->id,'currency_id'=>$id,'balance_in_trade'=>$id===$this->market->base_currency_id?'1':'0']);
        Sanctum::actingAs($seller,['trade']);$this->place('sell','limit',['price'=>'99.5','quantity'=>'1'])->assertOk();
        Sanctum::actingAs($this->user,['trade']);$id=$this->place('buy','limit',['price'=>'100','quantity'=>'1'])->assertOk()->json('message');
        $this->assertEquals(99.5,(float)DB::table('transactions')->where('order_id',$id)->sole()->price);$this->assertEquals(0,(float)$this->state()['used']);
    }
}
