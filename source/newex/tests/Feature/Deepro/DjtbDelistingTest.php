<?php
namespace Tests\Feature\Deepro;
use Tests\TestCase;
use Illuminate\Support\Facades\{DB,Schema};
use Illuminate\Database\Schema\Blueprint;
use App\Services\Market\MarketService;

final class DjtbDelistingTest extends TestCase
{
    private string $tag='20261005-full-implementation-r991';
    protected function setUp():void
    {
        parent::setUp();config(['database.default'=>'delist_memory','database.connections.delist_memory'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>'']]);DB::purge('delist_memory');
        Schema::create('currencies',function(Blueprint $t){$t->id();$t->string('symbol');$t->string('bep_contract');foreach(['status','asset_display_enabled','deposit_status','withdraw_status'] as $s)$t->boolean($s)->default(true);$t->timestamps();});
        Schema::create('markets',function(Blueprint $t){$t->id();$t->string('name');$t->integer('base_currency_id');foreach(['status','trade_status','liq'] as $s)$t->boolean($s)->default(true);$t->timestamps();});
        Schema::create('orders',fn(Blueprint $t)=>$t->integer('market_id'));
        Schema::create('wallets',function(Blueprint $t){$t->id();$t->integer('currency_id');foreach(['wallet','trade','order','withdraw'] as $s)$t->integer('balance_in_'.$s)->default(0);});
        DB::table('currencies')->insert(['id'=>1,'symbol'=>'DJTB','bep_contract'=>'0xf2ec508422174ee564de98187db9359d318afb6b']);DB::table('markets')->insert(['id'=>1,'name'=>'DJTB-USDT','base_currency_id'=>1]);DB::table('wallets')->insert(['currency_id'=>1,'balance_in_wallet'=>7]);
        $this->mock(MarketService::class,fn($m)=>$m->shouldReceive('updateMarketsInfoCache')->zeroOrMoreTimes());
    }
    protected function tearDown():void
    {
        $p=storage_path('app/releases/'.$this->tag.'/djtb-before-delist.json');if(is_file($p))unlink($p);if(is_dir(dirname($p)))rmdir(dirname($p));DB::disconnect('delist_memory');parent::tearDown();
    }
    public function test_delist_is_replay_safe_and_retains_assets_withdrawal_setting_and_backup():void
    {
        $this->artisan('market:delist-djtb')->assertExitCode(0);self::assertTrue((bool)DB::table('markets')->value('status'));
        foreach([1,2] as $_)$this->artisan('market:delist-djtb',['--apply'=>true,'--release'=>$this->tag])->assertExitCode(0);
        $c=DB::table('currencies')->first();self::assertFalse((bool)$c->asset_display_enabled);self::assertFalse((bool)$c->deposit_status);self::assertTrue((bool)$c->withdraw_status);self::assertTrue((bool)$c->status);self::assertSame(7,DB::table('wallets')->value('balance_in_wallet'));
        self::assertFalse((bool)DB::table('markets')->value('trade_status'));$b=json_decode(file_get_contents(storage_path('app/releases/'.$this->tag.'/djtb-before-delist.json')),true);self::assertTrue((bool)$b['market']['status']);
    }
    public function test_open_orders_and_wrong_asset_identity_fail_without_config_mutation():void
    {
        DB::table('orders')->insert(['market_id'=>1]);$this->artisan('market:delist-djtb',['--apply'=>true,'--release'=>$this->tag])->assertExitCode(1);self::assertTrue((bool)DB::table('markets')->value('status'));
        DB::table('orders')->delete();DB::table('currencies')->update(['bep_contract'=>'wrong']);$this->artisan('market:delist-djtb',['--apply'=>true,'--release'=>$this->tag])->assertExitCode(1);self::assertTrue((bool)DB::table('markets')->value('status'));
    }
}
