<?php
namespace Tests\Feature\Deepro;

use Tests\TestCase;
use App\Models\{Market\Market,Order\Order,User\User,Wallet\Wallet};
use App\Events\OrderBookUpdated;
use Illuminate\Support\Facades\{DB,Event,Queue,Http,Cache};
use Laravel\Sanctum\Sanctum;

/** Isolated acceptance: all DB changes rolled back; external requests must be faked. */
final class TradingReliabilityTest extends TestCase
{
    private Market $market;
    private User $trader;
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('deepro_test', DB::connection()->getDatabaseName());
        $manager=new \Illuminate\Foundation\Testing\DatabaseTransactionsManager([config('database.default')]);
        app()->instance('db.transactions',$manager); DB::connection()->setTransactionManager($manager);
        DB::beginTransaction();
        (require database_path('migrations/2026_10_02_220000_trading_reliability_receipts.php'))->up();
        config(['cache.default'=>'array','performance.cache_store'=>'array','broadcasting.default'=>'log','app.readonly'=>false]);
        Event::fake(); Queue::fake(); Http::preventStrayRequests();
        $this->market=Market::whereName('BTC-USDT')->firstOrFail();
        $this->market->forceFill(['liq'=>false,'status'=>true,'trade_status'=>true,'buy_order_status'=>true,'sell_order_status'=>true,'cancel_order_status'=>true,'min_trade_size'=>'0.00001','min_trade_value'=>'0','max_trade_size'=>'10000000','max_trade_value'=>'100000000'])->save();
        DB::table('orders')->where('market_id',$this->market->id)->delete();
        DB::table('market_execution_policies')->updateOrInsert(['market_id'=>$this->market->id],['mode'=>'internal','maker_user_id'=>null,'max_quote_per_fill'=>'1000','created_at'=>now(),'updated_at'=>now()]);
        $this->trader=User::withoutEvents(fn()=>User::factory()->create(['email_verified_at'=>now(),'vip'=>0,'referral_id'=>null,'deactivated'=>false,'deleted'=>false,'is_xn'=>false]));
        foreach([$this->market->base_currency_id,$this->market->quote_currency_id] as $id) Wallet::factory()->create(['user_id'=>$this->trader->id,'currency_id'=>$id,'balance_in_trade'=>'10000','balance_in_virtual_trade'=>'0','balance_in_virtual_order'=>'0']);
        \Setting::set('trade.disable_trades', false); \Setting::set('general.maintenance_status', false);
        Cache::put('market.'.$this->market->id.'.last','100');
        Sanctum::actingAs($this->trader,['trade']);
    }
    protected function tearDown(): void {while(DB::transactionLevel()>0) DB::rollBack(); parent::tearDown();}
    private function place(array $extra=[]): array
    {
        return ['market'=>$this->market->name,'side'=>'buy','type'=>'limit','quantity'=>'0.1','price'=>'90']+$extra;
    }
    public function test_actual_desktop_and_lite_form_payloads_accept_ordinary_orders_and_keep_balance_checks(): void
    {
        foreach (['Market', 'MarketLite'] as $variant) foreach (['buy', 'sell'] as $side) {
            $process = new \Symfony\Component\Process\Process(['node', base_path('tests/Support/spot-form-payload.mjs'), $variant, $side, 'limit']);
            $process->mustRun();
            $payload = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertArrayNotHasKey('trigger_price', $payload);
            $this->assertArrayNotHasKey('trigger_condition', $payload);
            $id = $this->postJson('/api/v1/orders', $payload)->assertOk()->json('message');
            $this->assertSame($side, Order::findOrFail($id)->side);
            $this->assertSame((string)$id, (string)$this->postJson('/api/v1/orders', $payload)->assertOk()->json('message'));
        }
        // Older clients still send the default trigger, including on retry.
        $legacy = $this->place()+['trigger_price'=>0, 'trigger_condition'=>null, 'client_order_id'=>'legacy-default-trigger'];
        $id=$this->postJson('/api/v1/orders',$legacy)->assertOk()->json('message');
        $this->assertSame((string)$id,(string)$this->postJson('/api/v1/orders',$legacy)->assertOk()->json('message'));
        Wallet::where('user_id',$this->trader->id)->update(['balance_in_trade'=>'0']);
        foreach (['limit','market'] as $type) {
            $payload=array_replace($legacy,['type'=>$type,'client_order_id'=>'empty-'.$type,'quoteQuantity'=>'9']);
            $response=$this->postJson('/api/v1/orders',$payload)->assertStatus(422);
            $this->assertArrayNotHasKey('trigger_price',$response->json('errors'));
        }
        Wallet::where('user_id',$this->trader->id)->update(['balance_in_trade'=>'10000']);
        $this->postJson('/api/v1/orders',array_replace($legacy,['type'=>'stop_limit','client_order_id'=>'invalid-stop']))->assertStatus(422)->assertJsonValidationErrors('trigger_price');
    }

    public function test_order_validation_uses_the_users_language(): void
    {
        $language=\App\Models\Language\Language::where('slug','zh-cn')->firstOrFail();
        $this->trader->language_id=$language->id;$this->trader->save();$this->trader->unsetRelation('language');
        $response=$this->postJson('/api/v1/orders',array_replace($this->place(),['type'=>'stop_limit','trigger_price'=>0]))->assertStatus(422);
        $this->assertSame('触发价格必须大于 0',$response->json('errors.trigger_price.0'));
    }

    public function test_stop_requires_trigger_and_up_down_activate_once(): void
    {
        $this->postJson('/api/v1/orders',array_replace($this->place(),['type'=>'stop_limit']))->assertStatus(422);
        $this->assertSame(0,Order::where('user_id',$this->trader->id)->count());
        foreach(['up'=>'90','down'=>'110'] as $direction=>$trigger) {
            $id=$this->postJson('/api/v1/orders',array_replace($this->place(),['type'=>'stop_limit','trigger_price'=>$trigger,'trigger_condition'=>$direction]))->assertOk()->json('message');
            $order=Order::findOrFail($id); $this->assertSame($direction,$order->trigger_condition); $this->assertSame('limit',$order->type);
        }
        $id=$this->postJson('/api/v1/orders',array_replace($this->place(),['type'=>'stop_limit','trigger_price'=>'110','trigger_condition'=>'up']))->assertOk()->json('message');
        $this->assertSame('stop_limit',Order::findOrFail($id)->type);
    }
    public function test_cancel_is_broadcast_after_business_commit_and_restores_balance(): void
    {
        $before=Wallet::where('user_id',$this->trader->id)->where('currency_id',$this->market->quote_currency_id)->value('balance_in_trade');
        $id=$this->postJson('/api/v1/orders',$this->place())->assertOk()->json('message');
        $this->postJson('/api/v1/orders/cancel',['uuid'=>$id])->assertOk();
        $cancel=Event::dispatched(OrderBookUpdated::class,fn($event)=>$event->type==='cancel')->last()[0];
        $this->assertSame('real',$cancel->order['settlement_domain']);$this->assertTrue($cancel->broadcastWhen());
        $this->assertEquals($before,Wallet::where('user_id',$this->trader->id)->where('currency_id',$this->market->quote_currency_id)->value('balance_in_trade'));
    }
    public function test_same_key_replays_before_balance_validation_and_different_request_conflicts(): void
    {
        $payload=$this->place()+['client_order_id'=>'acceptance-repeat'];
        $a=$this->postJson('/api/v1/orders',$payload)->assertOk()->json('message');
        Wallet::where('user_id',$this->trader->id)->update(['balance_in_trade'=>'0']);
        $b=$this->postJson('/api/v1/orders',$payload)->assertOk()->json('message');
        $this->assertSame($a,$b); $this->assertSame(1,Order::where('user_id',$this->trader->id)->count());
        $this->postJson('/api/v1/orders',array_replace($payload,['price'=>'91']))->assertStatus(409);
        $this->assertSame(1,DB::table('trading_request_receipts')->where('user_id',$this->trader->id)->count());
    }
    public function test_idempotent_failed_operation_does_not_leave_a_claim_or_commit_callback(): void
    {
        request()->replace(['client_order_id'=>'failed-attempt']); $called=0;
        try {app(\App\Services\Order\IdempotentOrderRequest::class)->run('spot',function()use(&$called){DB::afterCommit(function()use(&$called){$called++;});throw new \RuntimeException('rollback');});$this->fail();}catch(\RuntimeException $e){}
        $this->assertSame(0,$called);$this->assertSame(0,DB::table('trading_request_receipts')->where('user_id',$this->trader->id)->count());
        $this->assertSame('recovered',app(\App\Services\Order\IdempotentOrderRequest::class)->run('spot',fn()=>'recovered'));
    }
    public function test_derivative_price_never_uses_stale_editable_real_cache(): void
    {
        Http::fake(['*'=>Http::response(['symbol'=>'BTCUSDT','lastPrice'=>'123','closeTime'=>now()->subMinute()->timestamp*1000])]);
        try{app(\App\Services\Market\VerifiedDerivativePrice::class)->forUser($this->market,$this->trader);$this->fail();}catch(\Illuminate\Validation\ValidationException $e){}
        Cache::forget('options:verified-quote:BTCUSDT');
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['*'=>Http::response(['symbol'=>'BTCUSDT','lastPrice'=>'123','closeTime'=>now()->timestamp*1000])]);
        $this->assertSame('123',app(\App\Services\Market\VerifiedDerivativePrice::class)->forUser($this->market,$this->trader)['price']);
        $this->trader->is_xn=true;$this->assertSame('100',app(\App\Services\Market\VerifiedDerivativePrice::class)->forUser($this->market,$this->trader)['price']);
    }
    public function test_funding_period_is_unique_and_receipt_is_actual_capped_balance_delta(): void
    {
        $this->freshQuote('100');
        $p=$this->future(['balance'=>'1','quantity'=>'100','created_at'=>now()->subHours(9),'activated_at'=>now()->subHours(9)]);
        $svc=app(\App\Services\Order\FuturesFundingSettlement::class);
        $this->assertTrue($svc->settle($p->id,'1',8,now()));$this->assertFalse($svc->settle($p->id,'1',8,now()));
        $this->assertEquals('0',$p->fresh()->balance);$r=DB::table('funding_fee_distributions')->where('futures_contract_id',$p->id)->sole();
        $this->assertSame(0,bccomp('1',$r->funding_fee_amount,18));$this->assertSame(0,bccomp('100',$r->theoretical_fee,18));$this->assertSame(0,bccomp('-1',$r->balance_delta,18));$this->assertSame(0,bccomp('99',$r->shortfall,18));
        $young=$this->future(['created_at'=>now(),'activated_at'=>now()]);$this->assertFalse($svc->settle($young->id,'1',8,now()));
        $short=$this->future(['balance'=>'1','quantity'=>'1','is_long'=>false,'created_at'=>now()->subHours(9),'activated_at'=>now()->subHours(9)]);
        $this->assertTrue($svc->settle($short->id,'-2',8,now()));$this->assertEquals('0',$short->fresh()->balance);
        $r=DB::table('funding_fee_distributions')->where('futures_contract_id',$short->id)->sole();$this->assertSame(0,bccomp('-1',$r->funding_fee_amount,18));$this->assertSame(0,bccomp('1',$r->shortfall,18));
    }
    private function freshQuote(string $price='100'):void {Http::fake(['*'=>Http::response(['symbol'=>'BTCUSDT','lastPrice'=>$price,'closeTime'=>now()->timestamp*1000])]);}
    private function future(array $extra=[]):\App\Models\Order\FuturesContract {return \App\Models\Order\FuturesContract::factory()->create(array_replace(['user_id'=>$this->trader->id,'market_id'=>$this->market->id,'base_currency_id'=>$this->market->base_currency_id,'quote_currency_id'=>$this->market->quote_currency_id,'referral_balance_domain'=>'real'],$extra));}
    private function option(array $extra=[]):\App\Models\Option\Option {
        $wallet=Wallet::where('user_id',$this->trader->id)->where('currency_id',$this->market->quote_currency_id)->firstOrFail();
        return \App\Models\Option\Option::factory()->create(array_replace(['user_id'=>$this->trader->id,'market_id'=>$this->market->id,'currency_id'=>$this->market->quote_currency_id,'funding_domain'=>'real','funding_wallet_id'=>$wallet->id,'fee'=>'2','fee_refund_amount'=>'0.5'],$extra));
    }
    public function test_option_review_refunds_original_domain_once_and_requires_a_second_operator():void {
        $o=$this->option();$svc=app(\App\Services\Option\OptionSettlementReview::class);$svc->enqueue($o->id,now()->subMinute());$svc->enqueue($o->id,now()->subMinute());
        $this->assertSame('review_required',$o->fresh()->status);$this->assertSame(1,DB::table('option_settlement_reviews')->where('option_id',$o->id)->count());
        $a=User::factory()->create();$b=User::factory()->create();$svc->requestRefund($o->id,$a->id,'Verified quote missing at expiry');
        try{$svc->approveRefund($o->id,$a->id,'Same operator should fail');$this->fail();}catch(\Illuminate\Validation\ValidationException $e){}
        $wallet=Wallet::findOrFail($o->funding_wallet_id);$before=$wallet->balance_in_trade;$v=$wallet->balance_in_virtual_wallet;
        $svc->approveRefund($o->id,$b->id,'Independent operator verified refund');$svc->approveRefund($o->id,$b->id,'Retry of completed request');
        $this->assertSame(0,bccomp(bcadd($before,'101.5',18),$wallet->fresh()->balance_in_trade,18));$this->assertEquals($v,$wallet->fresh()->balance_in_virtual_wallet);$this->assertSame('closed',$o->fresh()->status);
        $settled=$this->option(['status'=>'won']);$svc->enqueue($settled->id,now()->subMinute());$this->assertFalse(DB::table('option_settlement_reviews')->where('option_id',$settled->id)->exists());
        try{$svc->requestRefund($settled->id,$a->id,'Already settled is prohibited');$this->fail();}catch(\Illuminate\Validation\ValidationException $e){}
    }
    public function test_option_refund_rejects_mismatched_wallet_owner_atomically():void {
        $other=User::factory()->create();$w=Wallet::factory()->create(['user_id'=>$other->id,'currency_id'=>$this->market->quote_currency_id]);$o=$this->option(['funding_wallet_id'=>$w->id]);
        $svc=app(\App\Services\Option\OptionSettlementReview::class);$svc->enqueue($o->id,now()->subMinute());$svc->requestRefund($o->id,$this->trader->id,'Reject mismatched funding identity');
        try{$svc->approveRefund($o->id,$other->id,'Independent approval cannot bypass owner');$this->fail();}catch(\Illuminate\Database\Eloquent\ModelNotFoundException $e){}
        $this->assertSame('review_required',$o->fresh()->status);$this->assertSame('refund_requested',DB::table('option_settlement_reviews')->where('option_id',$o->id)->value('status'));
    }
    public function test_product_and_side_switches_block_options_before_debit():void {
        $svc=app(\App\Services\Order\ProductTradingAvailability::class);$this->market->has_options=true;$svc->check($this->market,'options','buy');
        $this->market->buy_order_status=false;
        try{$svc->check($this->market,'options','buy');$this->fail();}catch(\Illuminate\Validation\ValidationException $e){}
        $svc->check($this->market,'options','sell');$this->market->has_options=false;
        try{$svc->check($this->market,'options','sell');$this->fail();}catch(\Illuminate\Validation\ValidationException $e){}
    }
    public function test_futures_and_options_http_retries_keep_one_contract_and_debit():void {
        $this->market->forceFill(['has_futures'=>true,'has_options'=>true,'options_min_amount'=>'1','options_max_amount'=>'1000'])->save();$this->freshQuote();
        $payload=['market'=>$this->market->name,'type'=>'market','side'=>'buy','leverage'=>10,'quoteQuantity'=>'10','client_order_id'=>'futures-repeat'];
        $a=$this->postJson('/api/v1/futures',$payload)->assertOk()->json('message');$b=$this->postJson('/api/v1/futures',$payload)->assertOk()->json('message');$this->assertSame($a,$b);
        $this->assertSame(1,\App\Models\Order\FuturesContract::where('user_id',$this->trader->id)->count());
        $payload=['market'=>$this->market->name,'type'=>1,'side'=>'buy','quantity'=>'10','startAt'=>now()->addMinute()->getTimestampMs(),'timeframeSeconds'=>60,'client_order_id'=>'options-repeat'];
        $a=$this->postJson('/api/v1/options',$payload)->assertOk()->json('message');$before=Wallet::where('user_id',$this->trader->id)->where('currency_id',$this->market->quote_currency_id)->value('balance_in_trade');
        $this->travel(2)->minutes();$b=$this->postJson('/api/v1/options',$payload)->assertOk()->json('message');$this->travelBack();$this->assertSame($a,$b);
        $this->assertSame(1,\App\Models\Option\Option::where('user_id',$this->trader->id)->count());$this->assertEquals($before,Wallet::where('user_id',$this->trader->id)->where('currency_id',$this->market->quote_currency_id)->value('balance_in_trade'));
    }
    public function test_copy_open_atomic_receipt_prevents_duplicate_follower_contracts():void {
        $source=$this->future(['price'=>'100','balance'=>'10']);$trader=\App\Models\CopyTrading\CopyTradingTrader::create(['user_id'=>$this->trader->id,'display_name'=>'Local test','is_enabled'=>true]);
        $follower=User::factory()->create(['is_xn'=>false,'deactivated'=>false]);$follow=\App\Models\CopyTrading\CopyTradingFollow::create(['copy_trading_trader_id'=>$trader->id,'trader_user_id'=>$this->trader->id,'follower_user_id'=>$follower->id,'is_enabled'=>true]);
        $svc=new class extends \App\Services\CopyTrading\CopyTradingService {
            public int $calls=0;
            protected function buildFollowerPayload(User $follower,array $payload):?array{return $payload;}
            protected function openFollowerPosition(User $follower,array $payload):string{$this->calls++;$p=\App\Models\Order\FuturesContract::findOrFail($payload['copy_trading_source_contract_id'])->replicate();$p->id=(string)generate_uuid();$p->user_id=$follower->id;$p->save();return $p->id;}
        };
        $first=$svc->copyFuturesOpen($source->id);$second=$svc->copyFuturesOpen($source->id);
        $this->assertSame(1,$first['success']);$this->assertSame(1,$second['skipped']);$this->assertSame(1,$svc->calls);$this->assertSame(1,\App\Models\Order\FuturesContract::where('user_id',$follower->id)->count());
        $this->assertSame(1,DB::table('copy_trading_events')->where('follow_id',$follow->id)->count());
        $svc2=new class extends \App\Services\CopyTrading\CopyTradingService {public function verify(User $u,array $p){return $this->openFollowerPosition($u,$p);}};
        try{$svc2->verify($follower,['market'=>$this->market->name,'type'=>'market','side'=>'buy','leverage'=>999,'quoteQuantity'=>'10']);$this->fail();}catch(\Illuminate\Validation\ValidationException $e){$this->assertArrayHasKey('leverage',$e->errors());}
    }
    public function test_failed_settlement_emits_no_trade_or_market_cache_before_commit():void {
        $this->freshQuote();$events=0;$old=Cache::get('market.'.$this->market->id.'.last');
        DB::beginTransaction();
        event(new OrderBookUpdated(['order'=>['settlement_domain'=>'real'],'name'=>$this->market->name,'decimals'=>8],'cancel'));
        DB::afterCommit(function()use(&$events){$events++;Cache::put('market.'.$this->market->id.'.last','999');});
        Event::assertNotDispatched(OrderBookUpdated::class);$this->assertSame($old,Cache::get('market.'.$this->market->id.'.last'));DB::rollBack();$this->assertSame(0,$events);
        DB::beginTransaction();DB::afterCommit(function()use(&$events){$events++;});DB::commit();$this->assertSame(1,$events);
    }
    public function test_sql_counterparty_selection_keeps_domain_and_price_time_priority():void {
        $maker=User::factory()->create();$v=User::factory()->create();
        foreach([$maker,$v] as$u) foreach([$this->market->base_currency_id,$this->market->quote_currency_id] as$c)Wallet::factory()->create(['user_id'=>$u->id,'currency_id'=>$c,'balance_in_order'=>$u->id===$maker->id?'5':'0','balance_in_virtual_order'=>$u->id===$v->id?'5':'0']);
        $base=['market_id'=>$this->market->id,'base_currency_id'=>$this->market->base_currency_id,'quote_currency_id'=>$this->market->quote_currency_id,'side'=>'sell','type'=>'limit','quantity'=>'1','fee_rate'=>'0'];
        Order::factory()->create($base+['user_id'=>$v->id,'price'=>'80','settlement_domain'=>'virtual']);
        $later=Order::factory()->create($base+['user_id'=>$maker->id,'price'=>'90','settlement_domain'=>null,'created_at'=>now()]);
        $first=Order::factory()->create($base+['user_id'=>$maker->id,'price'=>'90','settlement_domain'=>'real','created_at'=>now()->subMinute()]);
        $repo=app(\App\Repositories\Order\OrderRepository::class);$this->assertSame($first->id,$repo->getMatchedOrder('limit','buy',$this->market->id,'100',$this->trader->id,'real')->id);
        $this->assertSame('80',$repo->getMatchedOrder('limit','buy',$this->market->id,'100',$this->trader->id,'virtual')->price);
        $first->delete();$this->assertSame($later->id,$repo->getMatchedOrder('limit','buy',$this->market->id,'100',$this->trader->id,'real')->id);
    }
    public function test_verified_price_is_rechecked_before_pending_limit_activation():void {
        $p=$this->future(['type'=>'limit','status'=>'pending','price'=>'90','balance'=>'10','quantity'=>'1','trade_margin_amount'=>'10']);
        Wallet::where('user_id',$this->trader->id)->where('currency_id',$this->market->quote_currency_id)->update(['balance_in_order'=>'10']);
        $this->freshQuote('100');app(\App\Repositories\Order\OrderRepository::class)->processFuturesLimitOrder($p);$this->assertSame('pending',$p->fresh()->status);
        Cache::forget('options:verified-quote:BTCUSDT');Http::swap(new \Illuminate\Http\Client\Factory());$this->freshQuote('89');app(\App\Repositories\Order\OrderRepository::class)->processFuturesLimitOrder($p->fresh());
        $this->assertSame('active',$p->fresh()->status);$this->assertSame('89',$p->fresh()->price);$this->assertStringStartsWith('binance:spot:',$p->fresh()->opening_price_source);
    }
    public function test_actual_copy_open_close_and_retry_use_one_receipt_and_original_funding_domain():void {
        $this->market->forceFill(['has_futures'=>true])->save();$this->freshQuote();$source=$this->future(['price'=>'100','balance'=>'10']);
        $trader=\App\Models\CopyTrading\CopyTradingTrader::create(['user_id'=>$this->trader->id,'display_name'=>'Local acceptance','is_enabled'=>true]);
        $follower=User::factory()->create(['is_xn'=>false,'deactivated'=>false,'vip'=>0,'referral_id'=>null]);$wallet=Wallet::factory()->create(['user_id'=>$follower->id,'currency_id'=>$this->market->quote_currency_id,'balance_in_trade'=>'1000','balance_in_virtual_trade'=>'0']);
        $follow=\App\Models\CopyTrading\CopyTradingFollow::create(['copy_trading_trader_id'=>$trader->id,'trader_user_id'=>$this->trader->id,'follower_user_id'=>$follower->id,'is_enabled'=>true]);
        $svc=app(\App\Services\CopyTrading\CopyTradingService::class);$result=$svc->copyFuturesOpen($source->id);$this->assertSame(1,$result['success'],json_encode($result));
        $this->assertSame(1,$svc->copyFuturesOpen($source->id)['skipped']);$copied=\App\Models\Order\FuturesContract::where('user_id',$follower->id)->sole();$this->assertSame('real',$copied->referral_balance_domain);$this->assertLessThan(1000,(float)$wallet->fresh()->balance_in_trade);
        $this->assertSame(1,$svc->copyFuturesClose($source->id)['success']);$this->assertSame(0,$svc->copyFuturesClose($source->id)['success']);$this->assertEquals('0',$wallet->fresh()->balance_in_virtual_trade);
        $this->assertSame('close_success',DB::table('copy_trading_copied_orders')->where('copy_trading_follow_id',$follow->id)->value('status'));
    }
    public function test_legacy_finance_full_redemption_preserves_database_precision_and_cannot_repeat():void {
        $wallet=Wallet::where('user_id',$this->trader->id)->where('currency_id',$this->market->quote_currency_id)->firstOrFail();$wallet->balance_in_trade='0';$wallet->save();
        $amount='123456789012345.123456789123456789';$id=DB::table('auto_invest_orders')->insertGetId(['order_no'=>'exact-'.uniqid(),'user_id'=>$this->trader->id,'currency_id'=>$this->market->quote_currency_id,'wallet_id'=>$wallet->id,'amount'=>$amount,'principal_amount'=>$amount,'investment_type'=>'fixed','status'=>'active','matured_at'=>now()->subDay(),'started_at'=>now()->subDays(2),'created_at'=>now(),'updated_at'=>now()]);
        $svc=app(\App\Services\Wallet\AutoInvestOrderService::class);$svc->redeemOrder($this->trader,$id);$this->assertSame($amount,$wallet->fresh()->balance_in_trade);$this->assertSame('closed',DB::table('auto_invest_orders')->where('id',$id)->value('status'));
        try{$svc->redeemOrder($this->trader,$id);$this->fail();}catch(\Exception $e){}$this->assertSame($amount,$wallet->fresh()->balance_in_trade);
    }
    public function test_real_close_with_stale_price_returns_validation_error_and_preserves_position():void {
        $p=$this->future(['balance'=>'10']);Http::fake(['*'=>Http::response(['symbol'=>'BTCUSDT','lastPrice'=>'100','closeTime'=>now()->subMinute()->timestamp*1000])]);
        $before=Wallet::where('user_id',$this->trader->id)->where('currency_id',$this->market->quote_currency_id)->value('balance_in_trade');
        $this->postJson('/api/v1/orders/futures/cancel',['uuid'=>$p->id])->assertStatus(422)->assertJsonValidationErrors('price');
        $this->assertSame('active',$p->fresh()->status);$this->assertEquals($before,Wallet::where('user_id',$this->trader->id)->where('currency_id',$this->market->quote_currency_id)->value('balance_in_trade'));
    }
}
