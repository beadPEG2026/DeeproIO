<?php
namespace Tests\Feature\Deepro;

use Tests\TestCase;
use App\Models\User\User;
use App\Models\FileUpload\FileUpload;
use App\Models\Support\{SupportMessage, SupportTicketEntry};
use App\Services\Content\ProductPublication;
use App\Services\Wallet\NetworkAvailability;
use Illuminate\Support\Facades\{DB, Event, Http, Mail, Queue, Route, Storage};
use Illuminate\Support\Str;
use Illuminate\Http\{Request, UploadedFile};

final class AccountExperienceRemediationTest extends TestCase
{
    protected function setUp(): void {
        parent::setUp();
        $this->assertSame('deepro_test', DB::connection()->getDatabaseName());
        config(['app.readonly'=>false,'cache.default'=>'array','session.driver'=>'array','broadcasting.default'=>'log']);
        DB::beginTransaction(); Event::fake(); Mail::fake(); Queue::fake(); Http::preventStrayRequests();
        Storage::fake('local');
        if (!Route::has('admin.support.tickets')) Route::middleware('web')->group(base_path('routes/admin.php'));
        Route::getRoutes()->refreshNameLookups();
        \Setting::set('general.maintenance_status', false);
        \Setting::set('recaptcha.status', false);
    }
    protected function tearDown():void { while(DB::transactionLevel()>0)DB::rollBack();parent::tearDown(); }
    private function user(string $role='user'):User {
        $u=User::factory()->create(['email'=>'account-qa-'.Str::uuid().'@example.invalid','email_verified_at'=>now(),'deleted'=>false,'deactivated'=>false,'is_xn'=>false]);
        $u->assignRole($role); return $u;
    }
    private function asUser(User $u):self { \Auth::forgetGuards();$u->withAccessToken(new \Laravel\Sanctum\TransientToken());$this->actingAs($u,'web');return $this; }
    private function upload(User $u):int {
        $this->asUser($u);
        return $this->post('/support/attachments',['file'=>UploadedFile::fake()->image('evidence.png')])->assertOk()->json('uuid');
    }
    private function ticket(User $u,array $data=[]):SupportMessage {
        return SupportMessage::create(array_merge(['user_id'=>$u->id,'title'=>'Local QA ticket','body'=>'Local QA request','status'=>'new'], $data));
    }
    public function test_private_attachment_ownership_binding_and_download_are_enforced():void {
        $a=$this->user();$b=$this->user();$id=$this->upload($a);$f=FileUpload::findOrFail($id);
        $this->assertTrue($f->is_private);$this->assertArrayNotHasKey('path',$f->toArray());Storage::disk('local')->assertExists($f->path);
        $this->asUser($b)->get('/support/attachments/'.$id)->assertNotFound();
        $this->deleteJson('/support/attachments',['uuid'=>$id])->assertForbidden();
        $payload=['title'=>'A ticket','body'=>'Details','file_id'=>$id,'request_key'=>(string)Str::uuid()];
        $this->postJson('/support',$payload)->assertUnprocessable()->assertJsonValidationErrors('file_id');
        $this->asUser($a)->postJson('/support',$payload)->assertRedirect();
        $ticket=SupportMessage::where('request_key',$payload['request_key'])->firstOrFail();
        $this->postJson('/support',$payload)->assertRedirect();
        $this->assertSame(1,SupportMessage::where('request_key',$payload['request_key'])->count());
        $this->get('/support/attachments/'.$id)->assertOk()->assertHeader('X-Content-Type-Options','nosniff');
        $this->deleteJson('/support/attachments',['uuid'=>$id])->assertStatus(409);
        $payload['request_key']=(string)Str::uuid();$this->postJson('/support',$payload)->assertUnprocessable();
        $this->asUser($this->user('superadmin'))->get('/support/attachments/'.$id)->assertOk();
        $this->asUser($b)->get('/support/tickets/'.$ticket->id)->assertNotFound();
    }
    public function test_recent_unowned_upload_cannot_be_deleted_and_unused_owned_upload_can():void {
        $a=$this->user();$f=FileUpload::create(['name'=>'legacy.png','path'=>'/storage/uploads/legacy.png']);
        $this->asUser($a)->deleteJson('/upload',['uuid'=>$f->id])->assertForbidden();
        $id=$this->upload($a);$path=FileUpload::findOrFail($id)->path;
        $this->deleteJson('/support/attachments',['uuid'=>$id])->assertOk();Storage::disk('local')->assertMissing($path);$this->assertDatabaseMissing('file_uploads',['id'=>$id]);
    }
    public function test_ticket_detail_hides_internal_notes_and_workflow_metadata():void {
        $a=$this->user();$t=$this->ticket($a,['reply'=>'Legacy answer']);
        foreach(['note'=>'PRIVATE_NOTE_SENTINEL','workflow'=>'PRIVATE_WORKFLOW_SENTINEL','reply'=>'Public answer'] as $kind=>$body) SupportTicketEntry::create(['support_message_id'=>$t->id,'request_key'=>(string)Str::uuid(),'kind'=>$kind,'body'=>$body,'changes'=>['internal'=>'PRIVATE_CHANGE_SENTINEL']]);
        $r=$this->asUser($a)->get('/support/tickets/'.$t->id,['X-Inertia'=>'true','X-Inertia-Version'=>app(\App\Http\Middleware\HandleInertiaRequests::class)->version(Request::create('/support'))])->assertOk();
        $r->assertJsonPath('component','Support/Ticket')->assertDontSee('PRIVATE_NOTE_SENTINEL')->assertDontSee('PRIVATE_WORKFLOW_SENTINEL')->assertDontSee('PRIVATE_CHANGE_SENTINEL');
        $this->assertCount(2,$r->json('props.ticket.entries'));
        $other=$this->ticket($this->user(),['title'=>'OTHER_OWNER_SENTINEL']);
        $this->get('/support/tickets',['X-Inertia'=>'true','X-Inertia-Version'=>app(\App\Http\Middleware\HandleInertiaRequests::class)->version(Request::create('/support'))])->assertOk()->assertDontSee('OTHER_OWNER_SENTINEL');
        $this->postJson('/support/tickets/'.$other->id,[])->assertNotFound();
    }
    public function test_customer_and_admin_conversation_retries_conflicts_and_close_reopen():void {
        $a=$this->user();$t=$this->ticket($a);$u='/support/tickets/'.$t->id;
        $payload=['action'=>'reply','body'=>'More details','request_key'=>(string)Str::uuid(),'revision'=>0];
        $this->asUser($a)->postJson($u,$payload)->assertRedirect();$this->postJson($u,$payload)->assertRedirect();$this->assertSame(1,$t->entries()->count());
        $payload['request_key']=(string)Str::uuid();$this->postJson($u,$payload)->assertUnprocessable()->assertJsonValidationErrors('revision');
        $admin=$this->user('superadmin');$this->asUser($admin)->postJson('/exchange-control-panel/support-tickets/'.$t->id.'/reply',['reply'=>'Please retry','request_key'=>(string)Str::uuid(),'revision'=>1,'status'=>'replied','priority'=>'normal'])->assertRedirect();
        $this->assertSame('replied',$t->fresh()->status);
        $this->asUser($a)->postJson($u,['action'=>'close','request_key'=>(string)Str::uuid(),'revision'=>2])->assertRedirect();$this->assertSame('closed',$t->fresh()->status);
        $this->postJson($u,['action'=>'reply','body'=>'Not reopened','request_key'=>(string)Str::uuid(),'revision'=>3])->assertUnprocessable();
        $this->postJson($u,['action'=>'reopen','request_key'=>(string)Str::uuid(),'revision'=>3])->assertRedirect();$this->assertSame('new',$t->fresh()->status);
    }
    public function test_maintenance_api_signals_unavailability_and_both_admin_roles_preview():void {
        $m=new \App\Http\Middleware\Maintenance();\Setting::set('general.maintenance_status',true);
        $r=Request::create('/api/v1/anything','GET');$route=new \Illuminate\Routing\Route('GET','api/v1/anything',fn()=>null);$r->setRouteResolver(fn()=>$route);
        $res=$m->handle($r,fn()=>response('preview'));$this->assertSame(503,$res->getStatusCode());$this->assertSame('60',$res->headers->get('Retry-After'));
        $this->assertSame('SITE_MAINTENANCE',json_decode($res->getContent(),true)['code']);
        foreach(['admin','superadmin'] as $role){$user=$this->user($role);$r->setUserResolver(fn()=>$user);$this->assertSame('preview',$m->handle($r,fn()=>response('preview'))->getContent());}
    }
    public function test_network_explanations_use_request_policies_without_exposing_internal_reasons():void {
        $c=\App\Models\Currency\Currency::where('symbol','USDT')->with('networks')->firstOrFail();$n=$c->networks->first();
        DB::table('networks')->where('id',$n->id)->update(['withdraw_status'=>false]);
        $row=collect(app(NetworkAvailability::class)->forCurrency($c,'withdraw',null))->firstWhere('id',$n->id);
        $this->assertFalse($row['available']);$this->assertSame('paused',$row['code']);
        $this->assertStringNotContainsString('credential',implode(' ',NetworkAvailability::reason('Scanner credential is missing')));
        $u=$this->user();$r=$this->asUser($u)->getJson('/api/v1/wallets/deposit/networks?symbol=USDT&purpose=withdraw&include_unavailable=1')->assertOk();$this->assertNotEmpty($r->json('networks'));$this->assertStringContainsString('no-store',$r->headers->get('Cache-Control'));
        $legacy=$this->getJson('/api/v1/wallets/deposit/networks?symbol=USDT&purpose=withdraw')->assertOk();$this->assertArrayNotHasKey($n->id,$legacy->json());
    }
    public function test_product_update_detects_another_edit_before_saving_or_auditing():void {
        $p=\App\Models\Launchpad\Launchpad::firstOrFail();$p->forceFill(['publication_revision'=>3])->save();$old=$p->name;$count=DB::table('product_publication_events')->count();
        $r=Request::create('/','POST',['publication_status'=>'draft','publication_revision'=>2]);$u=$this->user('superadmin');$r->setUserResolver(fn()=>$u);
        try {ProductPublication::save($r,fn($f)=>$p->forceFill(['name'=>'Should never save'])->save(),$p);$this->fail('stale update accepted');}catch(\Illuminate\Validation\ValidationException $e){$this->assertArrayHasKey('publication_revision',$e->errors());}
        $this->assertSame($old,$p->fresh()->name);$this->assertSame($count,DB::table('product_publication_events')->count());
        $r->merge(['publication_revision'=>3]);ProductPublication::save($r,function($f)use($p){$p->forceFill($f)->save();return $p;},$p);$this->assertSame(4,(int)$p->fresh()->publication_revision);
    }
}
