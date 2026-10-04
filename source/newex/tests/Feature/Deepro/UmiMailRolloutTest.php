<?php
namespace Tests\Feature\Deepro;
use Tests\TestCase;
use App\Models\{Umi\LegacyAccount,User\User};
use App\Jobs\{SendUmiActivationCode,SendUmiBindingCode};
use App\Mail\UmiActivationCode;
use App\Services\Umi\{LegacyActivation,LegacyBinding};
use Illuminate\Support\Facades\{DB,Mail,Event,Queue,Http,Hash};
use Illuminate\Support\Str;

final class UmiMailRolloutTest extends TestCase {
 protected function setUp():void {parent::setUp();$this->assertSame('deepro_test',DB::connection()->getDatabaseName());DB::beginTransaction();config(['cache.default'=>'array','mail.default'=>'smtp','app.readonly'=>false]);Event::fake();Mail::fake();Queue::fake();Http::fake(['*'=>Http::response([],503)]);}
 protected function tearDown():void {while(DB::transactionLevel()>0)DB::rollBack();parent::tearDown();}
 private function account(string $email):LegacyAccount {return LegacyAccount::create(['legacy_id'=>99887766,'legacy_uuid'=>'mail-fixture-'.Str::uuid(),'batch_id'=>0,'source_hash'=>str_repeat('a',64),'legacy_status'=>2,'identity'=>['nickname'=>'Mail fixture'],'profile'=>[],'approved_email'=>$email,'approved_email_lookup'=>LegacyActivation::lookup($email),'activation_status'=>'pending']);}
 private function challenge(LegacyAccount $a,?User $u=null,array $extra=[]):string {
  $id=(string)Str::uuid();$data=['id'=>$id,'legacy_id'=>$a->legacy_id,'email_lookup'=>LegacyActivation::lookup($a->approved_email),'code_hash'=>Hash::make('12345678'),'expires_at'=>now()->addMinutes(10),'created_at'=>now(),'delivery_state'=>'pending'];if($u)$data['user_id']=$u->id;
  DB::table($u?'umi_binding_challenges':'umi_activation_challenges')->insert(array_replace($data,$extra));return $id;
 }
 public function test_activation_uses_configured_mailer_localized_template_and_is_idempotent():void {
  $a=$this->account('mail-activation@example.com');$id=$this->challenge($a);$job=new SendUmiActivationCode($id,'12345678','zh-cn');$job->handle();$job->handle();
  Mail::assertSent(UmiActivationCode::class,fn($m)=>$m->purpose==='activation'&&$m->mailer==='smtp'&&$m->locale==='zh-cn'&&$m->hasTo('mail-activation@example.com'));
  Mail::assertSentCount(1);$this->assertDatabaseHas('umi_activation_challenges',['id'=>$id,'delivery_state'=>'sent']);
  $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldBeEncrypted::class,$job);
  $html=(new UmiActivationCode('12345678','activation'))->locale('zh-cn')->render();$this->assertStringContainsString('激活您的 UMI 账户',$html);$this->assertStringContainsString('10 分钟',$html);$this->assertStringNotContainsString('绑定页面',$html);
 }
 public function test_binding_mail_is_bound_to_verified_current_owner_and_uses_distinct_purpose():void {
  $u=User::withoutEvents(fn()=>User::factory()->create(['email'=>'mail-binding@example.com','email_verified_at'=>now(),'deactivated'=>false,'deleted'=>false]));$a=$this->account($u->email);$id=$this->challenge($a,$u);$job=new SendUmiBindingCode($id,'12345678','zh-cn');$job->handle();$job->handle();
  Mail::assertSent(UmiActivationCode::class,fn($m)=>$m->purpose==='binding'&&$m->hasTo($u->email)&&$m->mailer==='smtp');Mail::assertSentCount(1);
  $this->assertDatabaseHas('umi_binding_challenges',['id'=>$id,'delivery_state'=>'sent']);$job->failed(new \RuntimeException('late worker retry'));$this->assertDatabaseHas('umi_binding_challenges',['id'=>$id,'delivery_state'=>'sent']);
  $html=(new UmiActivationCode('12345678','binding'))->locale('zh-cn')->render();$this->assertStringContainsString('不能用于重设密码',$html);
  try{app(LegacyActivation::class)->activate($id,'12345678','LocalTestPassword!');$this->fail('Binding challenge accepted for activation');}catch(\Illuminate\Validation\ValidationException $e){$this->assertNotEmpty($e->errors());}
  $id2=$this->challenge($a,$u);$u->email='changed@example.com';$u->save();(new SendUmiBindingCode($id2,'12345678'))->handle();Mail::assertSentCount(1);
 }
 public function test_expired_and_consumed_codes_are_not_emailed_and_failures_are_recorded():void {
  $a=$this->account('expired@example.com');$expired=$this->challenge($a,null,['expires_at'=>now()->subSecond()]);(new SendUmiActivationCode($expired,'12345678'))->handle();
  $used=$this->challenge($a,null,['used_at'=>now()]);(new SendUmiActivationCode($used,'12345678'))->handle();Mail::assertNothingSent();
  $fresh=$this->challenge($a);(new SendUmiActivationCode($fresh,'12345678'))->failed(new \RuntimeException('SMTP unavailable'));$this->assertDatabaseHas('umi_activation_challenges',['id'=>$fresh,'delivery_state'=>'failed']);
 }
 public function test_repeated_request_returns_rate_limit_without_replacing_original_challenge():void {
  $a=$this->account('cooldown@example.com');$id=app(LegacyActivation::class)->requestCode((string)$a->legacy_id);
  try {app(LegacyActivation::class)->requestCode((string)$a->legacy_id);$this->fail('Duplicate code request accepted');}
  catch(\Illuminate\Http\Exceptions\HttpResponseException $e) {$this->assertSame(429,$e->getResponse()->getStatusCode());}
  $this->assertDatabaseHas('umi_activation_challenges',['id'=>$id,'used_at'=>null]);
 }
 public function test_delivered_activation_completes_once_without_crediting_exchange_assets():void {
  $a=$this->account('activation-complete@example.invalid');$before=$a->profile;$id=$this->challenge($a);
  (new SendUmiActivationCode($id,'12345678','zh-cn'))->handle();
  $service=app(LegacyActivation::class);
  try {$service->activate($id,'00000000','LocalFixturePassword!');$this->fail('Wrong code accepted');}
  catch(\Illuminate\Validation\ValidationException $e){$this->assertNotEmpty($e->errors());}
  $u=$service->activate($id,'12345678','LocalFixturePassword!');
  $this->assertSame($u->id,$a->fresh()->user_id);$this->assertSame($before,$a->fresh()->profile);
  $this->assertTrue($u->hasVerifiedEmail());$this->assertTrue($u->hasRole('user'));$this->assertFalse($u->hasRole('superadmin'));
  $this->assertSame(0,DB::table('wallets')->where('user_id',$u->id)->count());
  try {$service->activate($id,'12345678','LocalFixturePassword!');$this->fail('Consumed activation replayed');}
  catch(\Illuminate\Validation\ValidationException $e){$this->assertNotEmpty($e->errors());}
 }
 public function test_delivered_binding_completes_only_for_owner_and_cannot_replay():void {
  $u=User::withoutEvents(fn()=>User::factory()->create(['email'=>'binding-complete@example.invalid','email_verified_at'=>now(),'deactivated'=>false,'deleted'=>false]));
  $other=User::withoutEvents(fn()=>User::factory()->create(['email_verified_at'=>now(),'deactivated'=>false,'deleted'=>false]));
  $a=$this->account($u->email);$id=$this->challenge($a,$u);(new SendUmiBindingCode($id,'12345678','zh-cn'))->handle();$service=app(LegacyBinding::class);
  try {$service->complete($other,$id,'12345678');$this->fail('Another user claimed legacy account');}
  catch(\Illuminate\Validation\ValidationException $e){$this->assertNotEmpty($e->errors());}
  $service->complete($u,$id,'12345678');$this->assertSame($u->id,$a->fresh()->user_id);
  $this->assertNotNull(DB::table('umi_binding_challenges')->where('id',$id)->value('used_at'));
  $this->assertSame(0,DB::table('wallets')->where('user_id',$u->id)->count());
  try {$service->complete($u,$id,'12345678');$this->fail('Consumed binding replayed');}
  catch(\Illuminate\Validation\ValidationException $e){$this->assertNotEmpty($e->errors());}
 }
}
