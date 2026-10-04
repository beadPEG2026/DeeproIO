<?php
namespace Tests\Feature\Deepro;
use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\{DB,Event,Mail,Queue,Route};
use App\Services\Content\{SitePresentation,PagePublication};
use App\Services\Operations\StakingConfiguration;
use App\Models\Page\Page;
use App\Models\Staking\Staking;
use App\Models\User\User;
use Illuminate\Validation\ValidationException;
class UiOperationsTest extends TestCase {
 use DatabaseTransactions;
 protected function setUp():void {parent::setUp();$this->assertSame('deepro_test',DB::connection()->getDatabaseName());config(['app.readonly'=>false]);if(!Route::has('admin.site-presentation'))Route::middleware('web')->group(base_path('routes/admin.php'));Route::getRoutes()->refreshNameLookups();\Setting::set('general.maintenance_status',false);Event::fake();Mail::fake();Queue::fake();}
 private function operator(string $role='perm_pages'):User {$u=User::factory()->create(['email'=>'ui-qa-'.\Illuminate\Support\Str::uuid().'@example.invalid','email_verified_at'=>now(),'deleted'=>false,'deactivated'=>false,'is_xn'=>false]);\Spatie\Permission\Models\Role::findOrCreate($role,'web');$u->assignRole($role);\Auth::forgetGuards();$u->withAccessToken(new \Laravel\Sanctum\TransientToken());$this->actingAs($u,'web');return $u;}
 private function product():Staking {return Staking::create(['currency_id'=>DB::table('currencies')->value('id'),'allowed_days'=>'30,60,90','rewards_percentage'=>'1.81,3.61,5.1','min_amount'=>'1','max_amount'=>'100','staking_type'=>0,'status'=>'active'])->fresh();}
 private function pageData():array {return ['title'=>'QA public title','slug'=>'ui-qa-'.strtolower(\Illuminate\Support\Str::random(8)),'content'=>'<p>Published body</p>','is_html'=>false,'status'=>true];}
 public function test_serialized_staking_collection_exposes_explicit_periods():void {$p=$this->product();$json=(new \App\Http\Resources\Staking\StakingCollection(collect([$p])))->response()->getData(true);$this->assertSame(30,$json['data'][0]['periods'][0]['days']);$this->assertSame('22.02',$json['data'][0]['periods'][0]['apr']);$this->assertSame('1.81',$json['data'][0]['periods'][0]['period_rate']);$this->assertSame(60,$json['data'][0]['periods'][1]['days']);}
 public function test_immediate_rate_change_audited_without_position_mutation():void {$u=$this->operator('perm_stakings');$s=app(StakingConfiguration::class);$p=$this->product();$positions=DB::table('staking_users')->orderBy('id')->get()->toJson();$data=$p->only(StakingConfiguration::FIELDS);$data['rewards_percentage']='1,2,3';$s->save($p,$data,['revision'=>$s->revision($p),'reason'=>'QA rate update','apr_limit'=>30],$u->id);$this->assertSame('1,2,3',$p->fresh()->rewards_percentage);$this->assertSame($positions,DB::table('staking_users')->orderBy('id')->get()->toJson());$this->assertDatabaseHas('operations_events',['object_type'=>'staking_config','object_id'=>(string)$p->id,'actor_id'=>$u->id]);}
 public function test_rate_edit_conflict_does_not_overwrite():void {$u=$this->operator('perm_stakings');$s=app(StakingConfiguration::class);$p=$this->product();$this->expectException(ValidationException::class);$s->save($p,$p->only(StakingConfiguration::FIELDS),['revision'=>str_repeat('0',64),'reason'=>'QA conflict'],$u->id);}
 public function test_high_apr_requires_acknowledgement():void {$this->expectException(ValidationException::class);app(StakingConfiguration::class)->validateRates(['allowed_days'=>'1','rewards_percentage'=>'5'],null,false);}
 public function test_apr_limit_rejects_even_acknowledged_rate():void {$this->expectException(ValidationException::class);app(StakingConfiguration::class)->validateRates(['allowed_days'=>'30','rewards_percentage'=>'5'],10,true);}
 public function test_scheduled_rates_only_apply_when_due_and_once():void {$u=$this->operator('perm_stakings');$s=app(StakingConfiguration::class);$p=$this->product();$data=$p->only(StakingConfiguration::FIELDS);$data['rewards_percentage']='1,2,3';$s->save($p,$data,['revision'=>$s->revision($p),'reason'=>'QA scheduled rate','effective_at'=>now()->addMinutes(2)->toIso8601String()],$u->id);$this->assertSame('1.81,3.61,5.1',$p->fresh()->rewards_percentage);$this->assertSame(0,$s->applyDue());$this->travel(3)->minutes();$this->assertSame(1,$s->applyDue());$this->assertSame('1,2,3',$p->fresh()->rewards_percentage);$this->assertSame(0,$s->applyDue());$this->travelBack();}
 public function test_scheduled_rates_cancel_if_operator_permission_revoked():void {$u=$this->operator('perm_stakings');$s=app(StakingConfiguration::class);$p=$this->product();$data=$p->only(StakingConfiguration::FIELDS);$data['rewards_percentage']='1,2,3';$s->save($p,$data,['revision'=>$s->revision($p),'reason'=>'QA revoke scheduler','effective_at'=>now()->addMinute()->toIso8601String()],$u->id);$u->syncRoles([]);$this->travel(2)->minutes();$this->assertSame(0,$s->applyDue());$this->assertSame('1.81,3.61,5.1',$p->fresh()->rewards_percentage);$this->assertSame('cancelled',$s->controls($p->id)['last_result']);$this->travelBack();}
 public function test_navigation_default_hides_api_without_disabling_services():void {$s=app(SitePresentation::class);$links=array_merge(...array_column($s->navigation()['groups'],'links'));$this->assertNotContains('/docs/api',array_column($links,'path'));$this->getJson('/api/v1/servertime')->assertOk();}
 public function test_draft_navigation_is_not_public_until_published():void {$u=$this->operator();$s=app(SitePresentation::class);$data=$s->defaults('navigation');$data['api_public']=true;$s->save('navigation',$data,'draft',$s->revision('navigation'),$u->id,'QA API menu draft');$this->assertFalse($s->get('navigation')['api_public']);$s->save('navigation',$data,'publish',$s->revision('navigation'),$u->id,'QA API menu publish');$this->assertTrue($s->get('navigation')['api_public']);$this->assertNull($s->admin('navigation')['draft']);}
 public function test_navigation_suppression_preserves_explicit_disable_and_unpublished_pages():void {
  $u=$this->operator();$s=app(SitePresentation::class);$data=$s->defaults('navigation');
  $data['groups'][0]['links'][0]['visible']=false;
  $page=Page::firstOrCreate(['slug'=>'about'],$this->pageData());$page->update(['status'=>false]);
  $data['groups'][2]['links']=array_values(array_filter($data['groups'][2]['links'],fn($link)=>$link['path']!=='/about'));
  $s->save('navigation',$data,'publish',$s->revision('navigation'),$u->id,'QA explicit disabled navigation');
  $nav=$s->navigation();$paths=array_column(array_merge(...array_column($nav['groups'],'links')),'path');
  foreach(['/markets','/docs/api','/about'] as $path){$this->assertContains($path,$nav['suppressed_paths']);$this->assertNotContains($path,$paths);}
  $this->assertNotContains('/trading-rules',$nav['suppressed_paths']);
  $this->assertArrayNotHasKey('draft',$nav);
 }
 public function test_guest_scanner_preserves_the_selected_asset_and_token_through_login():void {
  $destination=route('wallets.withdraw.crypto',['symbol'=>'BTC','recipient_scan'=>str_repeat('a',32)]);
  $response=$this->get($destination);
  if($response->headers->get('Location')===route('verification.notice'))$response=$this->get(route('verification.notice'));
  $response->assertRedirect(route('login'));
  $this->assertSame($destination,session('url.intended'));
  $request=\Illuminate\Http\Request::create(route('login'),'GET');$request->setLaravelSession(app('session.store'));
  $response=app(\App\Http\Responses\LoginResponse::class)->toResponse($request);
  $this->assertSame($destination,$response->headers->get('Location'));
  $this->assertNull(session('url.intended'));
 }
 public function test_signed_in_unverified_scanner_still_requires_email_verification():void {
  $user=$this->operator();$user->forceFill(['email_verified_at'=>null])->save();$this->actingAs($user->fresh(),'web');
  $this->get(route('wallets.withdraw.crypto',['symbol'=>'BTC','recipient_scan'=>str_repeat('b',32)]))->assertRedirect(route('verification.notice'));
 }
 public function test_unsafe_navigation_target_rejected():void {$s=app(SitePresentation::class);$data=$s->defaults('navigation');$data['groups'][0]['links'][0]['path']='https://evil.example';$this->expectException(ValidationException::class);$s->validate('navigation',$data);}
 public function test_chat_host_cannot_be_changed_to_arbitrary_iframe():void {$s=app(SitePresentation::class);$d=$s->defaults('support');$d['chat_url']='https://tawk.to.evil.example/chat/a/b';$this->expectException(ValidationException::class);$s->validate('support',$d);}
 public function test_support_and_content_permissions_remain_separate():void {$this->operator('perm_pages');$this->getJson('/exchange-control-panel/site-presentation/navigation')->assertOk();$this->getJson('/exchange-control-panel/support-presentation/support')->assertForbidden();$this->getJson('/exchange-control-panel/staking?staking_type=0')->assertForbidden();$this->operator('perm_support_tickets');$this->getJson('/exchange-control-panel/support-presentation/support')->assertOk();$this->getJson('/exchange-control-panel/site-presentation/help')->assertForbidden();}
 public function test_guest_cannot_publish_configuration():void {$this->putJson('/exchange-control-panel/site-presentation/help',['settings'=>[]])->assertRedirect();}
 public function test_published_cms_body_survives_draft_save():void {$u=$this->operator();$s=app(PagePublication::class);$p=Page::create($this->pageData());$d=$p->only($p->getFillable());$d['content']='<p>Private draft</p>';$s->save($p,$d,'draft',$s->revision($p),$u->id,'QA save private draft');$this->assertSame('<p>Published body</p>',$p->fresh()->content);$this->assertSame('<p>Private draft</p>',$s->draft($p->id)['content']);$s->save($p,$d,'publish',$s->revision($p),$u->id,'QA publish draft');$this->assertSame('<p>Private draft</p>',$p->fresh()->content);}
 public function test_new_page_draft_is_hidden():void {$u=$this->operator();$s=app(PagePublication::class);$p=$s->save(null,$this->pageData(),'draft',null,$u->id,'QA new private page');$this->assertFalse($p->fresh()->status);$this->assertNotContains('/'.$p->slug,app(SitePresentation::class)->targets());}
 public function test_fixed_pages_cannot_be_renamed():void {$u=$this->operator();$p=Page::firstOrCreate(['slug'=>'about'],$this->pageData());$s=app(PagePublication::class);$d=$p->only($p->getFillable());$d['slug']='moved-about';$this->expectException(ValidationException::class);$s->save($p,$d,'publish',$s->revision($p),$u->id,'QA rename fixed page');}
 public function test_preview_sanitizes_and_does_not_persist():void {$this->operator();$data=$this->pageData();$data['content']='<p>Hello<script>alert(1)</script><a href="javascript:alert(1)">link</a></p>';$before=Page::count();$response=$this->postJson('/exchange-control-panel/pages/preview',$data+['mode'=>'preview']);$response->assertOk();$this->assertStringNotContainsString('<script',$response->json('content'));$this->assertStringNotContainsString('javascript:',$response->json('content'));$this->assertSame($before,Page::count());}
 public function test_settings_have_revision_conflict_protection():void {$u=$this->operator();$s=app(SitePresentation::class);$d=$s->defaults('help');$rev=$s->revision('help');$s->save('help',$d,'draft',$rev,$u->id,'QA first draft');$this->expectException(ValidationException::class);$s->save('help',$d,'publish',$rev,$u->id,'QA stale publisher');}
 public function test_empty_email_remains_unconfigured():void {$s=app(SitePresentation::class);$data=$s->validate('support',$s->defaults('support'));$this->assertSame('',$data['email']);$this->assertNotSame('24/7',$data['hours']['en']);}
 public function test_admin_views_and_authenticated_write_paths():void {
  $u=$this->operator('superadmin');$p=$this->product();$s=app(StakingConfiguration::class);
  $payload=$p->only(StakingConfiguration::FIELDS)+['revision'=>$s->revision($p),'reason'=>'QA HTTP rate edit','apr_limit'=>50];
  $payload['rewards_percentage']='1,2,3';$this->putJson('/exchange-control-panel/staking/'.$p->id,$payload)->assertRedirect();$this->assertSame('1,2,3',$p->fresh()->rewards_percentage);
  $payload['staking_type']=1;$payload['allowed_days']='30,60';$payload['rewards_percentage']='1';$this->putJson('/exchange-control-panel/staking/'.$p->id,$payload)->assertUnprocessable();
  $site=app(SitePresentation::class);$support=$site->defaults('support');$support['hours']['zh-cn']='周一至周五 09:00–18:00 (UTC+8)';
  $this->putJson('/exchange-control-panel/support-presentation/support',['settings'=>$support,'mode'=>'draft','revision'=>$site->revision('support'),'reason'=>'QA draft support'])->assertOk();
  $this->assertSame('',$site->get('support')['hours']['zh-cn']);
  $page=Page::create($this->pageData());$pub=app(PagePublication::class);
  $this->putJson('/exchange-control-panel/pages/'.$page->id,$page->only($page->getFillable())+['id'=>$page->id,'mode'=>'draft','revision'=>$pub->revision($page),'reason'=>'QA page HTTP draft'])->assertRedirect();
  if(!getenv('DEEPRO_UI_FIXTURES'))return;
  $dir=getenv('DEEPRO_UI_FIXTURES');if(!is_dir($dir))mkdir($dir,0700,true);$map=[];
  foreach(['/exchange-control-panel/pages','/exchange-control-panel/support-tickets','/exchange-control-panel/staking/'.$p->id.'/edit','/exchange-control-panel/pages/'.$page->id.'/edit'] as $i=>$url){
   $response=$this->get($url);$response->assertOk();$file=$dir.'/page-'.$i.'.html';file_put_contents($file,$response->getContent());$map[$url]=['file'=>$file,'type'=>'text/html'];
  }
  foreach(['site-presentation/navigation','site-presentation/help','support-presentation/support'] as $i=>$path){$url='/exchange-control-panel/'.$path;$response=$this->getJson($url);$response->assertOk();$file=$dir.'/data-'.$i.'.json';file_put_contents($file,$response->getContent());$map[$url]=['file'=>$file,'type'=>'application/json'];}
  file_put_contents($dir.'/map.json',json_encode($map));
 }
 public function test_staking_editor_can_edit_staking_but_not_quantify():void { $this->operator('perm_stakings');$p=$this->product();$this->get('/exchange-control-panel/staking/'.$p->id.'/edit')->assertOk();$p->update(['staking_type'=>1]);$this->get('/exchange-control-panel/staking/'.$p->id.'/edit')->assertForbidden(); }
}
