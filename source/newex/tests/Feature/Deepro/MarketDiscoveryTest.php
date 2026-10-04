<?php
namespace Tests\Feature\Deepro;
use Tests\TestCase;
use App\Services\Market\{GlobalMarketOverview,MarketPresentation};
use Illuminate\Support\Facades\{Cache,Http,DB};
use Illuminate\Foundation\Testing\DatabaseTransactions;
class MarketDiscoveryTest extends TestCase {
 use DatabaseTransactions;
 protected function setUp():void {parent::setUp();$this->assertSame('deepro_test',DB::connection()->getDatabaseName());Cache::flush();config(['app.readonly'=>false]);if(!\Illuminate\Support\Facades\Route::has('admin.market-presentation'))\Illuminate\Support\Facades\Route::middleware('web')->group(base_path('routes/admin.php'));\Illuminate\Support\Facades\Route::getRoutes()->refreshNameLookups();\Setting::set('general.maintenance_status',false);}
 private function body(string $key,$value=50):array {
  $data=match($key){'global'=>['last_updated'=>now()->toIso8601String(),'quote'=>['USD'=>['total_market_cap'=>$value,'total_market_cap_yesterday_percentage_change'=>0]]], 'altcoin'=>['snapshot_time'=>now()->toIso8601String(),'altcoin_index'=>$value], default=>['update_time'=>now()->toIso8601String(),'value'=>$value,'value_classification'=>'Greed']};
  return ['status'=>['error_code'=>'0'],'data'=>$data];
 }
 private function fake():void {Http::fake(['*/global-metrics/*'=>Http::response($this->body('global',2.8e12)),'*/altcoin-season-index/*'=>Http::response($this->body('altcoin')),'*/fear-and-greed/*'=>Http::response($this->body('sentiment'))]);}
 public function test_zero_index_is_valid_and_missing_never_becomes_zero():void {$s=app(GlobalMarketOverview::class);$this->assertSame(0.0,$s->normalize('altcoin',$this->body('altcoin',0))['value']);$this->expectException(\UnexpectedValueException::class);$s->normalize('altcoin',$this->body('altcoin',null));}
 public function test_positive_cap_and_provider_classification_are_preserved():void {$s=app(GlobalMarketOverview::class);$this->assertSame(2.8e12,$s->normalize('global',$this->body('global',2.8e12))['value']);$this->assertSame('Greed',$s->normalize('sentiment',$this->body('sentiment',50))['classification']);}
 public function test_out_of_range_index_rejected():void {$this->expectException(\UnexpectedValueException::class);app(GlobalMarketOverview::class)->normalize('sentiment',$this->body('sentiment',101));}
 public function test_future_source_time_rejected():void {$b=$this->body('altcoin');$b['data']['snapshot_time']=now()->addHour()->toIso8601String();$this->expectException(\UnexpectedValueException::class);app(GlobalMarketOverview::class)->normalize('altcoin',$b);}
 public function test_boolean_and_nonpositive_cap_rejected():void {$this->expectException(\UnexpectedValueException::class);app(GlobalMarketOverview::class)->normalize('global',$this->body('global',false));}
 public function test_all_visitors_share_one_cached_fetch_per_feed():void {$this->fake();$s=app(GlobalMarketOverview::class);$r=$s->snapshot();$this->assertTrue($r['data']['global']['available']);$this->assertFalse($r['data']['global']['stale']);$s->snapshot();Http::assertSentCount(3);}
 public function test_failure_keeps_original_source_time_and_backs_off():void {Http::fake(['*/global-metrics/*'=>Http::sequence()->push($this->body('global',2.8e12))->push([],429),'*/altcoin-season-index/*'=>Http::sequence()->push($this->body('altcoin'))->push([],429),'*/fear-and-greed/*'=>Http::sequence()->push($this->body('sentiment'))->push([],429)]);$s=app(GlobalMarketOverview::class);$before=$s->snapshot();foreach(['global','altcoin','sentiment'] as $k)Cache::forget('market-overview.next.'.$k);$after=$s->snapshot();$this->assertSame($before['data']['global']['sourceTime'],$after['data']['global']['sourceTime']);$this->assertTrue($after['data']['global']['stale']);$s->snapshot();$this->assertSame(1,Cache::get('market-overview.failures.global'));}
 public function test_one_bad_feed_does_not_erase_others():void {Http::fake(['*/global-metrics/*'=>Http::response([],500),'*/altcoin-season-index/*'=>Http::response($this->body('altcoin')),'*/fear-and-greed/*'=>Http::response($this->body('sentiment'))]);$r=app(GlobalMarketOverview::class)->snapshot();$this->assertFalse($r['data']['global']['available']);$this->assertTrue($r['data']['altcoin']['available']);}
 public function test_stale_source_is_not_refreshed_by_response_time():void {$b=$this->body('global',2.8e12);$b['data']['last_updated']=now()->subHour()->toIso8601String();Http::fake(['*/global-metrics/*'=>Http::response($b),'*'=>Http::response([],500)]);$this->assertTrue(app(GlobalMarketOverview::class)->snapshot()['data']['global']['stale']);}
 public function test_public_sparkline_input_is_bounded():void {$this->getJson('/markets/data/sparklines?markets[]=BTC-USDT&markets[]=ETH-USDT&markets[]=SOL-USDT')->assertUnprocessable();$this->getJson('/markets/data/sparklines?markets[]=https://example.org')->assertUnprocessable();}
 public function test_unknown_market_has_no_external_fetch():void {Http::preventStrayRequests();$this->getJson('/markets/data/sparklines?markets[]=UNKNOWN-USDT')->assertOk()->assertJson(['data'=>[]]);Http::assertNothingSent();}
 public function test_display_settings_are_audited_without_mutating_market():void {$service=app(MarketPresentation::class);$before=DB::table('markets')->orderBy('id')->get()->toJson();$data=$service->defaults();$data['global']=false;$data['sectors']=[];$data=$service->validate($data);$actor=DB::table('users')->value('id');$service->save($data,$actor,'Display test only');$this->assertFalse($service->get()['global']);$this->assertDatabaseHas('operations_events',['object_type'=>'market_display','actor_id'=>$actor]);$this->assertSame($before,DB::table('markets')->orderBy('id')->get()->toJson());}
 public function test_unknown_sector_member_is_rejected():void {$s=app(MarketPresentation::class);$data=$s->defaults();$data['sectors']=[['name'=>'Fake','symbols'=>['FAKESYMBOL123']]];$this->expectException(\Illuminate\Validation\ValidationException::class);$s->validate($data);}
 public function test_guest_cannot_change_display_configuration():void {$this->putJson('/exchange-control-panel/market-presentation',['settings'=>[]])->assertRedirect();}
 public function test_market_editor_can_save_but_regular_user_cannot():void {
  \Illuminate\Support\Facades\Event::fake();\Illuminate\Support\Facades\Mail::fake();\Illuminate\Support\Facades\Queue::fake();
  $user=\App\Models\User\User::factory()->create(['email'=>'market-qa-'.\Illuminate\Support\Str::uuid().'@example.invalid','email_verified_at'=>now(),'deleted'=>false,'deactivated'=>false,'is_xn'=>false]);
  \Auth::forgetGuards();$user->withAccessToken(new \Laravel\Sanctum\TransientToken());$this->actingAs($user,'web');
  $this->getJson('/exchange-control-panel/market-presentation')->assertForbidden();
  \Spatie\Permission\Models\Role::findOrCreate('perm_markets','web');$user->assignRole('perm_markets');
  $this->getJson('/exchange-control-panel/market-presentation')->assertOk()->assertJsonPath('settings.defaultCategory','crypto');
  $settings=app(MarketPresentation::class)->defaults();$settings['sectors']=[];$settings['benchmarks']=false;
  $this->putJson('/exchange-control-panel/market-presentation',['settings'=>$settings,'reason'=>'Test display switches only'])->assertOk()->assertJsonPath('settings.benchmarks',false);
  $this->getJson('/markets/data/catalog')->assertOk()->assertJsonPath('presentation.benchmarks',false);
 }
}
