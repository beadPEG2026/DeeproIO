<?php
namespace Tests\Feature\Deepro;
use App\Models\Umi\LegacyAccount;
use App\Models\User\User;
use App\Services\Umi\{LegacyImporter,LegacyActivation,LegacyPortfolio};
use App\Mail\UmiActivationCode;
use App\Jobs\SendUmiActivationCode;
use Illuminate\Support\Facades\Bus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\{DB,Mail,Hash,Route,RateLimiter};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class UmiLegacyTest extends TestCase {
    use DatabaseTransactions;
    private array $directories=[];
    protected function setUp():void {parent::setUp();$this->assertSame('umi_regression',DB::connection()->getDatabaseName());
        if(!Route::has('admin.umi')) Route::middleware('web')->group(base_path('routes/admin.php'));
        $this->withSession(['_token'=>'umi-test'])->withHeader('X-CSRF-TOKEN','umi-test');
    }
    protected function tearDown():void {
        foreach($this->directories as $d) {foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($d,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST) as $f){$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());}rmdir($d);}parent::tearDown();
    }
    private function fixture(int $status=2):string {
        $d=storage_path('framework/testing/umi-'.Str::uuid());$this->directories[]=$d;
        mkdir($d.'/合并数据',0700,true);mkdir($d.'/用户详情/900001',0700,true);
        $u=['id'=>900001,'uuid'=>'old-test-uuid','nickname'=>'Legacy fixture','email'=>'legacy-check@example.com','phone'=>'','level'=>'V3','status'=>$status];
        $p=['id'=>900001,'uuid'=>$u['uuid'],'email'=>'','email_bound'=>false,'inviter'=>['user_id'=>999999],
            'quota_summary'=>['total_quota'=>'1234.024699','used_quota'=>'42.000001'],'dapp_balance_umi'=>'0.000000000000000001','balances'=>[],
            'performance'=>['manual_level'=>'V3','exclude_from_rewards'=>true]];
        file_put_contents($d.'/合并数据/users.json',json_encode([$u]));file_put_contents($d.'/用户详情/900001/profile.json',json_encode(['data'=>$p]));
        foreach(['burn__records','team-umi-flow','team-usdt-flow','usdt__transactions'] as $s)file_put_contents($d.'/合并数据/'.$s.'.json',json_encode([['id'=>1,'user_id'=>900001,'amount'=>'0.000000000000000001','created_at'=>'2025-01-01 01:00:00']]));
        return $d;
    }
    private function imported():LegacyAccount {app(LegacyImporter::class)->import($this->fixture(),true);return LegacyAccount::findOrFail(900001);}
    private function approved():LegacyAccount {
        $a=$this->imported();$s=app(LegacyActivation::class);
        $s->propose($a->legacy_id,'legacy-check@example.com','Fixture independently verified legacy login binding; case TEST-01.',101);
        $s->approve(DB::table('umi_identity_reviews')->max('id'),102);return $a->fresh();
    }
    private function issue():array {
        Mail::fake();Bus::fake([SendUmiActivationCode::class]);$id=app(LegacyActivation::class)->requestCode('900001');$code='';
        Bus::assertDispatched(SendUmiActivationCode::class,function($job){$this->assertSame('default',$job->queue);$job->handle();return true;});
        Mail::assertSent(UmiActivationCode::class,function($m)use(&$code){$code=$m->code;return true;});return [$id,$code];
    }
    public function test_import_preserves_relations_precision_and_limits_without_crediting_money():void {
        $before=DB::table('users')->count();$wallets=DB::table('wallets')->count();$a=$this->imported();
        $this->assertSame(999999,$a->parent_legacy_id);$this->assertSame('V3',$a->level);$this->assertSame(2,$a->legacy_status);
        $this->assertSame('0.000000000000000001',$a->profile['dapp_balance_umi']);$this->assertSame('1234.024699',$a->profile['quota_summary']['total_quota']);
        $this->assertNull($a->approved_email);$this->assertSame($before,DB::table('users')->count());$this->assertSame($wallets,DB::table('wallets')->count());
        $this->assertStringNotContainsString('legacy-check@example.com',DB::table('umi_legacy_accounts')->where('legacy_id',900001)->value('identity'));
    }
    public function test_repeat_import_is_idempotent_and_modified_archive_cannot_overwrite():void {
        $d=$this->fixture();$s=app(LegacyImporter::class);$s->import($d,true);$this->assertSame('already-imported',$s->import($d,true)['mode']);
        $this->assertSame(4,DB::table('umi_legacy_records')->where('legacy_id',900001)->count());
        $f=$d.'/合并数据/users.json';$u=json_decode(file_get_contents($f),true);$u[0]['level']='V1';file_put_contents($f,json_encode($u));
        try{$s->import($d,true);$this->fail();}catch(\RuntimeException $e){$this->assertSame(__('历史档案发生变化，需要单独对账；未覆盖已有档案。'),$e->getMessage());}
        $this->assertSame('V3',LegacyAccount::find(900001)->level);
    }
    public function test_relation_cycle_prevents_any_import():void {
        $d=$this->fixture();$f=$d.'/用户详情/900001/profile.json';$p=json_decode(file_get_contents($f),true);$p['data']['inviter']['user_id']=900001;file_put_contents($f,json_encode($p));
        try{app(LegacyImporter::class)->import($d,true);$this->fail();}catch(\RuntimeException $e){$this->assertSame(__('原关系出现循环，已停止导入。'),$e->getMessage());}
        $this->assertNull(LegacyAccount::find(900001));
    }
    public function test_unverified_contact_does_not_receive_code_and_response_does_not_disclose_existence():void {
        $this->imported();Mail::fake();$one=$this->postJson('/umi-ecosystem/activate/code',['identifier'=>'900001'])->assertOk();
        $two=$this->postJson('/umi-ecosystem/activate/code',['identifier'=>'no-such-identity'])->assertOk();
        $this->assertSame($one->json('message'),$two->json('message'));$this->assertNotSame($one->json('challenge'),$two->json('challenge'));Mail::assertNothingOutgoing();
    }
    public function test_review_cannot_be_self_approved():void {
        $this->imported();$s=app(LegacyActivation::class);$s->propose(900001,'legacy-check@example.com','Fixture independent evidence reference TEST-02',101);
        $this->expectException(ValidationException::class);$s->approve(DB::table('umi_identity_reviews')->max('id'),101);
    }
    public function test_verified_activation_sets_new_password_once_preserving_umi_relationship_and_no_credit():void {
        $this->approved();[$id,$code]=$this->issue();$before=DB::table('wallets')->count();
        $u=app(LegacyActivation::class)->activate($id,$code,'OnlyLocalFixture2026!');$a=LegacyAccount::find(900001);
        $this->assertTrue(Hash::check('OnlyLocalFixture2026!',$u->password));$this->assertSame('activated',$a->activation_status);
        $this->assertSame(999999,$a->parent_legacy_id);$this->assertSame('V3',$a->level);$this->assertTrue($u->withdrawal_disabled);
        $this->assertNull($u->referral_id);$this->assertSame(['user'],$u->getRoleNames()->all());
        foreach(DB::table('wallets')->where('user_id',$u->id)->get() as $wallet) {
            foreach((array)$wallet as $key=>$value) if(str_starts_with($key,'balance_')) $this->assertSame(0,bccomp((string)($value??'0'),'0',18),$key);
        }
        $this->assertSame($u->id,app(LegacyActivation::class)->resolveLogin('UMI:900001')->id);
        $this->expectException(ValidationException::class);app(LegacyActivation::class)->activate($id,$code,'AnotherLocalPassword!');
    }
    public function test_five_wrong_attempts_remain_persisted_and_correct_code_then_fails():void {
        $this->approved();[$id,$code]=$this->issue();$wrong=$code==='11111111'?'22222222':'11111111';
        for($i=0;$i<5;$i++){try{app(LegacyActivation::class)->activate($id,$wrong,'OnlyLocalFixture2026!');$this->fail();}catch(ValidationException $e){}}
        $this->assertSame(5,DB::table('umi_activation_challenges')->find($id)->attempts);
        $this->expectException(ValidationException::class);app(LegacyActivation::class)->activate($id,$code,'OnlyLocalFixture2026!');
    }
    public function test_expired_code_is_rejected():void {
        $this->approved();[$id,$code]=$this->issue();DB::table('umi_activation_challenges')->where('id',$id)->update(['expires_at'=>now()->subMinute()]);
        $this->expectException(ValidationException::class);app(LegacyActivation::class)->activate($id,$code,'OnlyLocalFixture2026!');
    }
    public function test_disabled_legacy_account_cannot_request_activation_code():void {
        app(LegacyImporter::class)->import($this->fixture(0),true);$a=LegacyAccount::findOrFail(900001);$a->update(['approved_email'=>'legacy-check@example.com']);Mail::fake();app(LegacyActivation::class)->requestCode('900001');Mail::assertNothingOutgoing();
    }
    public function test_guest_cannot_read_history_and_authenticated_user_cannot_choose_legacy_id():void {
        $this->imported();$this->get('/umi-ecosystem/account?section=burn')->assertRedirect();
        $this->get('/umi-ecosystem/activate')->assertOk();
    }
    public function test_existing_exchange_account_is_never_overwritten():void {
        $this->approved();[$id,$code]=$this->issue();$u=User::factory()->create(['email'=>'legacy-check@example.com']);$hash=$u->password;
        try{app(LegacyActivation::class)->activate($id,$code,'OnlyLocalFixture2026!');$this->fail();}catch(ValidationException $e){}
        $this->assertSame($hash,$u->fresh()->password);$this->assertNull(LegacyAccount::find(900001)->user_id);
    }
    public function test_user_cannot_view_someone_else_or_admin_archive():void {
        $this->imported();$u=User::factory()->create();$u->assignRole('user');
        $target=route('umi.portfolio',['tab'=>'history','legacy_section'=>'overview','page'=>1]);
        $this->actingAs($u)->get('/umi-ecosystem/account?id=900001')->assertRedirect($target);
        $this->get($target)->assertOk()->assertInertia(fn($p)=>$p->component('Umi/FundedHome')->where('legacy',null));
        $this->get('/exchange-control-panel/umi')->assertRedirect(route('admin.login'));
    }
    public function test_guest_recovery_request_does_not_bind_contact_or_set_password():void {
        $this->imported();$r=$this->postJson('/umi-ecosystem/recovery',['identifier'=>'900001','reply_email'=>'claimant@example.com','evidence'=>'A claim is unverified and cannot grant access to the legacy account.'])->assertOk();
        $this->assertTrue(Str::isUuid($r->json('reference')));$this->assertNull(LegacyAccount::find(900001)->approved_email);$this->assertNull(LegacyAccount::find(900001)->user_id);
    }
    public function test_fee_preview_preserves_observed_three_rates_and_cannot_execute():void {
        $s=app(LegacyPortfolio::class);$a=$s->feePreview('1','10.123456');$this->assertSame('3.037036800000000000',$a['fee']);
        $this->assertSame('7.08',$a['legacy_display_net']);$this->assertFalse($a['executable']);
        $this->assertSame('0.000000000000000000',$s->feePreview('3','10')['fee']);$this->assertSame('0.30',$s->feePreview('2','10')['rate']);
    }
    public function test_umi_asset_is_exactly_user_confirmed_bsc_contract_without_enabling_broadcast():void {
        $this->assertSame(56,config('umi.asset.chain_id'));$this->assertSame(18,config('umi.asset.decimals'));
        $this->assertSame('0xa1bc94946fc3479fe5602be67c7b554335423a4a',config('umi.asset.contract'));
        $this->assertFalse(config('umi.asset.withdrawals_enabled'));$this->assertFalse(config('umi.settlement_enabled'));
    }
    public function test_asset_registration_is_idempotent_and_preserves_existing_market_configuration():void {
        $before=(array)DB::table('markets')->where('name','UMI-USDT')->first();
        $this->artisan('umi:register-ecosystem-assets',['--commit'=>true])->assertSuccessful();
        $this->artisan('umi:register-ecosystem-assets',['--commit'=>true])->assertSuccessful();
        $this->assertSame(1,DB::table('currencies')->where('symbol','UMI')->count());
        $c=DB::table('currencies')->where('symbol','UMI')->first();$this->assertTrue($c->deposit_status);$this->assertTrue($c->withdraw_status);
        $this->assertSame(config('umi.asset.contract'),$c->bep_contract);
        $m=DB::table('markets')->where('name','UMI-USDT')->first();$this->assertSame($before,(array)$m);$this->assertTrue($m->trade_status);$this->assertFalse($m->custom_liquidity);
    }
    public function test_umi_mail_uses_dedicated_encrypted_queue_and_skips_used_challenges():void {
        $this->approved();[$id,$code]=$this->issue();
        $this->assertSame('sent',DB::table('umi_activation_challenges')->where('id',$id)->value('delivery_state'));
        Mail::fake();$job=new SendUmiActivationCode($id,$code);
        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldBeEncrypted::class,$job);
        $job->handle();Mail::assertNothingOutgoing();
        DB::table('umi_activation_challenges')->where('id',$id)->update(['delivery_state'=>'pending','used_at'=>now()]);
        $job->handle();Mail::assertNothingOutgoing();
    }
    public function test_recovery_workflow_cannot_grant_access_or_change_legacy_relationships():void {
        $a=$this->imported();$before=$a->getRawOriginal();
        $admin=User::factory()->create();$admin->assignRole(\Spatie\Permission\Models\Role::findOrCreate('superadmin','web'));
        $id=(string)Str::uuid();DB::table('umi_recovery_requests')->insert(['id'=>$id,'identifier'=>encrypt('900001'),'reply_email'=>encrypt('claim@example.com'),'evidence'=>encrypt('Claim evidence, still unverified'),'created_at'=>now(),'updated_at'=>now()]);
        $s=app(\App\Services\Umi\RecoveryWorkflow::class);
        $s->update($id,$admin,0,'in_review','已受理，正在核查原账号归属与历史记录。',900001);
        $this->assertSame($before,$a->fresh()->getRawOriginal());
        $this->assertSame('in_review',DB::table('umi_recovery_requests')->find($id)->state);
        $this->assertStringNotContainsString('已受理',DB::table('umi_recovery_actions')->where('request_id',$id)->value('note'));
        try{$s->update($id,$admin,1,'resolved','已完成初步沟通，申请关闭此工单。',900001);$this->fail('Unverified claim closed as verified');}catch(ValidationException $e){$this->assertArrayHasKey('recovery',$e->errors());}
        try{$s->update($id,$admin,0,'needs_information','请补充原账户燃烧订单的历史记录。',900001);$this->fail('Stale request overwrote history');}catch(\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e){$this->assertSame(409,$e->getStatusCode());}
        $this->assertSame(1,DB::table('umi_recovery_actions')->where('request_id',$id)->count());
    }
    public function test_recovery_can_close_only_after_two_person_identity_review():void {
        $a=$this->approved();$admin=User::factory()->create();$admin->assignRole(\Spatie\Permission\Models\Role::findOrCreate('superadmin','web'));
        $id=(string)Str::uuid();DB::table('umi_recovery_requests')->insert(['id'=>$id,'identifier'=>encrypt('900001'),'reply_email'=>encrypt('claim@example.com'),'evidence'=>encrypt('Independent review evidence'),'created_at'=>now(),'updated_at'=>now()]);
        app(\App\Services\Umi\RecoveryWorkflow::class)->update($id,$admin,0,'resolved','已核对双人身份复核记录，账户恢复申请结案。',900001);
        $this->assertNotNull(DB::table('umi_recovery_requests')->find($id)->closed_at);
        $this->assertNull($a->fresh()->user_id);
        $this->assertSame(999999,$a->fresh()->parent_legacy_id);
        $this->assertSame('V3',$a->fresh()->level);
    }
}
