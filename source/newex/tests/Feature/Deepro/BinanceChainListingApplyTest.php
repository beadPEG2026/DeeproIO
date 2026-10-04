<?php
namespace Tests\Feature\Deepro;

use App\Models\{Currency\Currency,Market\Market};
use App\Services\Market\{BinanceChainListing,HongKongProductListing};
use Illuminate\Support\Facades\{Artisan,DB,Event,Queue,Http};
use Tests\TestCase;

final class BinanceChainListingApplyTest extends TestCase
{
    protected function setUp():void {parent::setUp();$this->assertSame('deepro_test',DB::connection()->getDatabaseName());DB::beginTransaction();Event::fake();Queue::fake();Http::preventStrayRequests();}
    protected function tearDown():void {while(DB::transactionLevel()>0)DB::rollBack();parent::tearDown();}
    private function plan():array {
        return ['chains'=>['ETH'=>[['symbol'=>'QATESTCHAIN','name'=>'QA Asset','native'=>false,'referencePrice'=>'2','contractField'=>'contract','contract'=>'0x'.str_repeat('7',40),
            'chain'=>'ETH','networkId'=>(int)DB::table('networks')->whereSlug('erc20')->value('id'),'market'=>'QATESTCHAIN-USDT','quantityStep'=>'0.00100000','priceTick'=>'0.01000000']]]];
    }
    public function test_reapply_keeps_operator_settings_zero_balances_and_transfers_disabled():void {
        $counts=[];foreach(['orders','transactions','deposits','withdrawals'] as $t)$counts[$t]=DB::table($t)->count();
        $service=app(BinanceChainListing::class);$plan=$this->plan();$service->apply($plan);
        $currency=Currency::whereSymbol('QATESTCHAIN')->sole();$market=Market::whereName('QATESTCHAIN-USDT')->sole();
        $this->assertFalse($currency->deposit_status);$this->assertFalse($currency->withdraw_status);$this->assertTrue($market->trade_status);
        $this->assertTrue($market->switch_chart);$this->assertSame('binance',$market->chart_source);
        $this->assertContains($plan['chains']['ETH'][0]['networkId'],$currency->disabled_withdrawal_networks);
        $this->assertEquals(0,DB::table('wallets')->where('currency_id',$currency->id)->sum('balance_in_wallet'));
        $market->trade_status=false;$market->switch_chart=false;$market->chart_source='custom';$market->chart_symbol='QA_OVERRIDE';$market->save();
        $service->apply($plan);$market->refresh();$this->assertFalse($market->trade_status);
        $this->assertFalse($market->switch_chart);$this->assertSame('custom',$market->chart_source);$this->assertSame('QA_OVERRIDE',$market->chart_symbol);
        $this->assertSame(1,DB::table('currency_networks')->where('currency_id',$currency->id)->count());
        foreach($counts as $t=>$count)$this->assertSame($count,DB::table($t)->count());
    }
    public function test_contract_conflict_rolls_back_instead_of_remapping_existing_asset():void {
        $service=app(BinanceChainListing::class);$plan=$this->plan();$service->apply($plan);$plan['chains']['ETH'][0]['contract']='0x'.str_repeat('8',40);
        try{$service->apply($plan);$this->fail('Contract remapping accepted');}catch(\RuntimeException $e){$this->assertStringStartsWith('EXISTING_CONTRACT_CONFLICT',$e->getMessage());}
        $this->assertSame('0x'.str_repeat('7',40),Currency::whereSymbol('QATESTCHAIN')->sole()->contract);
    }
    public function test_requested_hong_kong_catalog_has_thirteen_products_and_same_listing_path():void {
        $this->assertCount(13,config('hk-price-products.assets'));$market=app(HongKongProductListing::class)->apply('HK00700');
        $this->assertSame('HK00700-USDT',$market->name);$this->assertFalse($market->trade_status);
        $currency=Currency::whereSymbol('HK00700')->sole();$this->assertFalse($currency->deposit_status);$this->assertFalse($currency->withdraw_status);
        $this->assertSame('00700',$currency->asset_reference['securityCode']);$this->assertSame('HK',$currency->asset_reference['region']);
    }

