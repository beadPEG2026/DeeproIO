<?php
namespace Tests\Feature\Deepro;
use Tests\TestCase;
use App\Services\Custody\AssetAutomation;
use App\Models\Currency\Currency;
use App\Models\Deposit\DepositChannel;
use Illuminate\Support\Facades\{DB,Event,Http};
final class AssetAutomationTest extends TestCase {
 protected function setUp():void{parent::setUp();$this->assertSame('deepro_test',DB::connection()->getDatabaseName());DB::beginTransaction();Event::fake();Http::preventStrayRequests();}
 protected function tearDown():void{while(DB::transactionLevel()>0)DB::rollBack();parent::tearDown();}
 public function test_existing_active_channels_and_limits_are_never_replaced_by_a_template():void{
  $c=Currency::where('symbol','USDT')->firstOrFail();$service=app(AssetAutomation::class);$service->draft($c);
  $d=DepositChannel::where('currency_id',$c->id)->where('network_id',6)->firstOrFail();$d->update(['state'=>'active','minimum'=>'42','start_block'=>123456]);
  $before=$d->fresh()->toArray();$service->draft($c);$this->assertSame($before,$d->fresh()->toArray());
 }
 public function test_missing_channel_is_audit_logged_draft_and_does_not_enable_collection():void{
  $c=Currency::where('symbol','BNB')->firstOrFail();$n=DB::table('networks')->where('slug','bep20')->first();$c->networks()->syncWithoutDetaching([$n->id]);
  $before=DB::table('custody_networks')->orderBy('chain')->get()->toJson();$service=app(AssetAutomation::class);$service->draft($c);
  $d=DepositChannel::where('currency_id',$c->id)->where('network_id',$n->id)->firstOrFail();$this->assertSame('draft',$d->state);$this->assertNull($d->config_digest);$this->assertNull($d->start_block);
  $this->assertTrue(DB::table('custody_audits')->where('action','channel.draft_created')->where('detail->channel_id',$d->id)->exists());$this->assertSame($before,DB::table('custody_networks')->orderBy('chain')->get()->toJson());
 }
 public function test_normal_currency_update_creates_missing_network_draft():void{
  $c=Currency::where('symbol','BNB')->firstOrFail();$service=new \App\Repositories\Currency\CurrencyRepository();
  $service->update($c->id,['networks'=>[5,6],'bep_contract'=>'0x'.str_repeat('2',40)]);
  $this->assertSame('draft',DepositChannel::where('currency_id',$c->id)->where('network_id',6)->value('state'));
 }
 public function test_inventory_exposes_gaps_and_never_contains_credentials():void{
  $rows=app(AssetAutomation::class)->inventory();$this->assertNotEmpty($rows);
  $btc=collect($rows)->firstWhere('symbol','BTC');$this->assertContains('Bitcoin Core wallet inventory; separate from token sweeps.',$btc['collection_issues']);
  foreach(['Collection adapter is not integrated','Hot wallet or signing key is missing','Custody bridge is not configured','Verified deposit receipt integration is missing'] as $obsolete)$this->assertNotContains($obsolete,$btc['collection_issues']);
  $sol=collect($rows)->firstWhere('symbol','SOL');$this->assertNotContains('Verified deposit receipt integration is missing',$sol['collection_issues']);
  Http::assertNothingSent();
  foreach($rows as$row){$this->assertArrayNotHasKey('private_key',$row);$this->assertArrayNotHasKey('signed_payload',$row);}
 }
}
