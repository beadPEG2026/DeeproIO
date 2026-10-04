<?php
namespace Tests\Feature\Deepro;

use App\Models\User\User;
use App\Services\Umi\Business\{Amount,Engine,Ledger,ReleaseSchedule,Rules,Settlement,UserOperations};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\{DB,Route};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class UmiRebuildTest extends TestCase {
    use DatabaseTransactions, \Tests\Support\UmiSponsorFixture;
    private Engine $e;
    protected function setUp():void {
        parent::setUp();$this->assertSame('umi_regression',DB::connection()->getDatabaseName());config(['umi-business.enabled'=>true,'umi-business.funded_live'=>false]);$this->e=app(Engine::class);
        $this->travelTo(\Carbon\Carbon::parse('2026-09-18 10:00:00','Asia/Shanghai'));
        if(!Route::has('admin.umi.business'))Route::middleware('web')->group(base_path('routes/admin.php'));
        if(!Route::has('umi.finance.preview'))Route::middleware('web')->group(base_path('routes/common.php'));
        Route::getRoutes()->refreshNameLookups();
        $this->withSession(['_token'=>'umi-rebuild'])->withHeader('X-CSRF-TOKEN','umi-rebuild');
    }
    private function key():string{return (string)Str::uuid();}
    private function eq(string $x,string $y):void{$this->assertSame(0,Amount::cmp($x,$y),"$x != $y");}
    private function account():object {
        $u=User::factory()->create();$u->assignRole('user');$this->e->enroll($u->id,null,$this->key(),true);$a=$this->e->owned($u->id);$this->e->fund($a->id,'1000','UMI',$this->key());return $a;
    }
    private function plan(object $a):object{$this->e->purchase($a->id,$a->user_id,'100',$this->key());return DB::table('umi_business_plans')->where('account_id',$a->id)->first();}
    private function counts():array {return array_map(fn($t)=>DB::table($t)->count(),['umi_business_entries','umi_business_operations','umi_business_plans','umi_business_rewards','umi_custody_transfers','umi_release_revisions','umi_level_history','umi_business_rules']);}
    private function revision(object $a,object $p,array $over=[]):array{return array_replace(['target_type'=>'plan','target_id'=>$p->id,'account_id'=>$a->id,'expected_revision'=>0,'daily_rate'=>'0.01','status'=>'active','reason'=>'按已取得原计划资料核定该单日释放比例'],$over);}
    private function advance():string{$d=app(ReleaseSchedule::class)->effectiveDay();app(Settlement::class)->advance(0,$d,$this->key());return $d;}

    public function test_plan_adjustment_preserves_originals_and_pause_resume_has_no_catchup():void {
        $a=$this->account();$p=$this->plan($a);$s=app(ReleaseSchedule::class);$day=$s->effectiveDay();
        $s->revise(0,$this->revision($a,$p),$this->key());$this->eq('0.008',DB::table('umi_business_plans')->find($p->id)->daily_rate);
        $this->eq('0.008',$s->resolve('plan',$p,\Carbon\CarbonImmutable::parse($day)->subDay()->toDateString())['daily_rate']);
        $this->advance();$this->eq('1',DB::table('umi_business_plans')->find($p->id)->released);
        $revision=$s->resolve('plan',$p,$s->effectiveDay())['revision_id'];$s->revise(0,$this->revision($a,$p,['expected_revision'=>$revision,'status'=>'paused']),$this->key());$this->advance();
        $this->eq('1',DB::table('umi_business_plans')->find($p->id)->released);
        $revision=$s->resolve('plan',$p,$s->effectiveDay())['revision_id'];$s->revise(0,$this->revision($a,$p,['expected_revision'=>$revision,'daily_rate'=>'0.008']),$this->key());$this->advance();
        $this->eq('1.8',DB::table('umi_business_plans')->find($p->id)->released);$this->assertTrue($this->e->ledger->audit()['ok']);
    }
    public function test_stale_foreign_and_invalid_release_edits_are_rejected_without_writes():void {
        $a=$this->account();$b=$this->account();$p=$this->plan($a);$s=app(ReleaseSchedule::class);$key=$this->key();$v=$this->revision($a,$p);
        $one=$s->revise(0,$v,$key);$this->assertSame($one['id'],$s->revise(0,$v,$key)['id']);$before=$this->counts();
        $revision=$s->resolve('plan',$p,$s->effectiveDay())['revision_id'];
        foreach([$v,array_replace($v,['account_id'=>$b->id]),array_replace($v,['expected_revision'=>$revision,'daily_rate'=>'1.01'])] as $bad){try{$s->revise(0,$bad,$this->key());$this->fail('Invalid revision succeeded');}catch(ValidationException $ex){}}
        $this->assertSame($before,$this->counts());
    }
    public function test_continuity_overlay_never_rewrites_snapshot_or_invents_missing_opening():void {
        $a=$this->account();$this->plan($a);$snapshot='{"original_daily":"0.8"}';
        DB::table('umi_continuity_openings')->insert(['account_id'=>$a->id,'legacy_id'=>999001,'batch_id'=>$this->key(),'cutoff'=>'2026-09-16','status'=>'ready','snapshot'=>$snapshot,'quota_total'=>'0','quota_used'=>'0','daily_release'=>'0.8','created_at'=>now()]);
        $s=app(ReleaseSchedule::class);$s->revise(0,['target_type'=>'continuity','target_id'=>$a->id,'account_id'=>$a->id,'expected_revision'=>0,'status'=>'paused','daily_amount'=>'0.8','reason'=>'核对历史接续日量期间暂停新日期释放'],$this->key());
        $o=DB::table('umi_continuity_openings')->where('account_id',$a->id)->first();$this->assertSame($snapshot,$o->snapshot);$this->eq('0.8',$o->daily_release);$this->assertSame('ready',$o->status);
        $this->advance();$this->assertSame(0,DB::table('umi_business_rewards')->where('kind','linear')->whereNull('plan_id')->count());
    }
    public function test_purchase_preview_runs_real_checks_but_rolls_back_every_record():void {
        $a=$this->account();$before=$this->counts();$q=app(UserOperations::class)->preview($a->user_id,['action'=>'purchase','amount'=>'100']);
        $this->assertFalse($q['committed']);$this->eq('300',$q['plans'][0]->quota);$this->eq('0.8',$q['plans'][0]->daily_amount);$this->eq('-100',$q['changes'][0]['delta']);
        $this->assertSame($before,$this->counts());$this->eq('1000',$this->e->ledger->balance($a->id,'main'));
        try{app(UserOperations::class)->preview($a->user_id,['action'=>'purchase','amount'=>'2000']);$this->fail();}catch(ValidationException $ex){}
        $this->assertSame($before,$this->counts());
    }
    public function test_native_claim_preview_rolls_back_nested_fee_and_wallet_writes():void {
        config(['umi-business.funded_live'=>true]);$u=User::factory()->create();$this->e->enroll($u->id,$this->umiSponsorCode(),$this->key());$a=$this->e->owned($u->id);
        $this->e->run($a->id,0,'test_setup',[],$this->key(),fn($op)=>$this->e->ledger->move($op,'system:issuance',Ledger::bucket($a->id,'treasure'),'100','UMI','测试期初'));
        $before=$this->counts();$q=app(UserOperations::class)->preview($u->id,['action'=>'claim_to_wallet','amount'=>'10','pocket'=>'treasure']);
        $this->eq('3',$q['fees']['UMI']);$this->eq('7',$q['wallet_changes']['UMI']);$this->assertSame($before,$this->counts());$this->eq('100',$this->e->ledger->balance($a->id,'treasure'));
        $cid=DB::table('currencies')->where('symbol','UMI')->value('id');$this->eq('0',DB::table('wallets')->where('user_id',$u->id)->where('currency_id',$cid)->value('balance_in_wallet'));
    }
    public function test_stale_preview_is_blocked_but_successful_retry_survives_rule_change():void {
        $a=$this->account();$s=app(UserOperations::class);$v=['action'=>'stake','amount'=>'10'];$q=$s->preview($a->user_id,$v);$v['expected_rule_id']=$q['rule_id'];$key=$this->key();$first=$s->execute($a->user_id,$v,$key);
        app(Rules::class)->update(['vip_fee'=>'0.1'],'调整后旧成功请求应返回原操作结果',0);$this->assertSame($first['id'],$s->execute($a->user_id,$v,$key)['id']);
        try{$s->execute($a->user_id,$v,$this->key());$this->fail();}catch(ValidationException $ex){}
        $this->eq('10',$this->e->ledger->balance($a->id,'vip'));
    }
    public function test_new_rule_version_does_not_reprice_overdue_business_day():void {
        config(['umi-business.funded_live'=>true]);$u=User::factory()->create();$this->e->enroll($u->id,$this->umiSponsorCode(),$this->key());$a=$this->e->owned($u->id);$old=app(Rules::class)->current()['id'];
        $this->e->manage(0,'quota',['account_id'=>$a->id,'amount'=>'1000','direction'=>'add','reason'=>'测试独立账户的收益额度与历史日结'],$this->key());
        $this->e->run($a->id,0,'test_setup',[],$this->key(),fn($op)=>$this->e->ledger->move($op,'system:issuance','system:distribution','1000','UMI','测试收益池'));
        DB::table('umi_business_state')->where('id',1)->update(['business_date'=>'2026-09-16']);
        app(Rules::class)->update(['treasure_daily_rate'=>'0.01'],'新日利率从当前业务日期生效不改旧日',0);
        $this->assertSame($old,app(Rules::class)->forDay('2026-09-17')['id']);$this->eq('0.01',app(Rules::class)->forDay('2026-09-18')['rules']['treasure_daily_rate']);
        app(Settlement::class)->advance(0,'2026-09-17',$this->key());$this->assertSame($old,DB::table('umi_business_days')->where('day','2026-09-17')->value('rule_id'));
    }
    public function test_tier_and_rank_configuration_validation_is_atomic():void {
        $this->account();$r=app(Rules::class);$before=$r->current();$tiers=$before['rules']['tiers'];$tiers[1]['min']='1999';
        foreach([['tiers'=>$tiers],['vip_thresholds'=>['10','10','51','101']],['ranks'=>[]]] as $bad){try{$r->update($bad,'无效档位配置不得覆盖已生效的规则',0);$this->fail();}catch(ValidationException $ex){}}
        $this->assertSame($before['id'],$r->current()['id']);$r->update(['vip_thresholds'=>['11','22','52','102']],'明确配置新的质押档位并保存规则版本',0);$this->eq('11',$r->current()['rules']['vip_thresholds'][0]);
    }
    public function test_level_transitions_record_basis_and_are_append_only():void {
        $a=$this->account();$this->plan($a);$v=['account_id'=>$a->id,'manual_level'=>3,'reward_excluded'=>false,'reason'=>'按照原用户等级资料设置当前手动下限'];$this->e->manage(0,'level',$v,$this->key());
        $h=DB::table('umi_level_history')->where('account_id',$a->id)->first();$this->assertSame(0,$h->before_level);$this->assertSame(3,$h->after_level);$this->eq('123',$h->personal);
        $this->e->manage(0,'level',$v,$this->key());$this->assertSame(1,DB::table('umi_level_history')->where('account_id',$a->id)->count());
        try{DB::transaction(fn()=>DB::table('umi_level_history')->where('id',$h->id)->update(['after_level'=>9]));$this->fail();}catch(\Illuminate\Database\QueryException $ex){}
        $this->assertSame(3,DB::table('umi_level_history')->find($h->id)->after_level);
    }
    public function test_preview_uses_authenticated_owner_and_admin_controls_remain_restricted():void {
        $a=$this->account();$b=$this->account();$this->actingAs(User::find($a->user_id));$before=$this->counts();
        $this->postJson(route('umi.finance.preview'),['action'=>'stake','amount'=>'10','account_id'=>$b->id])->assertUnprocessable()->assertJsonValidationErrors('action');$this->assertSame($before,$this->counts());
        $preview=app(\App\Services\Umi\Business\UserOperations::class)->preview($a->user_id,['action'=>'stake','amount'=>'10','account_id'=>$b->id]);
        $this->assertFalse($preview['committed']);$this->assertSame($before,$this->counts());
        $vip=collect($preview['changes'])->firstWhere('pocket','vip');$this->eq('10',$vip['delta']);
        $this->eq('0',$this->e->ledger->balance($a->id,'vip'));$this->eq('0',$this->e->ledger->balance($b->id,'vip'));
        $this->postJson(route('admin.umi.business.submit'),['action'=>'release_revision','request_key'=>$this->key()])->assertForbidden();
    }
}
