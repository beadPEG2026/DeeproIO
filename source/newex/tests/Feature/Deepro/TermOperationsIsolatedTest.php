<?php
namespace Tests\Feature\Deepro;
use Tests\TestCase;
use Illuminate\Support\Facades\{DB,Schema,Event};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use App\Models\{Staking\Staking,User\User,Staking\StakingUser};
use App\Services\Staking\{FundedTermProduct,TermOperations};

final class TermOperationsIsolatedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();config(['app.key'=>'base64:'.base64_encode(str_repeat('t',32)),'database.default'=>'term_ops_memory','database.connections.term_ops_memory'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>'','foreign_key_constraints'=>true],'app.timezone'=>'Europe/Madrid','app.readonly'=>false]);
        DB::purge('term_ops_memory');self::assertSame(':memory:',DB::connection()->getDatabaseName());Event::fake();Carbon::setTestNow(Carbon::parse('2026-10-02 12:00:00','Europe/Madrid'));
        Schema::create('users',function(Blueprint $t){$t->id();$t->boolean('is_xn')->default(false);$t->boolean('deleted')->default(false);$t->boolean('deactivated')->default(false);});
        Schema::create('currencies',function(Blueprint $t){$t->id();$t->string('symbol');});
        Schema::create('wallets',function(Blueprint $t){$t->id();$t->integer('user_id');$t->integer('currency_id');$t->string('balance_in_trade');$t->timestamps();});
        Schema::create('staking',function(Blueprint $t){$t->id();$t->integer('currency_id');$t->string('allowed_days');$t->string('rewards_percentage');$t->string('min_amount');$t->string('max_amount');$t->string('status');$t->integer('staking_type');$t->timestamps();});
        Schema::create('settings',function(Blueprint $t){$t->string('key')->primary();$t->text('value');});
        Schema::create('deposits',function(Blueprint $t){$t->id();$t->integer('user_id');});
        Schema::create('deposit_review_events',function(Blueprint $t){$t->id();$t->integer('deposit_id');$t->string('reason');$t->string('status');});
        Schema::create('operations_events',function(Blueprint $t){$t->id();$t->string('request_key')->unique();$t->integer('actor_id')->nullable();$t->string('object_type');$t->string('object_id');$t->string('action');$t->text('reason')->nullable();$t->text('changes');$t->timestamp('created_at');});
        require_once database_path('migrations/2022_01_28_060408_create_staking_users.php');(new \CreateStakingUsers)->up();
        Schema::table('staking_users',fn(Blueprint $t)=>$t->json('meta')->nullable());
        (require database_path('migrations/2026_09_21_050000_staking_reward_receipts.php'))->up();
        (require database_path('migrations/deepro/2026_10_02_221000_staking_settlement_tasks.php'))->up();
        DB::table('users')->insert(['id'=>1]);
        foreach([1=>'BTC',2=>'ETH'] as $id=>$symbol){DB::table('currencies')->insert(compact('id','symbol'));DB::table('wallets')->insert(['id'=>$id,'user_id'=>1,'currency_id'=>$id,'balance_in_trade'=>'2']);DB::table('staking')->insert(['id'=>$id,'currency_id'=>$id,'allowed_days'=>'30','rewards_percentage'=>'1','min_amount'=>'0.01','max_amount'=>'2','status'=>'active','staking_type'=>0]);DB::table('settings')->insert(['key'=>'staking.configuration.'.$id,'value'=>json_encode(['funded_term'=>true,'reward_funding'=>'platform','reward_user_id'=>null,'pool_limit'=>'10'])]);}
    }
    protected function tearDown(): void {Carbon::setTestNow();DB::disconnect('term_ops_memory');parent::tearDown();}
    private function subscribe(int $product=1,string $amount='1'): StakingUser {return app(FundedTermProduct::class)->subscribe(Staking::findOrFail($product),User::findOrFail(1),$amount,30);}
    public function test_platform_products_subscribe_without_reward_reserve_and_report_separate_currencies(): void
    {
        $a=$this->subscribe();$b=$this->subscribe(2,'0.5');$report=app(TermOperations::class)->report([]);$t=collect($report['totals'])->keyBy('currency_id');
        self::assertSame(0,bccomp($t[1]['active_principal'],'1',24));self::assertSame(0,bccomp($t[1]['platform_reward_due'],'0.01',24));self::assertSame(0,bccomp($t[2]['platform_reward_due'],'0.005',24));
        self::assertSame(0,bccomp($t[1]['reserved_reward'],'0',24));self::assertSame('Europe/Madrid',$report['timezone']);self::assertSame('2026-10-03',$a->value_date->toDateString());self::assertSame(2,$report['positions']->total());
        self::assertSame(1,app(TermOperations::class)->report(['currency_id'=>2])['positions']->total());self::assertFalse(Schema::hasTable('umi_v2_members'));
    }
    public function test_failed_maturity_is_recorded_and_retry_pays_exactly_once(): void
    {
        $s=$this->subscribe();Carbon::setTestNow($s->redemption_date->copy());$wallet=DB::table('wallets')->find(1);DB::table('wallets')->where('id',1)->delete();$service=app(TermOperations::class);
        try{$service->settle($s->id,1,'核对缺失的钱包后重试到期兑付');self::fail('Missing wallet must reject');}catch(\RuntimeException $e){self::assertSame('funded_staking_wallet_missing',$e->getMessage());}
        self::assertSame('failed',DB::table('staking_settlement_tasks')->first()->status);self::assertSame('active',$s->fresh()->status);self::assertSame(1,$service->report(['failed'=>true])['positions']->total());
        DB::table('wallets')->insert((array)$wallet);self::assertTrue($service->settle($s->id,1,'已恢复对应钱包并核对兑付金额'));self::assertFalse($service->settle($s->id,1,'重复核对同一到期订单的完成状态'));
        self::assertSame(0,bccomp((string)DB::table('wallets')->find(1)->balance_in_trade,'2.01',18));self::assertSame('resolved',DB::table('staking_settlement_tasks')->first()->status);
        $total=$service->report([])['totals'][0];self::assertSame(0,bccomp($total['platform_reward_due'],'0',24));self::assertSame(0,bccomp($total['platform_reward_expense'],'0.01',24));self::assertSame(0,$service->report(['failed'=>true])['positions']->total());
    }
    public function test_due_buckets_do_not_add_accrual_twice_and_risk_hold_blocks_new_subscription(): void
    {
        $s=$this->subscribe();Carbon::setTestNow($s->redemption_date->copy()->subDays(2));app(TermOperations::class)->settle($s->id);$t=app(TermOperations::class)->report([])['totals'][0];
        self::assertSame(0,bccomp($t['matured_payable'],'0',24));self::assertSame(0,bccomp($t['due_7d_payable'],'1.01',24));self::assertGreaterThan(0,bccomp($t['accrued_reward'],'0',24));
        DB::table('deposits')->insert(['id'=>1,'user_id'=>1]);DB::table('deposit_review_events')->insert(['deposit_id'=>1,'reason'=>'DEPOSIT_POST_CREDIT_CHAIN_CONFLICT','status'=>'open']);
        try{$this->subscribe(2);self::fail('Risk hold must block funding');}catch(\Illuminate\Validation\ValidationException){}
        self::assertSame(0,bccomp((string)DB::table('wallets')->find(2)->balance_in_trade,'2',18));self::assertSame(1,StakingUser::count());
    }
    public function test_filtered_export_has_a_matching_hash_and_persists_actor_audit(): void
    {
        $this->subscribe();$this->subscribe(2,'0.5');
        $request=\Illuminate\Http\Request::create('/exchange-control-panel/staking/operations/export','GET',['currency_id'=>1]);$request->setUserResolver(fn()=>User::findOrFail(1));
        $response=app(\App\Http\Controllers\Web\Admin\StakingOperationsController::class)->export($request,app(TermOperations::class));
        ob_start();$response->sendContent();$body=ob_get_clean();
        self::assertSame(hash('sha256',$body),$response->headers->get('X-Content-SHA256'));
        $event=DB::table('operations_events')->where('object_type','funded_staking_export')->first();$details=json_decode($event->changes,true);
        self::assertSame(1,$details['count']);self::assertSame(1,(int)$event->actor_id);self::assertSame(hash('sha256',$body),$details['sha256']);self::assertSame(1,$details['filters']['currency_id']);
        self::assertCount(2,array_values(array_filter(explode("\n",trim($body)))));
    }

}
