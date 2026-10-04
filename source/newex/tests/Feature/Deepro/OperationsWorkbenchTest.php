<?php
namespace Tests\Feature\Deepro;

use Tests\TestCase;
use App\Models\User\User;
use App\Models\Support\SupportMessage;
use App\Services\Operations\{Assets,Incidents,History,UserService,Trace};
use App\Services\SystemMonitor\OperationsHealth;
use Illuminate\Support\Facades\{DB,Event,Http,Mail,Queue,Route};
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Carbon\Carbon;

final class OperationsWorkbenchTest extends TestCase
{
    private string $prefix='/exchange-control-panel/operations';
    protected function setUp():void {
        parent::setUp();$this->assertSame('deepro_test',DB::connection()->getDatabaseName());
        config(['app.readonly'=>false,'cache.default'=>'array','session.driver'=>'array','broadcasting.default'=>'log']);
        DB::beginTransaction();Event::fake();Mail::fake();Queue::fake();Http::preventStrayRequests();
        if(!Route::has('admin.operations.assets'))Route::middleware('web')->group(base_path('routes/admin.php'));
        Route::getRoutes()->refreshNameLookups();\Setting::set('general.maintenance_status',false);\Setting::set('recaptcha.status',false);
        $this->health();
    }
    protected function tearDown():void {Carbon::setTestNow();while(DB::transactionLevel()>0)DB::rollBack();parent::tearDown();}
    private function user(string $role='user',?int $parent=null):User {
        \Spatie\Permission\Models\Role::findOrCreate($role,'web');
        $u=User::factory()->create(['email'=>'ops-qa-'.Str::uuid().'@example.invalid','email_verified_at'=>now(),'deleted'=>false,'deactivated'=>false,'is_xn'=>false,'referral_id'=>$parent]);$u->assignRole($role);return $u;
    }
    private function asUser(User $user):self {\Auth::forgetGuards();$user->withAccessToken(new \Laravel\Sanctum\TransientToken());$this->actingAs($user,'web');return $this;}
    private function page(string $url) {return $this->get($url,['X-Inertia'=>'true','X-Inertia-Version'=>app(\App\Http\Middleware\HandleInertiaRequests::class)->version(Request::create('/'))]);}
    private function health(string $status='ok',bool $fresh=true):void {
        $report=['status'=>$status,'fresh'=>$fresh,'generated_at'=>$fresh?now()->toISOString():null,'checks'=>[['name'=>'postgres','status'=>$status]]];
        app()->instance(OperationsHealth::class,new class($report){public function __construct(private array $report){}public function summary(){return $this->report;}});
    }
    private function payload(array $extra=[]):array {return array_merge(['request_key'=>(string)Str::uuid(),'revision'=>0,'status'=>'following','priority'=>'normal','assigned_to'=>null,'due_at'=>now()->addHour()->toISOString(),'reason'=>'QA operator checked the case evidence.'],$extra);}
    public function test_all_four_pages_render_and_are_private_without_external_calls():void {
        $admin=$this->user('superadmin');$this->asUser($admin);
        foreach(['assets'=>'Assets','trace'=>'Trace','users'=>'User','incidents'=>'Incidents'] as $uri=>$component){$r=$this->page($this->prefix.'/'.$uri)->assertOk()->assertJsonPath('component','Admin/Operations/'.$component);$this->assertStringContainsString('no-store',$r->headers->get('Cache-Control'));}
        $this->page($this->prefix.'/users/'.$admin->id)->assertOk();Http::assertNothingSent();
    }
    public function test_user_and_deactivated_admin_cannot_access_operations():void {
        foreach([$this->user(),$this->user('superadmin')] as $i=>$user){if($i)$user->forceFill(['deactivated'=>true])->save();$this->asUser($user);foreach(['assets','trace','users','incidents'] as $uri){$r=$this->get($this->prefix.'/'.$uri);$this->assertContains($r->status(),[302,403]);$r->assertDontSee('Admin/Operations/');}}
    }
    public function test_asset_editor_and_support_operator_keep_existing_module_boundaries():void {
        $this->asUser($this->user('assets_editor'));$this->page($this->prefix.'/assets')->assertOk();$this->get($this->prefix.'/incidents')->assertForbidden();$this->get($this->prefix.'/users')->assertForbidden();
        $this->get($this->prefix.'/trace?type=deposit&reference=1')->assertForbidden();
        $this->asUser($this->user('perm_support_tickets'));$this->page($this->prefix.'/trace')->assertOk()->assertJsonPath('props.types.0.value','ticket');$this->get($this->prefix.'/assets')->assertForbidden();
    }
    public function test_user_scope_assignment_does_not_change_referral_or_wallet_data():void {
        $leader=$this->user('admin');$child=$this->user('user',$leader->id);$other=$this->user();$owner=$this->user('superadmin');
        $this->asUser($leader);$this->page($this->prefix.'/users/'.$child->id)->assertOk()->assertJsonPath('props.orders',null)->assertJsonPath('props.umi_referral',null);$this->get($this->prefix.'/users/'.$other->id)->assertForbidden();
        $walletBefore=DB::table('wallets')->where('user_id',$child->id)->get()->toJson();$umiBefore=DB::table('umi_business_accounts')->where('user_id',$child->id)->get()->toJson();
        $payload=$this->payload(['assigned_to'=>$leader->id]);$url=$this->prefix.'/users/'.$child->id;
        $this->postJson($url,$payload)->assertRedirect();$this->postJson($url,$payload)->assertRedirect();$this->assertSame(1,DB::table('operations_events')->where('request_key',$payload['request_key'])->count());
        $stale=$payload;$stale['request_key']=(string)Str::uuid();$this->postJson($url,$stale)->assertUnprocessable()->assertJsonValidationErrors('revision');
        $this->postJson($url,$this->payload(['revision'=>1,'assigned_to'=>$other->id]))->assertUnprocessable()->assertJsonValidationErrors('assigned_to');
        $this->assertSame($leader->id,$child->fresh()->referral_id);$this->assertSame($walletBefore,DB::table('wallets')->where('user_id',$child->id)->get()->toJson());$this->assertSame($umiBefore,DB::table('umi_business_accounts')->where('user_id',$child->id)->get()->toJson());
        $this->page($this->prefix.'/users')->assertOk()->assertJsonCount(1,'props.queue');
        $payload['assigned_to']=$owner->id;$this->asUser($owner)->postJson($this->prefix.'/users/'.$other->id,$payload)->assertStatus(409);
    }
    public function test_ticket_business_reference_and_uuid_trace_without_uuid_cast_error():void {
        $u=$this->user();$admin=$this->user('superadmin');$uuid=(string)Str::uuid();$t=SupportMessage::create(['user_id'=>$u->id,'ticket_id'=>'OPS-TEST-REF','title'=>'QA ticket','body'=>'details','status'=>'new','request_key'=>$uuid]);
        $this->asUser($admin);
        foreach(['OPS-TEST-REF',$uuid,(string)$t->id] as $ref)$this->page($this->prefix.'/trace?type=ticket&reference='.$ref)->assertOk()->assertJsonPath('props.results.0.record.id',$t->id);
        $this->page($this->prefix.'/trace?type=ticket&reference=DOES-NOT-EXIST')->assertOk()->assertJsonCount(0,'props.results');
        $this->getJson($this->prefix.'/trace?type=ticket&reference=invalid%2Fpath')->assertUnprocessable();
    }
    public function test_channel_trace_and_asset_projection_omit_raw_secrets():void {
        $this->asUser($this->user('superadmin'));$c=\App\Models\Currency\Currency::where('symbol','USDT')->firstOrFail();$channel=\App\Models\Deposit\DepositChannel::create(['currency_id'=>$c->id,'network_id'=>6,'chain'=>'bsc','kind'=>'token','contract'=>$c->bep_contract,'decimals'=>18,'state'=>'draft']);
        DB::table('deposit_channel_audits')->insert(['channel_id'=>$channel->id,'actor_id'=>auth()->id(),'before'=>json_encode(['contract'=>'safe-public-contract','secret'=>'OPS_SECRET_SENTINEL']),'after'=>json_encode(['state'=>'active','rpc'=>'OPS_RPC_SENTINEL']),'created_at'=>now()]);
        $r=$this->page($this->prefix.'/trace?type=channel&reference='.$channel->id)->assertOk()->assertDontSee('OPS_SECRET_SENTINEL')->assertDontSee('OPS_RPC_SENTINEL');
        $this->assertSame('active',$r->json('props.results.0.events.0.after.state'));
        $r=$this->page($this->prefix.'/assets?asset='.$channel->currency_id)->assertOk();$this->assertCount(1,$r->json('props.assets.data'));
        $this->assertStringNotContainsString('private_key',$r->getContent());
    }
    public function test_incident_dedup_assignment_recovery_close_and_reopen():void {
        DB::statement("SET LOCAL TIME ZONE 'UTC'");
        Carbon::setTestNow('2026-09-26 12:00:00 UTC');$admin=$this->user('superadmin');$this->asUser($admin);$this->health('critical');$service=app(Incidents::class);
        $service->sync();$service->sync();$row=DB::table('operations_incidents')->where('check_key','postgres')->first();$this->assertSame(1,DB::table('operations_incidents')->where('check_key','postgres')->count());$this->assertCount(1,History::for('incident',$row->id));
        $this->assertSame(now()->toISOString(),\Carbon\CarbonImmutable::parse($row->last_unhealthy_at)->toISOString());
        $url=$this->prefix.'/incidents/'.$row->id;$p=$this->payload(['status'=>'investigating','assigned_to'=>$admin->id]);$this->postJson($url,$p)->assertRedirect();$this->postJson($url,$p)->assertRedirect();$this->assertSame(1,DB::table('operations_events')->where('request_key',$p['request_key'])->count());
        $this->postJson($url,$this->payload(['revision'=>1,'status'=>'resolved']))->assertUnprocessable()->assertJsonValidationErrors('status');
        Carbon::setTestNow(now()->addMinute());$this->health();$service->sync();$row=DB::table('operations_incidents')->where('id',$row->id)->first();$this->assertNotNull($row->recovery_at,json_encode(['timezone'=>config('app.timezone'),'now'=>now()->toISOString(),'sample_at'=>$row->sample_at,'last_unhealthy_at'=>$row->last_unhealthy_at]));$this->assertSame('investigating',$row->status);
        $this->postJson($url,$this->payload(['revision'=>$row->revision,'status'=>'resolved']))->assertRedirect();$this->assertSame('resolved',DB::table('operations_incidents')->where('id',$row->id)->value('status'));
        Carbon::setTestNow(now()->addMinute());$this->health('warning');$service->sync();$this->assertSame('open',DB::table('operations_incidents')->where('id',$row->id)->value('status'));$this->assertSame('incident.reopened',History::for('incident',$row->id)->first()->action);
    }
    public function test_stale_monitoring_is_an_incident_and_cannot_close():void {
        $this->asUser($this->user('superadmin'));$this->health('unknown',false);$s=app(Incidents::class);$s->sync();$s->sync();$r=DB::table('operations_incidents')->where('check_key','monitoring')->first();$this->assertNotNull($r);$this->assertCount(1,History::for('incident',$r->id));
        $this->postJson($this->prefix.'/incidents/'.$r->id,$this->payload(['status'=>'resolved']))->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->postJson($this->prefix.'/incidents/'.$r->id,$this->payload(['status'=>'investigating','assigned_to'=>$this->user('user')->id]))->assertUnprocessable()->assertJsonValidationErrors('assigned_to');
    }
    public function test_readonly_mode_prevents_incident_and_user_metadata_writes():void {
        $a=$this->user('superadmin');$u=$this->user();$this->asUser($a);config(['app.readonly'=>true]);
        $this->postJson($this->prefix.'/users/'.$u->id,$this->payload())->assertStatus(423);$this->postJson($this->prefix.'/incidents/sync')->assertStatus(423);$this->assertSame(0,DB::table('operations_service_cases')->where('user_id',$u->id)->count());
    }
    public function test_deposit_trace_keeps_report_scopes_and_links_custody_without_payload():void {
        $admin=$this->user('admin');$child=$this->user('user',$admin->id);$outside=$this->user();$currency=\App\Models\Currency\Currency::where('symbol','TRX')->firstOrFail();
        $ids=[];foreach([$child,$outside] as $i=>$user)$ids[]=DB::table('deposits')->insertGetId(['deposit_id'=>'OPS-DEPOSIT-'.$i,'txn'=>str_repeat((string)($i+1),64),'type'=>'coin','currency_id'=>$currency->id,'network_id'=>NETWORK_TRX,'user_id'=>$user->id,'address'=>'QA-LOCAL-ADDRESS','amount'=>'1','status'=>DEPOSIT_CONFIRMED,'confirms'=>20,'initial_raw'=>json_encode(['secret'=>'OPS_PRIVATE_DEPOSIT']),'raw'=>json_encode(['secret'=>'OPS_PRIVATE_RAW']),'created_at'=>now(),'updated_at'=>now()]);
        $this->asUser($admin);$this->page($this->prefix.'/trace?type=deposit&reference=OPS-DEPOSIT-0')->assertOk()->assertJsonCount(1,'props.results')->assertDontSee('OPS_PRIVATE_RAW');$this->page($this->prefix.'/trace?type=deposit&reference=OPS-DEPOSIT-1')->assertOk()->assertJsonCount(0,'props.results');
        $this->asUser($this->user('superadmin'));$this->page($this->prefix.'/trace?type=deposit&reference=OPS-DEPOSIT-1')->assertOk()->assertJsonCount(1,'props.results');
    }
    public function test_service_team_configuration_is_idempotent_scoped_and_not_an_authorization_grant():void {
        $super=$this->user('superadmin');$leader=$this->user('admin');$customer=$this->user();$this->asUser($super);
        $data=$this->payload(['id'=>null,'name'=>'Local operations team','member_ids'=>[$super->id,$leader->id],'enabled'=>true]);
        $this->postJson($this->prefix.'/teams',$data)->assertRedirect();$this->postJson($this->prefix.'/teams',$data)->assertRedirect();
        $team=DB::table('operations_service_teams')->where('name',$data['name'])->first();$this->assertNotNull($team);$this->assertSame(1,DB::table('operations_service_teams')->where('name',$data['name'])->count());
        $this->postJson($this->prefix.'/users/'.$customer->id,$this->payload(['team_id'=>$team->id,'assigned_to'=>$super->id]))->assertRedirect();
        $this->page($this->prefix.'/trace?type=request&reference='.$data['request_key'])->assertOk()->assertJsonPath('props.results.0.record.id',$team->id);
        $this->asUser($leader)->get($this->prefix.'/users/'.$customer->id)->assertForbidden();
        $this->postJson($this->prefix.'/teams',$data)->assertForbidden();
        $this->get($this->prefix.'/trace?type=team&reference='.$team->id)->assertForbidden();
        $this->assertNull($customer->fresh()->referral_id);
    }

    public function test_large_release_manifest_still_displays_only_a_validated_tag():void {
        $path=tempnam(sys_get_temp_dir(),'ops-release-');
        try {file_put_contents($path,json_encode(['tag'=>'20260926-admin-operations-r9-operations-workbench','files'=>str_repeat('x',100000)]));$r=new \App\Services\SystemMonitor\ReleaseInfo();$this->assertSame('20260926-admin-operations-r9-operations-workbench',$r->version($path));file_put_contents($path,json_encode(['tag'=>'<script>unsafe</script>']));$this->assertSame('Unknown',$r->version($path));}finally{unlink($path);}
    }

}
