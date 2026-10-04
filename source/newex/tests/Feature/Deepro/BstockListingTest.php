<?php
namespace Tests\Feature\Deepro;

use App\Models\Currency\Currency;
use App\Models\Market\Market;
use App\Services\Market\{StockOnboarding,StockAssets,StockLiquidity,AssetProfile};
use App\Services\Custody\CustodyBridge;
use Illuminate\Support\Facades\{DB,Http,Event,Queue};
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class BstockListingTest extends TestCase
{
    private array $asset;
    private array $depth;
    protected function setUp(): void {
        parent::setUp();
        $this->assertSame('deepro_test',DB::connection()->getDatabaseName());
        DB::beginTransaction();Event::fake();Queue::fake();Http::preventStrayRequests();
        $this->asset=json_decode(file_get_contents(resource_path('data/bstocks.json')),true)['assets'][0];
        $this->depth=['source'=>'binance-spot','source_symbol'=>'DJTBUSDT','symbol'=>'DJTB','chain_id'=>56,
            'contract'=>$this->asset['contract'],'quote_currency'=>'USDT','quantity_unit'=>'token','denomination'=>1,'mul_point'=>1,
            'event_time_kind'=>'snapshot_received','received_at'=>gmdate('c'),'event_time'=>time()*1000,'last_update_id'=>123,
            'bids'=>[['9.00','2.00']],'asks'=>[['9.10','3.00']], 'filters'=>[['filterType'=>'PRICE_FILTER','tickSize'=>'0.01000000']]];
        Http::fake(['*/v1/stocks/inspect'=>Http::response(['data'=>['asset'=>$this->asset,'depth'=>$this->depth,'checks'=>['identity'=>true,'depth'=>true]]])]);
    }
    protected function tearDown():void {while(DB::transactionLevel()>0)DB::rollBack();parent::tearDown();}
    private function bridge(int $decimals=18):void {
        $this->mock(CustodyBridge::class)->shouldReceive('call')->withArgs(fn($chain,$action,$payload)=>
            $chain==='bnb' && $action==='balance' && $payload['contract']===$this->asset['contract'])
            ->andReturn(['success'=>true,'decimals'=>$decimals,'balance'=>'0','native_balance'=>'0']);
    }
    private function listAsset():Market {$this->bridge();app(StockOnboarding::class)->apply($this->asset['contract']);return Market::whereName('DJTB-USDT')->sole();}
    public function test_listing_reuses_shared_currency_market_wallets_and_one_draft_bsc_channel():void {
        $financial=[];foreach(['orders','transactions','deposits','withdrawals'] as $t)$financial[$t]=DB::table($t)->count();
        $m=$this->listAsset();app(StockOnboarding::class)->apply($this->asset['contract']);
        $c=Currency::where('symbol','DJTB')->sole();$a=StockAssets::find('DJTB');
        $this->assertSame('BTech Holdings Limited',$a['issuer']);$this->assertSame('binance-spot',$a['marketSource']);
        $this->assertArrayNotHasKey('tokenId',$a);$this->assertSame(18,$a['decimals']);
        $this->assertSame($c->id,$m->base_currency_id);
        $this->assertEquals(0,DB::table('wallets')->where('currency_id',$c->id)->sum('balance_in_wallet'));
        $this->assertEquals(0,DB::table('wallets')->where('currency_id',$c->id)->sum('balance_in_trade'));
        $channel=DB::table('deposit_channels')->where('currency_id',$c->id)->sole();
        $this->assertSame(NETWORK_BEP,$channel->network_id);$this->assertSame('draft',$channel->state);
        $this->assertSame($a['contract'],$channel->contract);
        foreach($financial as $t=>$count)$this->assertSame($count,DB::table($t)->count());
        AssetProfile::validateEdit($c,['networks'=>[NETWORK_BEP],'name'=>'New display name']);
        $this->assertSame('This asset supports BSC (BEP20) only',AssetProfile::networkError($c,'erc20'));
    }
    public function test_wrong_chain_precision_stops_listing_before_any_database_write():void {
        $this->bridge(6);$before=DB::table('currencies')->count();
        try {app(StockOnboarding::class)->apply($this->asset['contract']);$this->fail('Wrong precision accepted');}
        catch(\RuntimeException $e){$this->assertSame('stock_chain_identity_mismatch',$e->getMessage());}
        $this->assertSame($before,DB::table('currencies')->count());
    }
    public function test_verified_identity_stays_locked_and_wrong_market_source_is_rejected():void {
        $m=$this->listAsset();$c=Currency::where('symbol','DJTB')->sole();
        foreach([['symbol'=>'DJT'],['decimals'=>6],['bep_contract'=>'0x'.str_repeat('1',40)],['asset_category'=>'crypto']] as $change) {
            try {AssetProfile::validateEdit($c,$change+['networks'=>[NETWORK_BEP]]);$this->fail('Identity edit allowed');}
            catch(ValidationException $e){$this->assertNotEmpty($e->errors());}
        }
        foreach([['source'=>'binance-alpha'],['source_symbol'=>'DJTUSDT'],['event_time_kind'=>'book_event']] as $change) {
            try {app(StockLiquidity::class)->store($m,array_replace($this->depth,$change));$this->fail('Wrong source accepted');}
            catch(\RuntimeException $e){$this->assertSame('stock_depth_identity_or_units_invalid',$e->getMessage());}
        }
    }
    public function test_public_trade_feed_preserves_spot_source_and_never_creates_local_trades():void {
        $m=$this->listAsset();
        Http::fake(['*/v1/stocks/trades'=>Http::response(['source'=>'binance-spot','source_symbol'=>'DJTBUSDT',
            'received_at'=>now()->toIso8601String(),'data'=>[['id'=>'42','price'=>'9.06','quantity'=>'1.8','timestamp'=>1700000000000]]])]);
        $this->getJson('/api/v1/markets/historical/trades?market=DJTB-USDT')->assertOk()
            ->assertJsonPath('trades.0.id','binance-spot:42')->assertJsonPath('trades.0.timestamp',1700000000000);
        $this->assertSame(0,DB::table('transactions')->where('market_id',$m->id)->count());
    }
}
