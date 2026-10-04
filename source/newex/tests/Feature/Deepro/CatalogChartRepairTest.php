<?php
namespace Tests\Feature\Deepro;

use App\Models\{Currency\Currency,Market\Market};
use App\Services\Market\HongKongProductListing;
use App\Services\Operations\History;
use Illuminate\Support\Facades\{Cache,DB,Event,Http,Queue};
use Illuminate\Support\Str;
use Tests\TestCase;

final class CatalogChartRepairTest extends TestCase
{
    private array $before;
    private Market $market;
    private int $actorId;
    private string $sourceKey;
    private string $hash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('deepro_test',DB::connection()->getDatabaseName());
        require_once getenv('DEEPRO_CHART_REPAIR_OPERATOR') ?: dirname(base_path(),2).'/ops/deploy/ui-refinement/repair-catalog-charts.php';
        config(['cache.default'=>'array','broadcasting.default'=>'log']);
        DB::beginTransaction(); Event::fake(); Queue::fake(); Http::preventStrayRequests();
        $this->actorId=(int)DB::table('users')->orderBy('id')->value('id');
        $btc=Market::whereName('BTC-USDT')->firstOrFail();
        $this->before=['operation'=>'ui-catalog-20260930','manifestSha256'=>\DeeproCatalogChartRepair::MANIFEST,
            'database'=>DB::connection()->getDatabaseName(),'actorId'=>$this->actorId,
            'requestKeys'=>['binance'=>$this->sourceKey=(string)Str::uuid()],
            'snapshot'=>['markets'=>[['id'=>$btc->id,'name'=>$btc->name]]]];
        $currency=Currency::withoutEvents(fn()=>Currency::forceCreate(['symbol'=>'QACANDLE','name'=>'QA Candle','type'=>'coin','decimals'=>8,'status'=>true,'asset_category'=>'crypto']));
        $this->market=Market::withoutEvents(fn()=>Market::forceCreate(['name'=>'QACANDLE-USDT','base_currency_id'=>$currency->id,'quote_currency_id'=>$btc->quote_currency_id,
            'status'=>true,'trade_status'=>true,'buy_order_status'=>true,'sell_order_status'=>true,'switch_chart'=>false,'chart_source'=>'binance',
            'chart_symbol'=>null,'chart_default_resolution'=>'60','custom_liquidity'=>false,'custom_liquidity_t'=>false,'liq'=>true,'base_precision'=>4,'quote_precision'=>4]));
        History::append('binance_chain_listing',$this->sourceKey,'configured',[
            'plan'=>['manifestSha256'=>\DeeproCatalogChartRepair::MANIFEST],
            'applied'=>[['symbol'=>'BTC','marketId'=>$btc->id,'market'=>$btc->name,'currencyId'=>$btc->base_currency_id],
                ['symbol'=>'QACANDLE','marketId'=>$this->market->id,'market'=>$this->market->name,'currencyId'=>$currency->id]],
        ],$this->actorId,'Approved 20260930 chain asset catalog listing',$this->sourceKey);
        $this->hash=hash('sha256',json_encode($this->before));
    }
    protected function tearDown(): void {while(DB::transactionLevel()>0)DB::rollBack();parent::tearDown();}
    private function runRepair(string $mode): array {return (new \DeeproCatalogChartRepair)->run($mode,$this->before,$this->hash,$this->actorId);}

    public function test_only_audited_new_market_gets_external_candles_with_idempotent_audit_and_no_financial_mutation(): void
    {
        $old=Market::whereName('BTC-USDT')->firstOrFail();$old->forceFill(['chart_source'=>'mexc','chart_symbol'=>'CUSTOMUSDT','switch_chart'=>false])->save();
        $oldBefore=$old->getAttributes();$financial=[];
        foreach(['wallets','orders','transactions','deposits','withdrawals','market_execution_policies'] as $table) $financial[$table]=DB::table($table)->get()->toJson();
        $runtime=['last'=>'90.43','market_id'=>$this->market->id,'persist_pending'=>true,'persist_after'=>time()+30,'updated_at'=>now()->toDateTimeString()];
        Cache::put('market_kline_runtime_config_'.$this->market->id,$runtime);
        Cache::put('unrelated-cache','keep');Cache::put('chart:symbol_info:binance:QACANDLEUSDT',['old'=>true]);
        $this->assertFalse($this->runRepair('verify')['verified']);
        $this->assertTrue($this->runRepair('dry-run')['readyToApply']);$this->assertFalse($this->market->fresh()->switch_chart);
        $this->assertSame(1,$this->runRepair('apply')['targetCount']);
        $this->assertTrue($this->runRepair('apply')['verified']);$this->assertTrue($this->runRepair('verify')['verified']);
        $this->assertTrue($this->market->fresh()->switch_chart);$this->assertSame('QACANDLEUSDT',$this->market->fresh()->chart_symbol);
        $this->assertSame($oldBefore,$old->fresh()->getAttributes());
        $this->assertSame(1,DB::table('operations_events')->where('request_key',\DeeproCatalogChartRepair::REQUEST_ID)->count());
        $this->assertSame($runtime,Cache::get('market_kline_runtime_config_'.$this->market->id));
        $this->assertSame('keep',Cache::get('unrelated-cache'));$this->assertNull(Cache::get('chart:symbol_info:binance:QACANDLEUSDT'));
        foreach($financial as $table=>$rows)$this->assertSame($rows,DB::table($table)->get()->toJson(),$table);
        $start=1700000000;Http::fake(['*api/v3/klines*'=>Http::response([[$start*1000,'2','3','1','2.5','10']])]);
        $this->getJson('/tradingview-chart/history?symbol=QACANDLE-USDT&resolution=60&from='.$start.'&to='.($start+7200))->assertOk()->assertJsonPath('s','ok')->assertJsonCount(1,'t');
        Http::assertSent(fn($r)=>str_contains($r->url(),'symbol=QACANDLEUSDT'));
    }

    public function test_custom_runtime_override_is_rejected_without_replacing_it(): void
    {
        Cache::put('market_kline_runtime_config_'.$this->market->id,['last'=>'88','custom_liquidity_t'=>true]);
        try {$this->runRepair('apply');$this->fail('Custom override accepted');}
        catch(\RuntimeException $e){$this->assertSame('CHART_REPAIR_CUSTOM_CHART_REQUIRES_REVIEW',$e->getMessage());}
        $this->assertSame(['last'=>'88','custom_liquidity_t'=>true],Cache::get('market_kline_runtime_config_'.$this->market->id));
        $this->assertFalse($this->market->fresh()->switch_chart);
        $this->assertFalse(DB::table('operations_events')->where('request_key',\DeeproCatalogChartRepair::REQUEST_ID)->exists());
    }

    public function test_reused_preexisting_market_name_and_unmatched_source_audit_are_rejected(): void
    {
        $this->before['snapshot']['markets'][]=['id'=>999999999,'name'=>$this->market->name];
        try {$this->runRepair('apply');$this->fail('Existing name accepted');}
        catch(\RuntimeException $e){$this->assertSame('CHART_REPAIR_PREEXISTING_MARKET_NAME',$e->getMessage());}
        array_pop($this->before['snapshot']['markets']);
        DB::table('operations_events')->where('request_key',$this->sourceKey)->update(['reason'=>'Different catalog']);
        try {$this->runRepair('apply');$this->fail('Wrong audit accepted');}
        catch(\RuntimeException $e){$this->assertSame('CHART_REPAIR_SOURCE_AUDIT_MISMATCH',$e->getMessage());}
        $this->assertFalse($this->market->fresh()->switch_chart);
    }

    public function test_rollback_restores_only_chart_fields_and_preserves_later_price_and_trade_switch(): void
    {
        $this->runRepair('apply');$this->market->forceFill(['last'=>'9.99','trade_status'=>false])->save();
        $this->assertTrue($this->runRepair('rollback')['verified']);$this->assertTrue($this->runRepair('rollback')['verified']);
        $market=$this->market->fresh();$this->assertFalse($market->switch_chart);$this->assertNull($market->chart_symbol);
        $this->assertEquals(9.99,(float)$market->last);$this->assertFalse($market->trade_status);$this->assertFalse($this->runRepair('verify')['verified']);
    }

    public function test_replay_and_rollback_refuse_a_later_operator_chart_change(): void
    {
        $this->runRepair('apply');$this->market->forceFill(['chart_symbol'=>'CUSTOMUSDT'])->save();
        foreach(['apply','verify','rollback'] as $mode) {
            try {$this->runRepair($mode);$this->fail('Changed chart accepted');}
            catch(\RuntimeException $e){$this->assertSame('CHART_REPAIR_CURRENT_CHART_CHANGED',$e->getMessage());}
        }
        $this->assertSame('CUSTOMUSDT',$this->market->fresh()->chart_symbol);
    }

    public function test_hong_kong_chart_description_uses_each_registered_security(): void
    {
        foreach(['HK00700'=>'00700.HK','HK09988'=>'09988.HK','HK08379'=>'08379.HK'] as $symbol=>$ticker) {
            app(HongKongProductListing::class)->apply($symbol);
            $this->getJson('/tradingview-chart/symbols?symbol='.$symbol.'USDT')->assertOk()->assertJsonPath('description',$ticker.' · USDT')->assertJsonPath('currency_code','USDT')->assertJsonPath('ticker',$symbol.'-USDT');
        }
        Http::assertNothingSent();
    }
}
