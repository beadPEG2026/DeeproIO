<?php
namespace Tests\Feature\Deepro;
use App\Models\{Market\Market,Order\Order,User\User,Wallet\Wallet};
use App\Services\Market\{StockAssets,StockLiquidity,StockOnboarding};
use Illuminate\Support\Facades\{DB,Event,Http,Queue,Route};
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
final class StockTradingTest extends TestCase {
    private Market $market;
    private User $buyer;
    private Wallet $usd;
    private User $maker;
    protected function setUp(): void {
        parent::setUp(); $this->assertSame('deepro_test',DB::connection()->getDatabaseName());
        config(['cache.default'=>'array','broadcasting.default'=>'log']);
        Route::getRoutes()->refreshNameLookups();Route::getRoutes()->refreshActionLookups();
        DB::beginTransaction();Event::fake();Queue::fake();Http::preventStrayRequests();
        $this->market=Market::whereName('AAPLon-USDT')->firstOrFail();$this->market->liq=true;$this->market->save();
        DB::table('orders')->where('market_id',$this->market->id)->delete();
        DB::table('stock_liquidity_books')->where('market_id',$this->market->id)->delete();
        $this->buyer=User::withoutEvents(fn()=>User::factory()->create(['email_verified_at'=>now(),'vip'=>0,'referral_id'=>null]));
        foreach ([$this->market->base_currency_id,$this->market->quote_currency_id] as $id) Wallet::factory()->create(['user_id'=>$this->buyer->id,'currency_id'=>$id,'balance_in_trade'=>$id===$this->market->quote_currency_id?'1000':'0']);
        $this->usd=Wallet::where('user_id',$this->buyer->id)->where('currency_id',$this->market->quote_currency_id)->first();
        $this->maker=User::withoutEvents(fn()=>User::factory()->create(['email_verified_at'=>now(),'vip'=>0,'referral_id'=>null]));
        $this->fundMaker($this->market);
        Sanctum::actingAs($this->buyer,['trade']);
    }
    private function fundMaker(Market $m):void {
        foreach ([$m->base_currency_id,$m->quote_currency_id] as $id) Wallet::updateOrCreate(['user_id'=>$this->maker->id,'currency_id'=>$id],['balance_in_trade'=>$id===$m->base_currency_id?'100':'10000']);
        DB::table('market_execution_policies')->updateOrInsert(['market_id'=>$m->id],['mode'=>'platform_maker','maker_user_id'=>$this->maker->id,'max_quote_per_fill'=>'1000','created_at'=>now(),'updated_at'=>now()]);
    }
    protected function tearDown():void {while(DB::transactionLevel()>0)DB::rollBack();parent::tearDown();}
    private function book(array $asks=[['100.00','1.0'],['110.00','2.0']], array $bids=[['99.00','2.0']], string $id='100'): array {
        $a=StockAssets::find('AAPLon');
        return ['symbol'=>'AAPLon','source'=>'binance-alpha','source_symbol'=>'ALPHA_741USDT','chain_id'=>56,'contract'=>$a['contract'],'quote_currency'=>'USDT','quantity_unit'=>'token','denomination'=>1,'mul_point'=>1,'received_at'=>gmdate('c'),'event_time'=>time()*1000,'last_update_id'=>$id,'bids'=>$bids,'asks'=>$asks];
    }
    private function seedBook(?array $book=null):void {app(StockLiquidity::class)->store($this->market,$book??$this->book());}
    private function place(string $side,string $type,array $input) {return $this->postJson('/api/v1/orders',['market'=>$this->market->name,'side'=>$side,'type'=>$type]+$input);}
    private function base():Wallet {return Wallet::where('user_id',$this->buyer->id)->where('currency_id',$this->market->base_currency_id)->first();}
    public function test_buy_walks_levels_and_sell_settles_balances():void {
        $this->seedBook();
        $id=$this->place('buy','market',['quoteQuantity'=>'200'])->assertOk()->json('message');
        $fills=DB::table('transactions')->where('order_id',$id)->orderBy('created_at')->get();
        $this->assertCount(2,$fills);$this->assertEquals(100,(float)$fills[0]->price);$this->assertEquals(110,(float)$fills[1]->price);
        $spent=$fills->sum('quote_currency')+$fills->sum('fee');
        $this->assertEqualsWithDelta(1000-$spent,(float)$this->usd->fresh()->balance_in_trade,0.0000001);
        $this->assertEqualsWithDelta($fills->sum('base_currency'),(float)$this->base()->balance_in_trade,0.0000001);
        $this->assertEqualsWithDelta(0,(float)$this->usd->fresh()->balance_in_order,0.0000001);
        $this->assertFalse(Order::whereKey($id)->exists());
        $sell=$this->place('sell','market',['quantity'=>'0.5'])->assertOk()->json('message');
        $fill=DB::table('transactions')->where('order_id',$sell)->sole();
        $this->assertEquals(99,(float)$fill->price);
        $this->assertEqualsWithDelta(1000-$spent+49.5-(float)$fill->fee,(float)$this->usd->fresh()->balance_in_trade,0.0000001);
        $this->assertEqualsWithDelta($fills->sum('base_currency')-.5,(float)$this->base()->balance_in_trade,0.0000001);
        Http::assertNothingSent();
    }
    public function test_consumed_depth_is_not_refilled_by_same_snapshot_and_rolls_back():void {
        $book=$this->book();$this->seedBook($book);
        $this->place('buy','market',['quoteQuantity'=>'50'])->assertOk();
        $before=app(StockLiquidity::class)->levels($this->market);
        $this->seedBook($book);$this->assertSame($before,app(StockLiquidity::class)->levels($this->market));
        DB::beginTransaction();$this->place('buy','market',['quoteQuantity'=>'20'])->assertOk();DB::rollBack();
        $this->assertSame($before,app(StockLiquidity::class)->levels($this->market));
    }
    public function test_market_order_cannot_overfill_finite_depth():void {
        $this->seedBook($this->book([['100.00','0.1']]));
        $id=$this->place('buy','market',['quoteQuantity'=>'200'])->assertOk()->json('message');
        $fills=DB::table('transactions')->where('order_id',$id)->get();$this->assertCount(1,$fills);
        $this->assertEqualsWithDelta(.1,$fills->sum('base_currency'),0.00000001);
        $this->assertEqualsWithDelta(1000-10-$fills->sum('fee'),(float)$this->usd->fresh()->balance_in_trade,0.00000001);
        $this->assertEqualsWithDelta(0,(float)$this->usd->fresh()->balance_in_order,0.00000001);
        $this->assertEmpty(app(StockLiquidity::class)->levels($this->market)['asks']);
    }
    public function test_limit_waits_then_fills_when_book_changes():void {
        $this->seedBook();
        $id=$this->place('buy','limit',['quantity'=>'1','price'=>'95'])->assertOk()->json('message');
        $this->assertTrue(Order::whereKey($id)->exists());
        $this->seedBook($this->book([['94.00','1']],[['93.00','1']],'101'));
        app(\App\Services\Order\OrderService::class)->processOrder(Order::findOrFail($id));
        $this->assertFalse(Order::whereKey($id)->exists());
        $fill=DB::table('transactions')->where('order_id',$id)->sole();
        $this->assertEquals(94,(float)$fill->price);
        $this->assertEqualsWithDelta(1000-94-(float)$fill->fee,(float)$this->usd->fresh()->balance_in_trade,0.00000001);
        $this->assertEqualsWithDelta(0,(float)$this->usd->fresh()->balance_in_order,0.00000001);
    }
    public function test_expired_depth_is_not_displayed_or_consumed():void {
        $this->seedBook();DB::table('stock_liquidity_books')->where('market_id',$this->market->id)->update(['expires_at'=>now()->subSecond()]);
        $this->assertEmpty(app(StockLiquidity::class)->levels($this->market)['asks']);
        $this->place('buy','market',['quoteQuantity'=>'100'])->assertStatus(422);
        $this->assertSame(0,DB::table('transactions')->where('user_id',$this->buyer->id)->count());
        $this->assertSame(0,DB::table('orders')->where('user_id',$this->buyer->id)->count());
        $this->assertEqualsWithDelta(1000,(float)$this->usd->fresh()->balance_in_trade,0.00000001);
    }
    public function test_units_identity_and_snapshot_conflicts_are_rejected():void {
        foreach (['contract'=>'0x'.str_repeat('1',40),'denomination'=>1000,'quantity_unit'=>'alpha_display_unit','received_at'=>'2000-01-01T00:00:00Z'] as $key=>$value) {
            try {$this->seedBook(array_replace($this->book(),[$key=>$value]));$this->fail('Accepted '.$key);} catch(\RuntimeException $e) {$this->assertNotEmpty($e->getMessage());}
        }
        $this->seedBook();
        try {$this->seedBook($this->book([['100','2']]));$this->fail('Accepted conflicting snapshot');}catch(\RuntimeException $e){$this->assertSame('stock_snapshot_conflict',$e->getMessage());}
    }
    public function test_limit_cancel_restores_reserved_funds():void {
        $this->seedBook();
        $id=$this->place('buy','limit',['quantity'=>'1','price'=>'90'])->assertOk()->json('message');
        $this->postJson('/api/v1/orders/cancel',['uuid'=>$id])->assertOk();
        $this->assertFalse(Order::whereKey($id)->exists());
        $this->assertEqualsWithDelta(1000,(float)$this->usd->fresh()->balance_in_trade,0.00000001);
        $this->assertEqualsWithDelta(0,(float)$this->usd->fresh()->balance_in_order,0.00000001);
    }
    public function test_virtual_funded_trade_preserves_real_balances():void {
        $this->usd->update(['balance_in_virtual_trade'=>1000]);$this->seedBook();
        $this->place('buy','market',['quoteQuantity'=>'50'])->assertStatus(422);
        $this->assertEquals(1000,(float)$this->usd->fresh()->balance_in_trade);
        $this->assertEquals(1000,(float)$this->usd->fresh()->balance_in_virtual_trade);
        $this->assertEquals(0,(float)$this->base()->balance_in_trade);
        $this->assertEquals(0,(float)$this->base()->balance_in_virtual_trade);
    }
    public function test_local_order_has_priority_when_price_is_better():void {
        $this->seedBook();
        $seller=User::withoutEvents(fn()=>User::factory()->create(['email_verified_at'=>now(),'vip'=>0,'referral_id'=>null]));
        foreach ([$this->market->base_currency_id,$this->market->quote_currency_id] as $id) Wallet::factory()->create(['user_id'=>$seller->id,'currency_id'=>$id,'balance_in_trade'=>$id===$this->market->base_currency_id?'1':'0']);
        Sanctum::actingAs($seller,['trade']);
        $limit=$this->place('sell','limit',['quantity'=>'1','price'=>'99.5'])->assertOk()->json('message');
        Sanctum::actingAs($this->buyer,['trade']);
        $id=$this->place('buy','market',['quoteQuantity'=>'50'])->assertOk()->json('message');
        $this->assertEquals(99.5,(float)DB::table('transactions')->where('order_id',$id)->sole()->price);
        $this->assertSame(0,DB::table('stock_liquidity_fills')->where('order_id',$id)->count());
    }
    public function test_all_catalog_assets_use_the_same_depth_adapter():void {
        foreach (StockAssets::all() as $a) {
            $m=Market::whereName($a['symbol'].'-USDT')->firstOrFail();$m->liq=true;$m->save();
            DB::table('stock_liquidity_books')->where('market_id',$m->id)->delete();
            $b=array_replace($this->book(),['symbol'=>$a['symbol'],'contract'=>$a['contract']]);
            app(StockLiquidity::class)->store($m,$b);
            $this->assertCount(2,app(StockLiquidity::class)->levels($m)['asks']);
        }
    }
    public function test_new_asset_is_provisioned_idempotently_without_frontend_code():void {
        $a=StockAssets::find('AAPLon');$a['symbol']=$a['id']='TESTon';$a['ticker']='TEST';$a['contract']='0x'.str_repeat('a',40);
        $depth=array_replace($this->book(),['symbol'=>$a['symbol'],'contract'=>$a['contract']]);
        Http::fake(['*/v1/stocks/inspect'=>Http::response(['data'=>['asset'=>$a,'depth'=>$depth,'checks'=>['identity'=>true,'depth'=>true]]])]);
        app(StockOnboarding::class)->apply($a['contract']);app(StockOnboarding::class)->apply($a['contract']);
        $this->assertSame(1,DB::table('currencies')->where('symbol','TESTon')->count());
        $m=Market::whereName('TESTon-USDT')->sole();$this->assertTrue((bool)$m->liq);
        $this->assertTrue(StockAssets::supports($m->name));
        $this->assertSame(1,DB::table('currency_networks')->where('currency_id',$m->base_currency_id)->count());
        $this->assertTrue((new \App\Http\Resources\Market\Market($m))->resolve()['stock_token']);
        $this->assertEquals(0,DB::table('wallets')->where('currency_id',$m->base_currency_id)->sum('balance_in_trade'));
        $this->assertSame(1,DB::table('wallets')->where('currency_id',$m->base_currency_id)->where('user_id',$this->buyer->id)->count());
        $this->market=$m;$this->fundMaker($m);
        $order=$this->place('buy','market',['quoteQuantity'=>'20'])->assertOk()->json('message');
        $this->assertGreaterThan(0,DB::table('transactions')->where('order_id',$order)->sum('base_currency'));
        $this->assertGreaterThan(0,(float)$this->base()->balance_in_trade);
    }
    public function test_failed_verification_leaves_configuration_untouched():void {
        $before=DB::table('currencies')->count();
        Http::fake(['*/v1/stocks/inspect'=>Http::response(['error'=>'alpha_identity_mismatch'],502)]);
        try {app(StockOnboarding::class)->apply('0x'.str_repeat('b',40));$this->fail('Should reject');}catch(\RuntimeException $e){$this->assertSame('alpha_identity_mismatch',$e->getMessage());}
        $this->assertSame($before,DB::table('currencies')->count());
    }

