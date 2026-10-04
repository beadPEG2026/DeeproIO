<?php
namespace Tests\Feature\Deepro;

use App\Models\Currency\Currency;
use App\Services\Market\{AssetProfile, StockAssets, StockCatalog};
use App\Services\Deposit\DepositChannelPolicy;
use App\Services\Custody\{AssetAutomation, CustodyNetwork};
use App\Services\Wallet\WithdrawalNetworkPolicy;
use App\Repositories\Currency\CurrencyRepository;
use Illuminate\Support\Facades\{DB, Http};
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class UnifiedAssetsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('deepro_test', DB::connection()->getDatabaseName());
        DB::beginTransaction();
        Http::preventStrayRequests();
    }
    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) DB::rollBack();
        parent::tearDown();
    }
    private function asset(): Currency { return Currency::where('symbol','AAPLon')->firstOrFail(); }
    private function payload(Currency $c): array { return $c->toArray() + ['networks'=>[NETWORK_BEP]]; }

    public function test_backfill_retains_each_original_currency_identity_and_provider_mapping(): void
    {
        foreach (DB::table('stock_token_catalog')->get() as $row) {
            $old=json_decode($row->asset,true);$c=Currency::where('symbol',$row->symbol)->sole();
            $a=StockAssets::find($row->symbol);
            $this->assertSame($old['contract'],$a['contract']);
            $this->assertSame($old['tokenId'],$a['tokenId']);
            $this->assertSame($old['decimals'],$a['decimals']);
            $this->assertSame($old['issuer'],$c->asset_issuer);
            $this->assertSame($c->id,(int)DB::table('markets')->where('name',$c->symbol.'-USDT')->value('base_currency_id'));
            $this->assertArrayNotHasKey('contract',$c->asset_reference);
        }
    }
    public function test_both_admin_entries_update_one_record_without_changing_financial_rows(): void
    {
        $this->withoutMiddleware();
        // Bind the real admin request/controller explicitly in the isolated test router.
        \Illuminate\Support\Facades\Route::put('/_qa/currencies/{id}', function (\App\Http\Requests\Web\Currency\CurrencyFormRequest $request, $id) {
            return app(\App\Http\Controllers\Web\Admin\CurrencyController::class)->update($request, \App\Models\Currency\CurrencyAdmin::findOrFail($id));
        });
        if (!\Illuminate\Support\Facades\Route::has('admin.stock-tokens.update')) \Illuminate\Support\Facades\Route::group([],base_path('routes/admin.php'));
        \Illuminate\Support\Facades\Route::getRoutes()->refreshNameLookups();
        $c=$this->asset();$reference=$c->asset_reference;
        $before=[];
        foreach (['wallets','orders','transactions','deposits','withdrawals'] as $table) {
            $before[$table]=hash('sha256',DB::table($table)->orderBy('id')->get()->toJson());
        }
        $payload=$this->payload($c);
        $payload['name']=str_repeat('A',60); // Same name limit as the quick listing entry.
        $payload['asset_issuer']='QA issuer';$payload['asset_unit']='token';
        $payload['asset_display_enabled']=false;$payload['asset_chart_interval']='4h';
        // A submitted provider object is never mass assignable.
        $payload['asset_reference']=['tokenId'=>'FORGED'];
        $this->put('/_qa/currencies/'.$c->id,$payload)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('QA issuer',StockAssets::find('AAPLon')['issuer']);
        $this->assertSame($reference,$c->fresh()->asset_reference);
        $this->assertCount(9,app(StockCatalog::class)->assets());
        $this->put('/exchange-control-panel/stock-tokens/AAPLon',['display_enabled'=>true,'default_interval'=>'5m'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertTrue($c->fresh()->asset_display_enabled);
        $this->assertSame('5m',$c->fresh()->asset_chart_interval);
        foreach ($before as $table=>$hash) $this->assertSame($hash,hash('sha256',DB::table($table)->orderBy('id')->get()->toJson()),$table);
    }
    public function test_old_catalog_and_preferences_cannot_override_common_asset_settings(): void
    {
        DB::table('stock_display_settings')->updateOrInsert(['symbol'=>'AAPLon'],['display_enabled'=>false,'default_interval'=>'4h']);
        DB::table('stock_token_catalog')->where('symbol','AAPLon')->update(['asset'=>'{"symbol":"FORGED"}']);
        $this->assertSame('AAPLon',StockAssets::find('AAPLon')['symbol']);
        $this->assertTrue(collect(app(StockCatalog::class)->assets())->firstWhere('symbol','AAPLon')['displayEnabled']);
    }
    public function test_invalid_network_or_identity_changes_are_atomic(): void
    {
        foreach ([['networks'=>[NETWORK_BEP,NETWORK_ERC]],['symbol'=>'AAPLON'],['decimals'=>6],['bep_contract'=>'0x'.str_repeat('1',40)],['asset_category'=>'crypto']] as $change) {
            $c=$this->asset();$before=$c->toArray();
            try {
                app(CurrencyRepository::class)->update($c->id,array_replace($this->payload($c),['name'=>'Should not save'],$change));
                $this->fail('Accepted conflicting asset edit');
            } catch (ValidationException $e) { $this->assertNotEmpty($e->errors()); }
            $this->assertSame($before,$c->fresh()->toArray());
        }
    }
    public function test_legacy_wrong_network_cannot_create_address_withdraw_or_sweep(): void
    {
        $c=$this->asset();
        DB::table('currencies')->where('id',$c->id)->update(['contract'=>$c->bep_contract]);
        DB::table('currency_networks')->updateOrInsert(['currency_id'=>$c->id,'network_id'=>NETWORK_ERC],[]);
        DB::table('networks')->where('id',NETWORK_ERC)->update(['status'=>true,'deposit_status'=>true,'withdraw_status'=>true]);
        $this->assertSame('This asset supports BSC (BEP20) only',app(DepositChannelPolicy::class)->error($c->id,NETWORK_ERC,false));
        $this->assertSame('This asset supports BSC (BEP20) only',app(WithdrawalNetworkPolicy::class)->error($c->id,NETWORK_ERC));
        $this->assertNull(AssetProfile::networkError($c,'bep20'));
        $this->assertSame('bnb',CustodyNetwork::asset($c->id,NETWORK_BEP)['chain']);
        try { CustodyNetwork::asset($c->id,NETWORK_ERC);$this->fail('Wrong chain accepted'); }
        catch (ValidationException $e) { $this->assertStringContainsString('CUSTODY_ASSET_NETWORK_RESTRICTED',$e->getMessage()); }
    }
    public function test_asset_draft_reuses_one_bsc_channel_and_never_enables_it(): void
    {
        $c=$this->asset();
        DB::table('deposit_channels')->where('currency_id',$c->id)->delete();
        DB::table('currency_networks')->updateOrInsert(['currency_id'=>$c->id,'network_id'=>NETWORK_ERC],[]);
        $this->assertSame(1,app(AssetAutomation::class)->draft($c));
        $this->assertSame(0,app(AssetAutomation::class)->draft($c));
        $row=DB::table('deposit_channels')->where('currency_id',$c->id)->sole();
        $this->assertSame(NETWORK_BEP,(int)$row->network_id);$this->assertSame('draft',$row->state);
        $this->assertSame($c->bep_contract,$row->contract);
    }
    public function test_ordinary_multi_network_tokens_and_existing_pause_switches_are_preserved(): void
    {
        $usdt=Currency::where('symbol','USDT')->sole();
        $this->assertNull(AssetProfile::networkError($usdt,'erc20'));
        $this->assertNull(AssetProfile::networkError($usdt,'trc20'));
        $c=$this->asset();$c->update(['deposit_status'=>false,'withdraw_status'=>false]);
        $this->assertSame('Deposits are unavailable',app(DepositChannelPolicy::class)->error($c->id,NETWORK_BEP,false));
        $this->assertSame('Withdrawals are not allowed for this currency',app(WithdrawalNetworkPolicy::class)->error($c->id,NETWORK_BEP));
    }
}