    public function test_manifest_provenance_is_saved_without_changing_existing_transfer_settings(): void
    {
        $plan=$this->plan();$plan['manifestSha256']=str_repeat('a',64);$plan['identityObservedAt']=now()->toIso8601String();
        $plan['chains']['ETH'][0]['chainDecimals']=6;
        $plan['chains']['ETH'][0]['identityEvidence']=['identity'=>'QA Asset on Ethereum','sourceIds'=>['issuer','registry']];
        app(BinanceChainListing::class)->apply($plan);
        $currency=Currency::whereSymbol('QATESTCHAIN')->sole();
        $identity=$currency->asset_reference['binanceChainListing']['networks']['ETH'];
        $this->assertSame(6,$identity['decimals']);$this->assertSame(str_repeat('a',64),$identity['manifestSha256']);
        $this->assertFalse($currency->deposit_status);$this->assertFalse($currency->withdraw_status);
    }

    public function test_adding_contract_to_existing_network_still_keeps_that_transfer_route_disabled(): void
    {
        $plan=$this->plan();$currency=Currency::withoutEvents(fn()=>Currency::forceCreate([
            'name'=>'QA Asset','symbol'=>'QATESTCHAIN','type'=>'coin','is_token'=>true,'decimals'=>8,
            'status'=>true,'deposit_status'=>true,'withdraw_status'=>true,'asset_category'=>'crypto',
        ]));
        $networkId=$plan['chains']['ETH'][0]['networkId'];
        DB::table('currency_networks')->insert(['currency_id'=>$currency->id,'network_id'=>$networkId]);
        app(BinanceChainListing::class)->apply($plan);$currency->refresh();
        $this->assertTrue($currency->deposit_status);$this->assertTrue($currency->withdraw_status);
        $this->assertContains($networkId,$currency->disabled_deposit_networks);
        $this->assertContains($networkId,$currency->disabled_withdrawal_networks);
    }

    public function test_renamed_ton_asset_cannot_create_a_second_gram_ledger_even_when_old_coin_is_deleted(): void
    {
        $currency=Currency::withTrashed()->whereSymbol('TON')->first() ?? Currency::withoutEvents(fn()=>Currency::forceCreate([
            'name'=>'Legacy Toncoin','symbol'=>'TON','type'=>'coin','is_token'=>false,'decimals'=>8,
            'status'=>false,'deposit_status'=>false,'withdraw_status'=>false,'asset_category'=>'crypto',
        ]));
        $currency->delete();$gramCount=Currency::withTrashed()->whereSymbol('GRAM')->count();$plan=$this->plan();$row=$plan['chains']['ETH'][0];
        $row=array_merge($row,['symbol'=>'GRAM','name'=>'Gram','native'=>true,'chain'=>'TON','contractField'=>null,'contract'=>null,'market'=>'GRAM-USDT']);
        try {app(BinanceChainListing::class)->apply(['chains'=>['TON'=>[$row]]]);$this->fail('Duplicate economic asset accepted');}
        catch(\RuntimeException $e){$this->assertSame('EXISTING_NATIVE_ALIAS_CONFLICT:TON:GRAM',$e->getMessage());}
        $this->assertSame($gramCount,Currency::withTrashed()->whereSymbol('GRAM')->count());
        $this->assertTrue(Currency::withTrashed()->whereKey($currency->id)->first()->trashed());
    }

    private function listingDryRun(): int
    {
        $symbol='QATESTCHAIN';$contract='0x'.str_repeat('7',40);
        DB::table('networks')->whereSlug('erc20')->update(['status'=>true]);
        Http::fake([
            '*asset-service/product/get-products'=>Http::response(['data'=>[
                ['s'=>$symbol.'USDT','b'=>$symbol,'q'=>'USDT','st'=>'TRADING','cs'=>1000,'c'=>'2','an'=>'QA Asset'],
            ]]),
            '*api/v3/exchangeInfo*'=>Http::response(['symbols'=>[
                ['symbol'=>$symbol.'USDT','baseAsset'=>$symbol,'quoteAsset'=>'USDT','status'=>'TRADING','isSpotTradingAllowed'=>true,
                    'filters'=>[['filterType'=>'PRICE_FILTER','tickSize'=>'0.01000000'],['filterType'=>'LOT_SIZE','stepSize'=>'0.00100000']]],
            ]]),
        ]);
        $path=tempnam(sys_get_temp_dir(),'listing-preflight-');
        file_put_contents($path,json_encode(['schema'=>'deepro.binance-chain-identities.v1','observedAt'=>gmdate('c'),
            'sources'=>['issuer'=>['url'=>'https://issuer.example/contracts','retrievedAt'=>gmdate('c')],
                'registry'=>['url'=>'https://registry.example/tokens','retrievedAt'=>gmdate('c')]],
            'assets'=>[['symbol'=>$symbol,'chain'=>'ETH','native'=>false,'contract'=>$contract,'decimals'=>18,
                'identity'=>'QA Asset on Ethereum','sourceIds'=>['issuer','registry']]]],JSON_THROW_ON_ERROR));
        try {return Artisan::call('deepro:binance-chain-assets',['--verified-manifest'=>$path,'--manifest-sha256'=>hash_file('sha256',$path)]);}
        finally {unlink($path);}
    }

