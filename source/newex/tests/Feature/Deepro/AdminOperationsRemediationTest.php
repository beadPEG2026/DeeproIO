<?php
namespace Tests\Feature\Deepro;

use Tests\TestCase;
use App\Models\User\User;
use App\Models\Support\{SupportMessage,SupportTicketEntry};
use App\Repositories\Deposit\DepositRepository;
use App\Repositories\Withdrawal\WithdrawalRepository;
use App\Support\AdminReportFilters;
use Illuminate\Support\Facades\{DB,Auth,Event,Queue,Mail,Http,Validator};
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class AdminOperationsRemediationTest extends TestCase
{
    private User $operator;
    protected function setUp():void {
        parent::setUp();
        $this->assertSame('deepro_test',DB::connection()->getDatabaseName());
        // The isolated database is reachable through the host port or Docker's internal port.
        $this->assertContains((string)config('database.connections.pgsql.port'), ['54329','5432','15485']);
        if ((string)config('database.connections.pgsql.port') === '15485') $this->assertSame('127.0.0.1', config('database.connections.pgsql.host'));
        config(['app.readonly'=>false,'cache.default'=>'array','session.driver'=>'array','broadcasting.default'=>'log','mail.default'=>'array']);
        DB::beginTransaction();Event::fake();Queue::fake();Mail::fake();Http::preventStrayRequests();
        $this->operator=User::role('superadmin')->firstOrFail();
        Auth::forgetGuards();$this->operator->withAccessToken(new \Laravel\Sanctum\TransientToken());$this->actingAs($this->operator,'web');
    }
    protected function tearDown():void {while(DB::transactionLevel()>0)DB::rollBack();parent::tearDown();}
    private function query(array $values):void {$this->app->instance('request',Request::create('/','GET',$values));}
    private function customer():User {return User::withoutEvents(fn()=>User::factory()->create(['email'=>'admin-qa-'.uniqid().'@example.test','phone'=>'13900006789','email_verified_at'=>now(),'is_xn'=>false]));}
    private function deposit(User $u,string $symbol,string $amount,string $date):int {
        $currency=DB::table('currencies')->where('symbol',$symbol)->first();
        return DB::table('deposits')->insertGetId(['user_id'=>$u->id,'deposit_id'=>'qa-'.uniqid(),'type'=>'coin','currency_id'=>$currency->id,'network_id'=>6,'amount'=>$amount,'address'=>'qa-address','txn'=>'qa-txn-'.uniqid(),'status'=>DEPOSIT_CONFIRMED,'created_at'=>$date,'updated_at'=>$date]);
    }
    public function test_report_period_and_page_size_are_validated():void {
        foreach([10,50,100,500] as $size){$this->query(['per_page'=>$size,'period'=>['2000-01-01','2000-01-02']]);$this->assertSame($size,AdminReportFilters::perPage());$this->assertSame(['2000-01-01','2000-01-02'],AdminReportFilters::period());}
        foreach([['period'=>'2000-01-01,2000-01-02'],['period'=>['bad','date']],['period'=>['2000-01-02','2000-01-01']],['period'=>['2000-01-01']],['per_page'=>99999]] as $bad){
            $this->query($bad);try{AdminReportFilters::perPage();AdminReportFilters::period();$this->fail('Invalid filter accepted');}catch(ValidationException $e){$this->assertNotEmpty($e->errors());}
        }
    }
    public function test_small_deposits_and_date_filtered_csv_match_the_list():void {
        $u=$this->customer();$ids=[];
        foreach(['TRX'=>'1','USDT'=>'0.3','USDC'=>'0.2'] as $symbol=>$amount)$ids[]=$this->deposit($u,$symbol,$amount,'2000-01-01 12:00:00');
        $outside=$this->deposit($u,'TRX','8','2001-01-01 12:00:00');
        $before=DB::table('deposits')->whereIn('id',[...$ids,$outside])->get()->toJson();
        $this->query(['user_id'=>$u->id,'period'=>['2000-01-01','2000-01-02'],'per_page'=>100]);
        $repo=new DepositRepository();$list=$repo->getReport();$this->assertSame(3,$list->total());$this->assertSame(100,$list->perPage());
        ob_start();$repo->exportReport()->sendContent();$csv=ob_get_clean();$rows=array_map('str_getcsv',array_filter(explode("\n",trim($csv))));
        $this->assertCount(4,$rows);$this->assertStringNotContainsString('2001-01-01',$csv);
        $this->assertSame($before,DB::table('deposits')->whereIn('id',[...$ids,$outside])->get()->toJson());
    }
    public function test_withdrawal_page_size_and_empty_date_export():void {
        $this->query(['period'=>['2000-01-01','2000-01-02'],'per_page'=>100]);$r=new WithdrawalRepository();$this->assertSame(100,$r->getReport()->perPage());
        ob_start();$r->exportReport()->sendContent();$csv=ob_get_clean();$this->assertCount(1,array_filter(explode("\n",trim($csv))));
    }
    public function test_user_search_supports_phone_and_public_wallet_address():void {
        $u=$this->customer();$wallet=DB::table('wallets')->insertGetId(['user_id'=>$u->id,'currency_id'=>2]);
        DB::table('wallet_addresses')->insert(['user_id'=>$u->id,'wallet_id'=>$wallet,'network_id'=>6,'address'=>'0xAdminQaUniquePublicAddress']);
        foreach(['13900006789','0xAdminQaUniquePublicAddress',(string)$u->id] as $term)$this->assertContains($u->id,User::filter(['search'=>$term])->pluck('id')->all());
        $this->assertSame(0,User::filter(['search'=>'nonexistent-qa-term'])->count());
    }
    public function test_monitor_rejects_stale_sample_and_uses_release_manifest():void {
        $path=tempnam(sys_get_temp_dir(),'admin-health-');
        try {
            file_put_contents($path,json_encode(['schema_version'=>2,'mode'=>'read_only','generated_at'=>now()->subMinutes(4)->toISOString(),'checks'=>[]]));
            $health=app(\App\Services\SystemMonitor\OperationsHealth::class)->summary($path);$this->assertSame('unknown',$health['status']);$this->assertFalse($health['fresh']);
            file_put_contents($path,json_encode(['tag'=>'qa-release-r1']));$this->assertSame('qa-release-r1',app(\App\Services\SystemMonitor\ReleaseInfo::class)->version($path));
        }finally{unlink($path);}
    }
    public function test_audit_detail_omits_unknown_fields_and_secrets():void {
        $view=new \App\Services\Custody\AuditView();$detail=$view->detail(json_encode(['chain'=>'tron','before'=>['enabled'=>false,'private_key'=>'not-a-real-secret'],'after'=>['enabled'=>true],'password'=>'not-a-real-secret']));
        $this->assertSame(['chain'=>'tron','before'=>['enabled'=>false],'after'=>['enabled'=>true]],$detail);
    }
    public function test_product_validation_rejects_inverted_limits_dates_and_invalid_lists():void {
        $c=DB::table('currencies')->value('id');$n=DB::table('networks')->value('id');
        $staking=new \App\Http\Requests\Web\Staking\StakingFormRequest();
        $data=['reason'=>'Isolated validation regression','currency_id'=>$c,'allowed_days'=>'30,60','rewards_percentage'=>'0.5,1','min_amount'=>'10','max_amount'=>'5','status'=>'bad','staking_type'=>0];
        $v=Validator::make($data,$staking->rules());$this->assertTrue($v->fails());$this->assertArrayHasKey('max_amount',$v->errors()->messages());$data['max_amount']='20';$this->assertArrayHasKey('status',Validator::make($data,$staking->rules())->errors()->messages());
        $data['max_amount']='20';$data['status']='active';$this->assertFalse(Validator::make($data,$staking->rules())->fails());
        $data['allowed_days']='0,-1';$this->assertTrue(Validator::make($data,$staking->rules())->fails());
        $launch=new \App\Http\Requests\Web\Launchpad\LaunchpadFormRequest();
        $base=['name'=>'QA','description'=>'QA','currency_id'=>$c,'network_id'=>$n,'rate'=>'1','min_buy'=>'10','max_buy'=>'20','soft_cap'=>'100','hard_cap'=>'200','start_time'=>'2026-10-02','end_time'=>'2026-10-03','status'=>true];
        $this->assertFalse(Validator::make($base,$launch->rules())->fails());
        foreach(['max_buy'=>'2','hard_cap'=>'20','end_time'=>'2026-10-01'] as $field=>$bad){
            $v=Validator::make(array_replace($base,[$field=>$bad]),$launch->rules());$this->assertTrue($v->fails());$this->assertArrayHasKey($field,$v->errors()->messages());
        }
    }
    public function test_support_keeps_history_rejects_stale_edits_and_deduplicates():void {
        $u=$this->customer();$ticket=SupportMessage::create(['user_id'=>$u->id,'title'=>'QA ticket','body'=>'QA body','reply'=>'Original reply','replied_at'=>now(),'status'=>'replied','ticket_id'=>'Q'.uniqid()]);
        $url='/exchange-control-panel/support-tickets/'.$ticket->id.'/reply';
        $payload=['reply'=>'Second reply','request_key'=>(string)\Illuminate\Support\Str::uuid(),'revision'=>0,'status'=>'replied','priority'=>'high','assigned_to'=>$this->operator->id,'due_at'=>'2026-10-01 12:00:00'];
        $this->postJson($url,$payload)->assertRedirect();$this->assertSame(2,$ticket->entries()->count());
        $this->postJson($url,$payload)->assertRedirect();$this->assertSame(2,$ticket->entries()->count());
        $payload['request_key']=(string)\Illuminate\Support\Str::uuid();$this->postJson($url,$payload)->assertStatus(422)->assertJsonValidationErrors('revision');
        $payload['revision']=1;$payload['reply']='';$payload['status']='closed';$this->postJson($url,$payload)->assertRedirect();
        $this->assertSame('closed',$ticket->fresh()->status);$this->assertSame('Second reply',$ticket->fresh()->reply);$this->assertSame(3,$ticket->entries()->count());Mail::assertNothingSent();
    }
    public function test_customer_cannot_manage_tickets():void {
        $u=$this->customer();$u->assignRole('user');Auth::forgetGuards();$u->withAccessToken(new \Laravel\Sanctum\TransientToken());$this->actingAs($u,'web');
        $this->getJson('/exchange-control-panel/support-tickets')->assertForbidden();
    }
    public function test_first_deposit_filter_and_dashboard_default_period():void {
        $u=$this->customer();$first=$this->deposit($u,'TRX','1','2000-01-01 12:00:00');$this->deposit($u,'TRX','2','2000-01-02 12:00:00');
        $this->query(['user_id'=>$u->id,'first_only'=>1,'period'=>['2000-01-01','2000-01-03']]);
        $rows=(new DepositRepository())->getReport();$this->assertSame(1,$rows->total());$this->assertSame($first,$rows->first()->id);
        $period=(new \App\Repositories\Report\ReportRepository())->dashboardReportPeriod();$this->assertSame(now()->startOfDay()->format('Y-m-d H:i:s'),$period[0]);
    }
    public function test_support_notes_do_not_send_or_replace_replies_and_mail_uses_snapshot():void {
        $u=$this->customer();$ticket=SupportMessage::create(['user_id'=>$u->id,'title'=>'QA','body'=>'QA','reply'=>'Old reply','status'=>'replied','ticket_id'=>'Q'.uniqid()]);
        $payload=['reply'=>'Internal investigation','internal_note'=>true,'request_key'=>(string)\Illuminate\Support\Str::uuid(),'revision'=>0,'status'=>'replied','priority'=>'normal'];
        $this->postJson('/exchange-control-panel/support-tickets/'.$ticket->id.'/reply',$payload)->assertRedirect();
        $note=$ticket->entries()->where('kind','note')->firstOrFail();$this->assertSame('not_requested',$note->mail_status);$this->assertSame('Old reply',$ticket->fresh()->reply);
        (new \App\Jobs\Support\DeliverSupportReply($note->id))->handle();Mail::assertNothingSent();
        $entry=SupportTicketEntry::create(['support_message_id'=>$ticket->id,'actor_id'=>$this->operator->id,'request_key'=>(string)\Illuminate\Support\Str::uuid(),'kind'=>'reply','body'=>'Immutable body','mail_status'=>'queued']);
        $job=new \App\Jobs\Support\DeliverSupportReply($entry->id);$job->handle();$job->handle();
        Mail::assertSent(\App\Mail\Support\SupportReply::class,fn($m)=>$m->replyBody==='Immutable body');Mail::assertSentCount(1);
        $this->assertSame('smtp_accepted',$entry->fresh()->mail_status);
        $entry->refresh()->update(['mail_status'=>'queued']);$job->failed(new \RuntimeException('simulated transport failure'));$this->assertSame('failed',$entry->fresh()->mail_status);
    }
    public function test_umi_account_search_pages_without_changing_ledger():void {
        $service=app(\App\Services\Umi\Business\Portfolio::class);$method=new \ReflectionMethod($service,'accountList');$method->setAccessible(true);
        $all=$method->invoke($service,'',1);$this->assertSame(30,$all->perPage());$this->assertLessThanOrEqual(30,$all->count());
        $this->assertSame(0,$method->invoke($service,'qa-nonexistent-code',1)->total());
    }

    public function test_umi_compound_filters_include_level_zero_and_keep_results_scoped():void {
        $method=new \ReflectionMethod(app(\App\Services\Umi\Business\Portfolio::class),'accountList');$method->setAccessible(true);
        $service=app(\App\Services\Umi\Business\Portfolio::class);$prefix='QA-FILTER-'.uniqid();
        foreach([[0,null,false],[1,null,false],[0,null,true],[0,$this->operator->id,false]] as $i=>$parts){
            // Only bind a fresh test account; never change historical relations.
            $user=$parts[1]===null?null:$this->customer()->id;
            DB::table('umi_business_accounts')->insert(['user_id'=>$user,'code'=>$prefix.$i,'level'=>$parts[0],'reward_excluded'=>$parts[2],'created_at'=>now(),'updated_at'=>now()]);
        }
        $rows=$method->invoke($service,$prefix,1,['level'=>'0','binding'=>'unbound','rewards'=>'included']);
        $this->assertSame(1,$rows->total());$this->assertSame($prefix.'0',$rows->first()->code);
        $this->assertSame(4,$method->invoke($service,$prefix,1,[])->total());
        // Once the funded schema is ready, legacy business URLs become read-only archive redirects.
        $this->assertTrue(\App\Services\Umi\V2\FundedRuntime::schemaReady());
        $this->getJson('/exchange-control-panel/umi/business?level=10')->assertRedirect(route('admin.umi.operations',['level'=>10,'tab'=>'history']));
        $this->getJson('/exchange-control-panel/umi/business?binding=anything')->assertRedirect(route('admin.umi.operations',['binding'=>'anything','tab'=>'history']));
    }
    public function test_history_pagination_preserves_full_totals_and_fingerprint():void {
        $rows=[];for($i=1;$i<=65;$i++)$rows[]=['legacy_id'=>$i,'status'=>$i<=60?'ready':'review','issues'=>[]];
        $report=['accounts'=>65,'ready'=>60,'opening_umi'=>'12345.678','fingerprint'=>str_repeat('a',64),'rows'=>$rows];
        $service=new \App\Support\UmiReportPage();$request=Request::create('/umi/continuity','GET',['result'=>'ready','rows_page'=>2]);
        $actual=$service->apply($report,['result'=>'ready','rows_page'=>2],'continuity',$request);
        $this->assertSame(60,$actual['rows']->total());$this->assertCount(30,$actual['rows']);$this->assertSame(31,$actual['rows']->items()[0]['legacy_id']);
        foreach(['accounts','ready','opening_umi','fingerprint'] as $key)$this->assertSame($report[$key],$actual[$key]);
        $this->assertStringContainsString('result=ready',$actual['rows']->previousPageUrl());
        $this->assertCount(65,$report['rows']);
        $actual=$service->apply($report,['search'=>'65','result'=>'review'],'continuity',$request);$this->assertSame(1,$actual['rows']->total());
        $shadow=['accounts'=>2,'rows'=>[['uid'=>1,'checks'=>['rank'=>true,'today'=>false]],['uid'=>2,'checks'=>['rank'=>true,'today'=>true]]]];
        $actual=$service->apply($shadow,['result'=>'review'],'shadow',$request);$this->assertSame(1,$actual['rows']->total());$this->assertSame(1,$actual['rows']->items()[0]['uid']);
    }
    public function test_audit_filter_export_snapshot_redaction_and_csv_formula_protection():void {
        $asset=DB::table('currencies')->where('symbol','USDT')->first();$network=DB::table('networks')->where('slug','bep20')->first();
        $transfer=DB::table('custody_transfers')->insertGetId(['key'=>'qa-'.uniqid(),'purpose'=>'sweep','chain'=>'bnb','currency_id'=>$asset->id,'network_id'=>$network->id,'sender'=>'qa','destination'=>'qa','amount'=>'1','max_fee'=>'0.01','confirmations'=>15,'created_at'=>'2000-01-01 12:00:00','updated_at'=>'2000-01-01 12:00:00']);
        $ids=[];
        foreach([
            ['action'=>'transfer.created','transfer_id'=>$transfer,'detail'=>json_encode(['private_key'=>'secret-sentinel','purpose'=>'sweep'])],
            ['action'=>'rule.saved','transfer_id'=>null,'detail'=>json_encode(['after'=>['currency_id'=>$asset->id,'network_id'=>$network->id],'private_key'=>'secret-sentinel'])],
            ['action'=>'=FAKE()','transfer_id'=>null,'detail'=>json_encode(['chain'=>'bnb','currency_id'=>$asset->id])],
            ['action'=>'network.updated','transfer_id'=>null,'detail'=>json_encode(['chain'=>'tron'])],
        ] as $r)$ids[]=DB::table('custody_audits')->insertGetId($r+['actor_id'=>$this->operator->id,'created_at'=>'2000-01-01 23:59:59']);
        DB::table('custody_audits')->insert(['action'=>'outside','actor_id'=>$this->operator->id,'detail'=>'{}','created_at'=>'2000-01-02 00:00:00']);
        $service=new \App\Services\Custody\AuditQuery();$filters=['audit_start'=>'2000-01-01','audit_end'=>'2000-01-01','audit_chain'=>'bnb','audit_currency'=>$asset->id,'audit_actor'=>$this->operator->id];
        $list=$service->query($filters)->orderBy('a.id')->get();$this->assertCount(3,$list);$this->assertSame(array_slice($ids,0,3),$list->pluck('id')->all());
        $before=DB::table('custody_transfers')->find($transfer);
        ob_start();$service->export($filters)->sendContent();$csv=ob_get_clean();
        $lines=array_map('str_getcsv',array_filter(explode("\n",trim($csv))));$this->assertCount(4,$lines);
        $this->assertStringNotContainsString('secret-sentinel',$csv);$this->assertStringContainsString("'=FAKE()",$csv);$this->assertStringNotContainsString('2000-01-02',$csv);
        $this->assertEquals($before,DB::table('custody_transfers')->find($transfer));
        $this->getJson('/exchange-control-panel/custody/audits/export?audit_chain=invalid')->assertUnprocessable()->assertJsonValidationErrors('audit_chain');
    }
    public function test_customer_cannot_export_custody_audits():void {
        $u=$this->customer();$u->assignRole('user');Auth::forgetGuards();$u->withAccessToken(new \Laravel\Sanctum\TransientToken());$this->actingAs($u,'web');
        $this->getJson('/exchange-control-panel/custody/audits/export')->assertForbidden();
    }

}
