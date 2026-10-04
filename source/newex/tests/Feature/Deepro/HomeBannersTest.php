<?php
namespace Tests\Feature\Deepro;
use Tests\TestCase;
use App\Services\Content\HomeBanners;
use App\Models\User\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\{DB,Event,Mail,Queue,Route};
use Illuminate\Validation\ValidationException;
class HomeBannersTest extends TestCase {
 use DatabaseTransactions;
 protected function setUp():void {
  parent::setUp();$this->assertSame('deepro_test',DB::connection()->getDatabaseName());
  config(['app.readonly'=>false]);if(!Route::has('admin.home-banners'))Route::middleware('web')->group(base_path('routes/admin.php'));Route::getRoutes()->refreshNameLookups();
  \Setting::set('general.maintenance_status',false);Event::fake();Mail::fake();Queue::fake();
 }
 private function actor():User {$u=User::factory()->create(['email'=>'banner-'.\Illuminate\Support\Str::uuid().'@example.invalid','email_verified_at'=>now(),'deleted'=>false,'deactivated'=>false,'is_xn'=>false]);\Auth::forgetGuards();$u->withAccessToken(new \Laravel\Sanctum\TransientToken());$this->actingAs($u,'web');return $u;}
 private function banner(array $extra=[]):array {return array_replace(['title'=>'QA banner','subtitle'=>'Test only','image'=>'/images/deepro-logo.svg','link'=>'/markets','tone'=>'mint','enabled'=>true,'start'=>null,'end'=>null],$extra);}
 public function test_public_only_receives_enabled_in_window_in_configured_order():void {
  $s=app(HomeBanners::class);$u=$this->actor();$rows=[$this->banner(['title'=>'second']),$this->banner(['enabled'=>false]),$this->banner(['start'=>now()->addDay()->toIso8601String()]),$this->banner(['end'=>now()->subMinute()->toIso8601String()]),$this->banner(['title'=>'first'])];
  $s->save($s->validate($rows),$u->id,'Test scheduling and ordering');$this->assertSame(['second','first'],array_column($s->published(),'title'));
  $this->assertDatabaseHas('operations_events',['object_type'=>'home_banners','actor_id'=>$u->id]);
 }
 public function test_unsafe_links_and_bad_schedule_are_rejected():void {
  foreach([['link'=>'javascript:alert(1)'],['link'=>'//example.org'],['image'=>'https://tracker.example/pixel'],['link'=>'/\\example.org'],['start'=>'2026-09-29','end'=>'2026-09-28']] as $bad){try{app(HomeBanners::class)->validate([$this->banner($bad)]);$this->fail('Invalid banner accepted');}catch(ValidationException $e){$this->assertNotEmpty($e->errors());}}
 }
 public function test_permissions_cover_write_and_read():void {
  $u=$this->actor();$this->getJson('/exchange-control-panel/home-banners')->assertForbidden();$this->putJson('/exchange-control-panel/home-banners',['banners'=>[],'reason'=>'Not authorized'])->assertForbidden();
  \Spatie\Permission\Models\Role::findOrCreate('perm_articles','web');$u->assignRole('perm_articles');
  $this->get('/exchange-control-panel/home-banners')->assertOk();$this->put('/exchange-control-panel/home-banners',['banners'=>[$this->banner()],'reason'=>'Publish QA banner'])->assertRedirect();
  $this->assertSame('QA banner',app(HomeBanners::class)->published()[0]['title']);
 }
 public function test_all_banners_can_be_disabled_without_fabricated_replacement():void {$u=$this->actor();$s=app(HomeBanners::class);$s->save([],$u->id,'Remove all banners');$this->assertSame([],$s->published());}
}