    public function test_production_shaped_archived_duplicate_reuses_only_live_market_and_preserves_history_balances_and_policy(): void
    {
        $service=app(BinanceChainListing::class);$plan=$this->plan();$service->apply($plan);
        $active=Market::whereName('QATESTCHAIN-USDT')->sole();
        $historical=$active->replicate();$historical->trade_status=false;$historical->save();$historical->delete();
        $historyBefore=(array)DB::table('markets')->where('id',$historical->id)->first();
        $walletId=DB::table('wallets')->where('currency_id',$active->base_currency_id)->value('id');
        DB::table('wallets')->where('id',$walletId)->update(['balance_in_wallet'=>'12.345']);
        $walletsBefore=DB::table('wallets')->where('currency_id',$active->base_currency_id)->orderBy('id')->get()->toJson();
        DB::table('market_execution_policies')->insert(['market_id'=>$active->id,'mode'=>'internal','max_quote_per_fill'=>'3']);
        $policyBefore=(array)DB::table('market_execution_policies')->where('market_id',$active->id)->first();
        $active->trade_status=false;$active->save();

        $this->assertSame(0,$this->listingDryRun());
        $this->assertTrue(json_decode(Artisan::output(),true)['dryRun']);
        $applied=$service->apply($plan);
        $this->assertSame($active->id,$applied[0]['marketId']);
        $this->assertFalse($active->fresh()->trade_status);
        $this->assertSame($historyBefore,(array)DB::table('markets')->where('id',$historical->id)->first());
        $this->assertSame($walletsBefore,DB::table('wallets')->where('currency_id',$active->base_currency_id)->orderBy('id')->get()->toJson());
        $this->assertSame($policyBefore,(array)DB::table('market_execution_policies')->where('market_id',$active->id)->first());
        $this->assertSame(1,Market::whereName('QATESTCHAIN-USDT')->count());
        $this->assertSame(2,Market::withTrashed()->whereName('QATESTCHAIN-USDT')->count());
        $this->assertSame($active->id,$service->resolveExistingMarket('QATESTCHAIN',(int)$active->base_currency_id,(int)$active->quote_currency_id)->id);
    }

    /** @dataProvider conflictingMarketShapes */
    public function test_dry_run_and_apply_reject_ambiguous_archived_only_or_mismatched_markets(string $shape): void
    {
        $service=app(BinanceChainListing::class);$plan=$this->plan();$service->apply($plan);
        $active=Market::whereName('QATESTCHAIN-USDT')->sole();
        if($shape==='multiple_active')$active->replicate()->save();
        elseif($shape==='archived_only')$active->delete();
        else {$active->base_currency_id=$active->quote_currency_id;$active->save();}
        $marketsBefore=DB::table('markets')->whereName('QATESTCHAIN-USDT')->orderBy('id')->get()->toJson();
        $walletsBefore=DB::table('wallets')->orderBy('id')->get()->toJson();
        $this->assertSame(1,$this->listingDryRun());
        $this->assertStringContainsString('EXISTING_MARKET_IDENTITY_CONFLICT:QATESTCHAIN',Artisan::output());
        try {$service->apply($plan);$this->fail('Conflicting market accepted');}
        catch(\RuntimeException $e){$this->assertSame('EXISTING_MARKET_IDENTITY_CONFLICT:QATESTCHAIN',$e->getMessage());}
        $this->assertSame($marketsBefore,DB::table('markets')->whereName('QATESTCHAIN-USDT')->orderBy('id')->get()->toJson());
        $this->assertSame($walletsBefore,DB::table('wallets')->orderBy('id')->get()->toJson());
    }

    public static function conflictingMarketShapes(): array
    {
        return [['multiple_active'],['archived_only'],['wrong_currency_id']];
    }
}
