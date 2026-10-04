<?php
namespace Tests\Feature\Deepro;

use App\Models\{Currency\Currency,Market\Market,Order\Order,User\User,Wallet\Wallet};
use App\Services\Market\{HongKongMarketData,HongKongPriceProduct,HongKongProductListing,FundedLiquidity,AssetProfile,StockAssets,StockDataClient,StockReferenceData};
use App\Services\Order\OrderService;
use Carbon\{Carbon,CarbonImmutable};
use Illuminate\Support\Facades\{DB,Cache,Event,Http,Queue};
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class HongKongPriceProductTest extends TestCase
{
    private Market $market;
    private int $eventTime;
    private ?array $candleLines=null;
    protected function setUp():void
    {
        parent::setUp();
        $this->assertSame('deepro_test',DB::connection()->getDatabaseName());
        config(['hk-price-products.platform_quotes.enabled'=>false,'cache.default'=>'array','broadcasting.default'=>'log','hk-price-products.trading_enabled'=>true,'hk-price-products.assets.HK08379.tradingEnabled'=>true]);
        Carbon::setTestNow('2026-09-29 10:00:00 Asia/Hong_Kong'); CarbonImmutable::setTestNow('2026-09-29 10:00:00 Asia/Hong_Kong');
        DB::beginTransaction(); Event::fake(); Queue::fake(); Http::preventStrayRequests();
        $this->eventTime=CarbonImmutable::now()->timestamp-900;
        $this->feeds();
        $this->market=app(HongKongProductListing::class)->apply('HK08379',true);
        $this->market->update(['min_trade_size'=>'0.00000001','max_trade_size'=>'100000','min_trade_value'=>'0.000001','max_trade_value'=>'100000','min_market_buy_amount'=>'0','base_ticker_size'=>'0.00000001']);
    }
    protected function tearDown():void
    {
        while(DB::transactionLevel()>0)DB::rollBack(); Carbon::setTestNow(); CarbonImmutable::setTestNow(); parent::tearDown();
    }
    private function feeds(bool $fx=true):void
    {
        Cache::forget('hk-reference.quote.08379');Cache::forget('hk-reference.fx');
        Http::fake(function($request) use($fx) {
            if(str_contains($request->url(),'push2.eastmoney.com') && str_contains($request->url(),'116.08379'))
                return Http::response(['rc'=>0,'data'=>['f57'=>'08379','f107'=>116,'f43'=>0.103,'f44'=>0.105,'f45'=>0.099,'f86'=>$this->eventTime,'f170'=>1.98]]);
            if(str_contains($request->url(),'push2.eastmoney.com') && str_contains($request->url(),'119.USDHKD'))
                return Http::response($fx?['rc'=>0,'data'=>['f57'=>'USDHKD','f43'=>7.8,'f86'=>CarbonImmutable::now()->timestamp]]:[], $fx?200:503);
            if(str_contains($request->url(),'api.kraken.com'))return Http::response(['error'=>[],'result'=>['USDTZUSD'=>[['1.00000000','2',CarbonImmutable::now()->timestamp+.1,'b','l','',1]]]]);
            if(str_contains($request->url(),'push2his.eastmoney.com')) {
                $lines=$this->candleLines ?? ['2026-09-28,0.1,0.103,0.11,0.09,1000,103','2026-04-21,0.182,0.172,0.180,0.172,100,17'];
                parse_str(parse_url($request->url(),PHP_URL_QUERY),$query);
                if($this->candleLines!==null && ($query['klt']??null)==101)
                    $lines=array_slice(array_values(array_filter($lines,fn($line)=>str_replace('-','',substr($line,0,10))<=$query['end'])),-(int)$query['lmt']);
                return Http::response($this->candleBody($lines));
            }
            return Http::response(['error'=>'old_provider_offline'],503);
        });
    }
    private function candleBody(array $lines):array {return ['rc'=>0,'data'=>['code'=>'08379','market'=>116,'klines'=>$lines]];}
    private function user(string $base,string $quote):User
    {
        $user=User::withoutEvents(fn()=>User::factory()->create(['email_verified_at'=>now(),'vip'=>0,'referral_id'=>null]));
        foreach ([$this->market->base_currency_id=>$base,$this->market->quote_currency_id=>$quote] as $id=>$balance)
            Wallet::factory()->create(['user_id'=>$user->id,'currency_id'=>$id,'balance_in_trade'=>$balance]);
        return $user;
    }
    private function place(User $user,string $side,string $type,array $d)
    {
        Sanctum::actingAs($user,['trade']);return $this->postJson('/api/v1/orders',['market'=>$this->market->name,'side'=>$side,'type'=>$type]+$d);
    }
    private function balance(User $user,int $currency):float {return (float)Wallet::where('user_id',$user->id)->where('currency_id',$currency)->value('balance_in_trade');}

    public function test_listing_is_idempotent_chainless_and_creates_no_inventory():void
    {
        $wallets=Wallet::count();$currencies=Currency::count();
        DB::table('market_execution_policies')->insert(['market_id'=>$this->market->id,'mode'=>'platform_credit','max_quote_per_fill'=>'250','created_at'=>now(),'updated_at'=>now()]);
        $again=app(HongKongProductListing::class)->apply('HK08379');
        $this->assertSame('platform_credit',app(FundedLiquidity::class)->policy($again)->mode);
        $this->assertEquals(250,(float)app(FundedLiquidity::class)->policy($again)->max_quote_per_fill);
        $this->assertSame($this->market->id,$again->id);$this->assertSame($currencies,Currency::count());$this->assertSame($wallets,Wallet::count());
        $this->assertEquals(0,(float)Wallet::where('currency_id',$again->base_currency_id)->sum('balance_in_trade'));
        $c=$again->baseCurrency;$this->assertFalse((bool)$c->deposit_status);$this->assertFalse((bool)$c->withdraw_status);$this->assertSame(0,$c->networks()->count());
        $a=AssetProfile::presentation($c);$this->assertNull($a['chain']);$this->assertNull($a['contract']);$this->assertSame('HKD',$a['referenceCurrency']);
        $this->assertNotNull(AssetProfile::networkError($c,'bep20'));$this->assertFalse($again->has_futures);$this->assertFalse($again->has_options);
    }
    public function test_fifteen_minute_reference_allows_real_user_roundtrip_without_fx_or_platform_credit():void
    {
        $this->feeds(false);$seller=$this->user('10','100');$buyer=$this->user('0','100');
        DB::table('platform_credit_pools')->where('id',1)->update(['enabled'=>true,'default_for_usdt'=>true]);
        $this->place($seller,'sell','limit',['quantity'=>'5','price'=>'0.013'])->assertOk();
        $id=$this->place($buyer,'buy','limit',['quantity'=>'5','price'=>'0.014'])->assertOk()->json('message');
        $this->assertEquals(0.013,(float)DB::table('transactions')->where('order_id',$id)->sole()->price);
        $this->assertEquals(5,$this->balance($buyer,$this->market->base_currency_id));
        $this->place($buyer,'sell','limit',['quantity'=>'2','price'=>'0.014'])->assertOk();
        $reverse=$this->place($seller,'buy','market',['quoteQuantity'=>'0.03'])->assertOk()->json('message');
        $this->assertEquals(2,(float)DB::table('transactions')->where('order_id',$reverse)->sum('base_currency'));
        $this->assertEqualsWithDelta(10,$this->balance($buyer,$this->market->base_currency_id)+$this->balance($seller,$this->market->base_currency_id),1e-8);
        $fees=(float)DB::table('transactions')->where('market_id',$this->market->id)->sum('fee');
        $this->assertEqualsWithDelta(200-$fees,$this->balance($buyer,$this->market->quote_currency_id)+$this->balance($seller,$this->market->quote_currency_id),1e-8);
        $this->assertSame(0,DB::table('platform_credit_fills')->where('market_id',$this->market->id)->count());
        $this->assertSame('platform_credit',app(FundedLiquidity::class)->policy($this->market)->mode);
        Http::assertNotSent(fn($r)=>$r->method()!=='GET' || str_contains($r->url(),'kraken'));
    }
    public function test_no_counterparty_means_no_market_fill_even_with_global_credit_enabled():void
    {
        DB::table('platform_credit_pools')->where('id',1)->update(['enabled'=>true,'default_for_usdt'=>true]);
        $user=$this->user('0','100');
        $this->place($user,'buy','market',['quoteQuantity'=>'1'])->assertStatus(422);
        $this->assertEquals(100,$this->balance($user,$this->market->quote_currency_id));
        $this->assertEquals(['bids'=>[],'asks'=>[]],app(FundedLiquidity::class)->levels($this->market));
    }
    public function test_closed_sessions_reject_all_order_types_without_reserving_balances():void
    {
        $user=$this->user('2','100');$before=DB::table('wallets')->where('user_id',$user->id)->orderBy('id')->get()->toJson();
        $orders=Order::count();$trades=DB::table('transactions')->count();
        foreach (['2026-09-29 09:29:59','2026-09-29 12:00:00','2026-09-29 16:00:00','2026-10-01 10:00:00','2026-12-24 13:00:00'] as $time) {
            Carbon::setTestNow($time.' Asia/Hong_Kong');CarbonImmutable::setTestNow($time.' Asia/Hong_Kong');
            foreach (['buy','sell'] as $side) foreach (['limit','market','stop_limit'] as $type) {
                $input=$side==='buy'&&$type==='market'?['quoteQuantity'=>'1']:['quantity'=>'1','price'=>'0.01'];
                if($type==='stop_limit')$input+=['trigger_price'=>'0.02','trigger_condition'=>'down'];
                $this->place($user,$side,$type,$input)->assertStatus(422)->assertJsonValidationErrors('market');
            }
        }
        $this->assertSame($orders,Order::count());$this->assertSame($trades,DB::table('transactions')->count());
        $this->assertSame($before,DB::table('wallets')->where('user_id',$user->id)->orderBy('id')->get()->toJson());Http::assertNothingSent();
    }
    public function test_repository_rechecks_session_even_without_request_validation():void
    {
        $user=$this->user('2','100');Sanctum::actingAs($user,['trade']);
        CarbonImmutable::setTestNow('2026-09-29 12:00:00 Asia/Hong_Kong');
        request()->merge(['market'=>$this->market->name,'side'=>'buy','type'=>'limit','quantity'=>'1','price'=>'0.01']);
        $before=DB::table('wallets')->where('user_id',$user->id)->orderBy('id')->get()->toJson();
        try {app(\App\Repositories\Order\OrderRepository::class)->store();$this->fail('Closed order accepted');}
        catch (\Illuminate\Validation\ValidationException $e) {$this->assertArrayHasKey('market',$e->errors());}
        $this->assertSame($before,DB::table('wallets')->where('user_id',$user->id)->orderBy('id')->get()->toJson());
    }
    public function test_queued_crossing_orders_do_not_fill_after_close_and_remain_cancelable():void
    {
        $buyer=$this->user('0','100');$seller=$this->user('2','0');
        $buy=$this->place($buyer,'buy','limit',['quantity'=>'1','price'=>'0.01'])->assertOk()->json('message');
        $ask=$this->place($seller,'sell','limit',['quantity'=>'1','price'=>'0.02'])->assertOk()->json('message');
        DB::table('orders')->where('id',$ask)->update(['price'=>'0.009']);
        CarbonImmutable::setTestNow('2026-09-29 12:00:00 Asia/Hong_Kong');
        app(OrderService::class)->processOrder(Order::findOrFail($buy));
        $this->assertSame(0,DB::table('transactions')->where('order_id',$buy)->count());
        $this->assertTrue(Order::whereKey($buy)->exists());
        Sanctum::actingAs($buyer,['trade']);$this->postJson('/api/v1/orders/cancel',['uuid'=>$buy])->assertOk();
        $this->assertEquals(100,$this->balance($buyer,$this->market->quote_currency_id));
    }
    public function test_suspension_is_enforced_during_an_open_session_and_metadata_agrees():void
    {
        config(['hk-price-products.assets.HK08379.suspended'=>true]);$u=$this->user('2','100');
        $this->place($u,'sell','limit',['quantity'=>'1','price'=>'0.01'])->assertStatus(422)->assertJsonValidationErrors('market');
        $s=app(HongKongPriceProduct::class)->marketSession($this->market);
        $this->assertFalse($s['can_trade']);$this->assertSame('suspended',$s['state']);$this->assertNull($s['next_open_at']);
    }
    public function test_stop_orders_wait_for_an_open_session_before_triggering():void
    {
        $user=$this->user('2','100');
        $id=$this->place($user,'sell','limit',['quantity'=>'1','price'=>'0.02'])->assertOk()->json('message');
        $order=Order::findOrFail($id);$order->type=Order::TYPE_STOP_LIMIT;$order->trigger_price='0.015';$order->trigger_condition='up';$order->save();
        // A valid, reached trigger isolates the session gate from trigger validation.
        Cache::put('market.'.$this->market->id.'.last','0.02');
        $repo=\Mockery::mock(\App\Repositories\Order\OrderRepository::class);
        $repo->shouldReceive('triggerStopLimitMatchedOrders')->twice()->andReturn(collect([$order]));
        $service=new OrderService($repo);
        CarbonImmutable::setTestNow('2026-09-29 12:00:00 Asia/Hong_Kong');
        $service->processStopLimitOrders($this->market->id);
        $this->assertSame(Order::TYPE_STOP_LIMIT,$order->fresh()->type);
        Queue::assertNotPushed(\App\Jobs\Order\CreateOrderJob::class);
        CarbonImmutable::setTestNow('2026-09-29 13:00:00 Asia/Hong_Kong');
        $service->processStopLimitOrders($this->market->id);
        $this->assertSame(Order::TYPE_LIMIT,$order->fresh()->type);
        Queue::assertPushed(\App\Jobs\Order\CreateOrderJob::class);
    }
    public function test_stop_trigger_prices_cannot_cross_market_boundaries():void
    {
        $user=$this->user('2','100');
        $id=$this->place($user,'sell','limit',['quantity'=>'1','price'=>'0.02'])->assertOk()->json('message');
        $order=Order::findOrFail($id);$order->type=Order::TYPE_STOP_LIMIT;$order->trigger_price='0.015';$order->trigger_condition='up';$order->save();
        $other=Market::whereName('BTC-USDT')->firstOrFail();
        $repo=app(\App\Repositories\Order\OrderRepository::class);
        $this->assertFalse($repo->triggerStopLimitMatchedOrders('100000',$other->id)->contains('id',$id));
        $this->assertFalse($repo->triggerStopLimitMatchedOrders('0.01',$this->market->id)->contains('id',$id));
        $this->assertTrue($repo->triggerStopLimitMatchedOrders('0.02',$this->market->id)->contains('id',$id));
    }
    public function test_poll_and_initial_resource_use_live_session_without_quote_dependency():void
    {
        foreach ([['2026-09-29 11:59:59',true],['2026-09-29 12:00:00',false],['2026-09-29 13:00:00',true]] as [$time,$open]) {
            CarbonImmutable::setTestNow($time.' Asia/Hong_Kong');
            $resource=(new \App\Http\Resources\Market\Market($this->market))->resolve();
            $poll=$this->getJson('/markets/'.$this->market->id.'/kline-delete-time')->assertOk()->json('market.trading_session');
            $this->assertSame($open,$resource['trading_session']['can_trade']);$this->assertSame($resource['trading_session'],$poll);
        }
        Http::assertNothingSent();
    }
    public function test_calendar_boundaries_next_open_and_exchange_timezone():void
    {
        $service=app(HongKongPriceProduct::class);
        foreach ([['2026-09-29 09:30:00',true],['2026-09-29 11:59:59',true],['2026-09-29 12:00:00',false],['2026-09-29 13:00:00',true],['2026-09-29 15:59:59',true],['2026-09-29 16:00:00',false],['2027-01-04 10:00:00',true],['2027-02-05 12:00:00',false]] as [$t,$open]) {
            $this->assertSame($open,$service->sessionOpen(CarbonImmutable::parse($t,'Asia/Hong_Kong')),$t);
        }
        foreach(config('hk-price-products.holidays') as $day) $this->assertFalse($service->sessionOpen(CarbonImmutable::parse($day.' 10:00','Asia/Hong_Kong')),$day);
        $s=$service->session(CarbonImmutable::parse('2026-09-30 08:00','UTC'));
        $this->assertFalse($s['is_open']);$this->assertSame('2026-10-02T09:30:00+08:00',$s['next_open_at']);
        $s=$service->session(CarbonImmutable::parse('2026-09-29 12:00','Asia/Hong_Kong'));
        $this->assertSame('lunch',$s['state']);$this->assertSame('2026-09-29T13:00:00+08:00',$s['next_open_at']);
        $s=$service->session(CarbonImmutable::parse('2027-12-31 13:00','Asia/Hong_Kong'));$this->assertNull($s['next_open_at']);
        $this->assertFalse($service->session(CarbonImmutable::parse('2028-01-03 10:00','Asia/Hong_Kong'))['calendar_available']);
    }
    public function test_pause_is_reread_by_matching_and_existing_orders_stay_cancelable():void
    {
        $user=$this->user('0','100');$id=$this->place($user,'buy','limit',['quantity'=>'1','price'=>'0.01'])->assertOk()->json('message');
        $seller=$this->user('1','0');$ask=$this->place($seller,'sell','limit',['quantity'=>'1','price'=>'0.02'])->assertOk()->json('message');
        // A queued pair becomes crossing before an operator pause; processing must re-read the switch.
        DB::table('orders')->where('id',$ask)->update(['price'=>'0.009']);
        Sanctum::actingAs($user,['trade']);
        $this->market->update(['trade_status'=>false]);
        $this->assertNotNull(app(HongKongPriceProduct::class)->tradingReason($this->market,false));
        app(OrderService::class)->processOrder(Order::findOrFail($id));
        $this->assertSame(0,DB::table('transactions')->where('order_id',$id)->count());
        $this->postJson('/api/v1/orders/cancel',['uuid'=>$id])->assertOk();$this->assertEquals(100,$this->balance($user,$this->market->quote_currency_id));
    }
    public function test_pair_identity_is_checked_at_execution():void
    {
        $this->market->update(['quote_currency_id'=>Currency::whereSymbol('USDC')->sole()->id]);
        $this->assertSame('Price reference product identity is invalid',app(HongKongPriceProduct::class)->tradingReason($this->market));
    }
    public function test_quote_sources_are_isolated_and_fx_never_changes_raw_hkd_history():void
    {
        $rows=app(StockDataClient::class)->call('/v1/stocks/quotes')['data'];$row=collect($rows)->firstWhere('symbol','HK08379');
        $this->assertSame('HKD',$row['underlyingCurrency']);$this->assertSame('0.103',$row['underlyingPrice']);
        $this->assertEqualsWithDelta(.103/7.8,(float)$row['price'],1e-12);$this->assertNull($row['high']);$this->assertNull($row['change']);
        $this->assertSame(900,$row['referenceAgeSeconds']);$this->assertNotEmpty(collect($rows)->filter(fn($r)=>($r['symbol']!=='HK08379')&&($r['unavailable']??false)));
        $history=app(StockReferenceData::class)->history($this->market,0,CarbonImmutable::now()->timestamp,'1D');
        $this->assertSame('HKD',$history['currency']);$this->assertSame([.103],$history['c']);$this->assertSame([1000.0],$history['v']);$this->assertSame(1,$history['rejected_bars']);
    }
    public function test_native_quote_request_never_waits_for_unrelated_provider_or_fx():void
    {
        $data=app(StockDataClient::class)->call('/v1/stocks/quotes',['assets'=>[StockAssets::find('HK08379')],'native_only'=>true]);
        $this->assertSame('0.103',$data['data'][0]['underlyingPrice']);$this->assertNull($data['data'][0]['price']);
        Http::assertNotSent(fn($r)=>!str_contains($r->url(),'116.08379'));
    }
    public function test_chart_countback_returns_real_older_trading_bars_and_excludes_to():void
    {
        $to=CarbonImmutable::parse('2026-09-29','UTC');$lines=[];$day=$to;
        while(count($lines)<301) {
            if($day->isWeekday())$lines[]=$day->format('Y-m-d').',0.1,0.103,0.11,0.09,1000,103';
            $day=$day->subDay();
        }
        $this->candleLines=array_reverse($lines);
        $fx=['ecb'=>[],'usdt'=>[],'received_at'=>time()];
        foreach($lines as $line){$d=CarbonImmutable::parse(substr($line,0,10),'UTC')->subDay()->format('Y-m-d');$fx['ecb'][$d]=['USD'=>1.0,'HKD'=>8.0];$fx['usdt'][$d]=1.0;}
        ksort($fx['ecb']);ksort($fx['usdt']);Cache::put('display.fx.history.v1',$fx,300);
        $response=$this->getJson('/tradingview-chart/history?'.http_build_query(['symbol'=>'HK08379-USDT','resolution'=>'1D','from'=>$to->subDays(10)->timestamp,'to'=>$to->timestamp,'countback'=>300]))->assertOk();
        $data=$response->json();$this->assertSame('ok',$data['s']);$this->assertSame('USDT',$data['currency']);
        $this->assertCount(300,$data['t']);$this->assertCount(300,array_unique($data['t']));
        $this->assertLessThan($to->subDays(10)->timestamp,$data['t'][0]);
        $this->assertSame($to->subDay()->timestamp,end($data['t']));
        $this->assertSame(array_fill(0,300,.103/8),$data['c']);
        Http::assertSentCount(1);
        Http::assertSent(fn($r)=>str_contains($r->url(),'end=20260928'));
    }
    public function test_countback_fills_a_rejected_bar_from_older_history_with_both_sources_identified():void
    {
        $day=CarbonImmutable::parse('2026-09-28','UTC');$recent=[];
        for($i=0;$i<299;$i++){$recent[]=$day->format('Y-m-d').',0.1,0.103,0.11,0.09,1000,103';$day=$day->subDay();}
        $recent=array_reverse($recent);$recent[]='2026-09-29,0.182,0.172,0.180,0.172,100,17';
        $olderDate=$day->format('Y-m-d');$requests=0;
        $qt=array_fill(0,78,'0');foreach([0=>'100',2=>'08379',3=>'0.103',30=>'2026/09/29 09:45:00',33=>'0.103',34=>'0.095',75=>'HKD']as$k=>$v)$qt[$k]=$v;
        Http::swap(new \Illuminate\Http\Client\Factory());Http::preventStrayRequests();
        Http::fake(function($r)use(&$requests,$recent,$olderDate,$qt){
            if(str_contains($r->url(),'eastmoney.com'))return ++$requests===1?Http::response($this->candleBody($recent)):Http::response('',503);
            if(str_contains($r->url(),'web.ifzq.gtimg.cn'))return Http::response(['code'=>0,'data'=>['hk08379'=>['day'=>[[$olderDate,'0.1','0.103','0.11','0.09','1000']],'qt'=>['hk08379'=>$qt]]]]);
            throw new \RuntimeException('Unexpected request');
        });
        $data=app(StockReferenceData::class)->history($this->market,0,CarbonImmutable::now()->timestamp,'1D',300);
        $this->assertCount(300,$data['t']);$this->assertSame(CarbonImmutable::parse($olderDate,'UTC')->timestamp,$data['t'][0]);
        $this->assertSame('Eastmoney public reference + Tencent public reference',$data['source']);
        $this->assertSame(1,$data['rejected_bars']);$this->assertSame('HKD',$data['currency']);Http::assertSentCount(3);
    }
    public function test_older_page_outage_preserves_valid_recent_bars_without_filling_the_gap():void
    {
        $bars=[];$day=CarbonImmutable::parse('2026-09-28','UTC');
        for($i=0;$i<299;$i++){$bars[]=['time'=>$day->timestamp*1000,'open'=>.1,'high'=>.11,'low'=>.09,'close'=>.103,'volume'=>1000];$day=$day->subDay();}
        $feed=\Mockery::mock(HongKongMarketData::class);
        $feed->shouldReceive('candles')->once()->ordered()->andReturn(['data'=>array_reverse($bars),'rejected_bars'=>1,'source'=>'Eastmoney public reference']);
        $feed->shouldReceive('candles')->once()->ordered()->andThrow(new \RuntimeException('upstream unavailable'));
        $this->app->instance(HongKongMarketData::class,$feed);
        $data=app(StockReferenceData::class)->history($this->market,0,CarbonImmutable::now()->timestamp,'1D',300);
        $this->assertSame('ok',$data['s']);$this->assertCount(299,$data['t']);$this->assertTrue($data['partial_history']);
        $this->assertSame('Eastmoney public reference',$data['source']);$this->assertSame(1,$data['rejected_bars']);Http::assertNothingSent();
    }
    public function test_candle_cache_does_not_reuse_a_different_intraday_end_time():void
    {
        $this->candleLines=[
            '2026-09-28 10:05,0.1,0.103,0.11,0.09,10,1',
            '2026-09-28 14:05,0.1,0.103,0.11,0.09,10,1',
        ];
        $feed=app(HongKongMarketData::class);$asset=StockAssets::find('HK08379');
        $early=CarbonImmutable::parse('2026-09-28 11:00','Asia/Hong_Kong')->timestamp;
        $late=CarbonImmutable::parse('2026-09-28 15:00','Asia/Hong_Kong')->timestamp;
        $this->assertCount(1,$feed->candles($asset,'5m',120,$early)['data']);
        $this->assertCount(2,$feed->candles($asset,'5m',120,$late)['data']);
    }
    public function test_hour_aggregation_does_not_cross_lunch_or_synthesize_missing_bars():void
    {
        $feed=app(HongKongMarketData::class);$rows=[];
        foreach ([['2026-09-28 11:30',6],['2026-09-28 13:00',12],['2026-09-28 14:00',11]] as [$start,$count])
            for($i=0;$i<$count;$i++)$rows[]=['time'=>CarbonImmutable::parse($start,'Asia/Hong_Kong')->addMinutes($i*5)->timestamp*1000,'open'=>.1,'high'=>.12,'low'=>.09,'close'=>.11,'volume'=>10.0];
        $hours=$feed->aggregateHours($rows);$this->assertCount(2,$hours);$this->assertSame(60.0,$hours[0]['volume']);$this->assertSame(120.0,$hours[1]['volume']);
        $count=0;$bars=$feed->normalizeCandles($this->candleBody(['2026-09-28 13:05,0.1,0.103,0.11,0.09,1000,103']),'08379','5m',$count);
        $this->assertSame(CarbonImmutable::parse('2026-09-28 13:00','Asia/Hong_Kong')->timestamp*1000,$bars[0]['time']);
    }
    public function test_calendar_full_holidays_half_days_and_unknown_year_fail_closed():void
    {
        $service=app(HongKongPriceProduct::class);
        foreach (['2026-10-01 10:00','2026-09-27 10:00','2026-12-24 13:30','2028-01-04 10:00','2026-09-29 12:00','2026-09-29 16:00'] as $time)
            $this->assertFalse($service->sessionOpen(CarbonImmutable::parse($time,'Asia/Hong_Kong')),$time);
        $this->assertTrue($service->sessionOpen(CarbonImmutable::parse('2026-12-24 10:00','Asia/Hong_Kong')));
    }
    public function test_command_requires_superadmin_and_audits_idempotent_pause_without_issuing_funds():void
    {
        $user=$this->user('0','0');$key=(string)\Illuminate\Support\Str::uuid();
        $args=['--apply'=>true,'--pause-trading'=>true,'--actor'=>$user->id,'--reason'=>'isolated QA pause','--request-key'=>$key];
        $this->artisan('deepro:hk-price-product',$args)->assertExitCode(1);
        \Spatie\Permission\Models\Role::findOrCreate('superadmin','web');$user->assignRole('superadmin');
        $before=DB::table('wallets')->orderBy('id')->get()->toJson();
        $this->artisan('deepro:hk-price-product',$args)->assertExitCode(0);
        $this->artisan('deepro:hk-price-product',$args)->assertExitCode(0);
        $this->artisan('deepro:hk-price-product',array_replace($args,['--reason'=>'different audit reason']))->assertExitCode(1);
        $this->assertSame(1,DB::table('operations_events')->where('request_key',$key)->count());
        $this->assertFalse($this->market->fresh()->trade_status);
        $this->assertSame($before,DB::table('wallets')->orderBy('id')->get()->toJson());
    }
    public function test_asset_edit_cannot_turn_price_product_into_a_chain_token():void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        AssetProfile::validateEdit($this->market->baseCurrency,['contract'=>'0x1111111111111111111111111111111111111111']);
    }
    public function test_wrong_quote_identity_and_future_fx_are_rejected():void
    {
        $feed=app(HongKongMarketData::class);
        try {$feed->normalizeQuote(['rc'=>0,'data'=>['f57'=>'00001','f107'=>116]],'08379');$this->fail('wrong identity accepted');}catch(\RuntimeException $e){$this->assertSame('hk_quote_identity_mismatch',$e->getMessage());}
        $this->expectException(\RuntimeException::class);$feed->normalizeUsdtUsd(['error'=>[],'result'=>['USDTZUSD'=>[['1','2',CarbonImmutable::now()->timestamp+20]]]]);
    }
}