    public function test_public_stock_endpoints_do_not_use_binance_spot_symbols():void {
        $this->seedBook();
        $this->getJson('/api/v1/markets/orderbook?market=AAPLon-USDT')->assertOk()->assertJsonPath('execution_mode','platform_maker')->assertJsonCount(2,'asks');
        $this->getJson('/api/v1/markets/historical/trades?market=AAPLon-USDT')->assertOk()->assertJsonPath('success',true);
        $this->getJson('/api/v1/markets/trades?market=AAPLon-USDT')->assertOk();
        Http::assertNothingSent();
    }
    public function test_admin_template_is_not_accessible_to_regular_users():void {
        if (!Route::has('admin.stock-tokens.store')) Route::middleware('web')->group(base_path('routes/admin.php'));
        Route::getRoutes()->refreshNameLookups();
        $this->actingAs($this->buyer,'web')->post('/exchange-control-panel/stock-tokens',['contract'=>StockAssets::find('AAPLon')['contract'],'asset_type'=>'stock'])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_unfunded_quotes_never_credit_customer_or_appear_as_depth():void {
        $this->seedBook();Wallet::where('user_id',$this->maker->id)->update(['balance_in_trade'=>0]);
        $this->getJson('/api/v1/markets/orderbook?market=AAPLon-USDT')->assertOk()->assertJsonCount(0,'asks')->assertJsonCount(0,'bids');
        $this->place('buy','market',['quoteQuantity'=>'20'])->assertStatus(422);
        $this->assertEquals(0,(float)$this->base()->balance_in_trade);
        $this->assertEquals(1000,(float)$this->usd->fresh()->balance_in_trade);
    }
    public function test_maker_inventory_and_both_accounts_are_conserved():void {
        $this->seedBook();$id=$this->place('buy','market',['quoteQuantity'=>'50'])->assertOk()->json('message');
        $fill=DB::table('transactions')->where('order_id',$id)->sole();
        $base=Wallet::whereIn('user_id',[$this->maker->id,$this->buyer->id])->where('currency_id',$this->market->base_currency_id);
        $quote=Wallet::whereIn('user_id',[$this->maker->id,$this->buyer->id])->where('currency_id',$this->market->quote_currency_id);
        $this->assertEqualsWithDelta(100,(float)$base->sum('balance_in_trade'),0.00000001);
        $this->assertEqualsWithDelta(11000-(float)$fill->fee,(float)$quote->sum('balance_in_trade'),0.00000001);
        $receipt=DB::table('funded_liquidity_fills')->where('taker_order_id',$id)->sole();
        $this->assertSame($this->maker->id,(int)$receipt->maker_user_id);
        $this->assertSame(1,DB::table('transactions')->where('order_id',$receipt->maker_order_id)->count());
        $this->assertEquals(0,(float)$base->sum('balance_in_order'));
        $this->assertEqualsWithDelta(0,(float)$quote->sum('balance_in_order'),0.00000001);
    }
    public function test_inventory_caps_fill_and_refunds_unfilled_quote():void {
        $this->seedBook();Wallet::where('user_id',$this->maker->id)->where('currency_id',$this->market->base_currency_id)->update(['balance_in_trade'=>'0.02']);
        $id=$this->place('buy','market',['quoteQuantity'=>'20'])->assertOk()->json('message');
        $this->assertEqualsWithDelta(.02,(float)$this->base()->balance_in_trade,1e-8);
        $this->assertEqualsWithDelta(0,(float)$this->usd->fresh()->balance_in_order,1e-8);
        $this->assertEquals(0,(float)Wallet::where('user_id',$this->maker->id)->where('currency_id',$this->market->base_currency_id)->value('balance_in_trade'));
    }
    public function test_internal_mode_does_not_fall_back_to_external_prices():void {
        $this->seedBook();DB::table('market_execution_policies')->where('market_id',$this->market->id)->update(['mode'=>'internal']);
        config(['app.fixed_swap'=>true]);
        $this->place('buy','market',['quoteQuantity'=>'20','swap'=>true])->assertStatus(422);
        Http::assertNothingSent();$this->assertEquals(0,(float)$this->base()->balance_in_trade);
    }
    public function test_rollback_restores_maker_and_customer_funds_and_receipts():void {
        $this->seedBook();$before=Wallet::whereIn('user_id',[$this->maker->id,$this->buyer->id])->orderBy('id')->get()->toJson();
        $count=DB::table('funded_liquidity_fills')->count();DB::beginTransaction();
        $this->place('buy','market',['quoteQuantity'=>'20'])->assertOk();DB::rollBack();
        $this->assertSame($before,Wallet::whereIn('user_id',[$this->maker->id,$this->buyer->id])->orderBy('id')->get()->toJson());
        $this->assertSame($count,DB::table('funded_liquidity_fills')->count());
    }

    public function test_ordinary_coin_uses_funded_inventory_and_clears_all_reservations():void {
        $this->market=Market::whereName('BTC-USDT')->firstOrFail();$this->market->update(['liq'=>true]);
        DB::table('orders')->where('market_id',$this->market->id)->delete();$this->fundMaker($this->market);
        Wallet::where('user_id',$this->maker->id)->where('currency_id',$this->market->base_currency_id)->update(['balance_in_trade'=>'0.02']);
        Wallet::updateOrCreate(['user_id'=>$this->buyer->id,'currency_id'=>$this->market->base_currency_id],['balance_in_trade'=>0]);
        foreach(['asks'=>[['price'=>'100','quantity'=>'0.02']],'bids'=>[['price'=>'99','quantity'=>'0.02']],'received_at'=>time()]as$k=>$v)\Illuminate\Support\Facades\Cache::put('markets_liquidity.BTC-USDT.'.$k,$v);
        $id=$this->place('buy','market',['quoteQuantity'=>'20'])->assertOk()->json('message');
        $this->assertEqualsWithDelta(.02,(float)$this->base()->balance_in_trade,1e-8);
        $this->assertEqualsWithDelta(0,(float)$this->usd->fresh()->balance_in_order,1e-8);
        $this->assertSame(1,DB::table('funded_liquidity_fills')->where('taker_order_id',$id)->count());
        Http::assertNothingSent();
    }
    public function test_virtual_internal_orders_match_only_virtual_counterparties():void {
        DB::table('market_execution_policies')->where('market_id',$this->market->id)->update(['mode'=>'internal']);
        Wallet::where('user_id',$this->maker->id)->where('currency_id',$this->market->base_currency_id)->update(['balance_in_virtual_trade'=>1]);
        Sanctum::actingAs($this->maker,['trade']);$sell=$this->place('sell','limit',['quantity'=>'1','price'=>'100'])->assertOk()->json('message');
        Sanctum::actingAs($this->buyer,['trade']);$this->place('buy','market',['quoteQuantity'=>'50'])->assertStatus(422);
        $this->getJson('/api/v1/markets/orderbook?market=AAPLon-USDT')->assertOk()->assertJsonCount(0,'asks');
        $this->usd->update(['balance_in_virtual_trade'=>1000]);
        $id=$this->place('buy','market',['quoteQuantity'=>'50'])->assertOk()->json('message');
        $this->assertGreaterThan(0,(float)$this->base()->balance_in_virtual_trade);
        $this->assertEquals(0,(float)$this->base()->balance_in_trade);
        $this->assertEquals(1000,(float)$this->usd->fresh()->balance_in_trade);
        $this->assertEquals(100,(float)Wallet::where('user_id',$this->maker->id)->where('currency_id',$this->market->base_currency_id)->value('balance_in_trade'));
    }
    public function test_missing_external_credentials_throw_before_any_request():void {
        $api=(new \ReflectionClass(\App\Services\Liquidity\Binance\BinanceApi::class))->newInstanceWithoutConstructor();
        $this->expectExceptionMessage('EXTERNAL_EXECUTION_CREDENTIALS_MISSING');$api->order('buy','BTC-USDT','1','1');
    }
    public function test_admin_execution_settings_are_audited_and_do_not_credit_funds():void {
        $url='/exchange-control-panel/spot-execution/'.$this->market->id;
        $data=['mode'=>'platform_maker','maker_user_id'=>$this->maker->id,'max_quote_per_fill'=>'30'];
        $this->actingAs($this->buyer,'web')->putJson($url,$data)->assertForbidden();
        $this->buyer->assignRole('superadmin');
        $before=Wallet::where('user_id',$this->maker->id)->orderBy('id')->get()->toJson();
        $this->putJson($url,$data)->assertRedirect();
        $this->assertSame($before,Wallet::where('user_id',$this->maker->id)->orderBy('id')->get()->toJson());
        $this->assertSame(1,DB::table('market_execution_audits')->where('actor_id',$this->buyer->id)->count());
        $this->putJson($url,array_replace($data,['mode'=>'external']))->assertStatus(422);
        $this->maker->update(['is_xn'=>true]);$this->putJson($url,$data)->assertStatus(422);
        $this->assertSame(1,DB::table('market_execution_audits')->where('actor_id',$this->buyer->id)->count());
    }

    public function test_failed_settlement_rolls_back_reservation_and_can_retry_once():void {
        $this->seedBook();$id=$this->place('buy','limit',['quantity'=>'1','price'=>'95'])->assertOk()->json('message');
        $this->seedBook($this->book([['94.00','1']],[['93.00','1']],'101'));
        $before=Wallet::whereIn('user_id',[$this->buyer->id,$this->maker->id])->orderBy('id')->get()->toJson();
        $service=new \App\Services\Order\OrderService();
        $service->transactionService=new class extends \App\Services\Transaction\TransactionService {public function process($t){throw new \RuntimeException('injected settlement failure');}};
        try{$service->processOrder(Order::findOrFail($id));$this->fail('Expected rollback');}catch(\RuntimeException $e){$this->assertStringContainsString('rolled back',$e->getMessage());}
        $this->assertSame($before,Wallet::whereIn('user_id',[$this->buyer->id,$this->maker->id])->orderBy('id')->get()->toJson());
        $this->assertSame(0,DB::table('funded_liquidity_fills')->where('taker_order_id',$id)->count());
        $order=Order::findOrFail($id);app(\App\Services\Order\OrderService::class)->processOrder($order);
        app(\App\Services\Order\OrderService::class)->processOrder($order);
        $this->assertSame(1,DB::table('funded_liquidity_fills')->where('taker_order_id',$id)->count());
    }
    public function test_stale_or_crossed_ordinary_quotes_have_no_executable_depth():void {
        $m=Market::whereName('BTC-USDT')->firstOrFail();$m->update(['liq'=>true]);$this->fundMaker($m);
        $cache=\Illuminate\Support\Facades\Cache::class;
        $cache::put('markets_liquidity.BTC-USDT.asks',[['price'=>'100','quantity'=>'1']]);$cache::put('markets_liquidity.BTC-USDT.bids',[['price'=>'101','quantity'=>'1']]);$cache::put('markets_liquidity.BTC-USDT.received_at',time());
        $this->assertEmpty(app(\App\Services\Market\FundedLiquidity::class)->levels($m)['asks']);
        $cache::put('markets_liquidity.BTC-USDT.bids',[['price'=>'99','quantity'=>'1']]);$cache::put('markets_liquidity.BTC-USDT.received_at',time()-30);
        $this->assertEmpty(app(\App\Services\Market\FundedLiquidity::class)->levels($m)['asks']);
    }

}
