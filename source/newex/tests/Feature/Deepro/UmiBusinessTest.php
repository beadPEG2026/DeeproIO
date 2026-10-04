<?php
namespace Tests\Feature\Deepro;
use App\Models\User\User;
use App\Services\Umi\Business\{Amount,Engine,Ledger,Rules,Settlement,Team,UserOperations};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\{DB,Route};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class UmiBusinessTest extends TestCase {
    use DatabaseTransactions;
    private Engine $engine;
    protected function setUp():void {parent::setUp();$this->assertSame('umi_regression',DB::connection()->getDatabaseName());config(['umi-business.enabled'=>true,'umi-business.funded_live'=>false]);$this->engine=app(Engine::class);if(!Route::has('admin.umi.business'))Route::middleware('web')->group(base_path('routes/admin.php'));$this->withSession(['_token'=>'umi-business'])->withHeader('X-CSRF-TOKEN','umi-business');}
    private function key():string{return (string)Str::uuid();}
    private function account(?object $parent=null):object {$u=User::factory()->create();$u->assignRole('user');$this->engine->enroll($u->id,$parent?->code,$this->key(),true);return $this->engine->owned($u->id);}
    private function fund(object $a,string $amount='10000',string $asset='UMI'):void {$this->engine->fund($a->id,$amount,$asset,$this->key());}
    private function buy(object $a,string $amount='100'):array{return $this->engine->purchase($a->id,$a->user_id,$amount,$this->key());}
    private function advance():string {$d=\Carbon\CarbonImmutable::parse(DB::table('umi_business_state')->value('business_date'))->addDay()->toDateString();app(Settlement::class)->advance(0,$d,$this->key());return $d;}
    private function eq(string $expected,string $actual):void{$this->assertSame(0,Amount::cmp($expected,$actual),"Expected $expected got $actual");}
    private function action(object $a,string $action,string $amount,array $more=[]):array{return $this->engine->action($a->id,$a->user_id,$action,['amount'=>$amount]+$more,$this->key());}
    private function level(object $a,int $level):void {$this->engine->manage(0,'level',['account_id'=>$a->id,'manual_level'=>$level,'reward_excluded'=>false,'reason'=>'本地独立验收手动等级规则'],$this->key());}

    public function test_purchase_snapshots_precision_and_duplicate_requests_do_not_debit_twice():void {
        $a=$this->account();$this->fund($a);$key=$this->key();$first=$this->engine->purchase($a->id,$a->user_id,'100.000000000000000001',$key);$again=$this->engine->purchase($a->id,$a->user_id,'100.000000000000000001',$key);
        $this->assertSame($first['id'],$again['id']);$this->assertTrue($again['replayed']);$this->eq('9899.999999999999999999',$this->engine->ledger->balance($a->id,'main'));
        $p=DB::table('umi_business_plans')->where('account_id',$a->id)->first();$this->eq('300.000000000000000003',$p->quota);$this->eq('0.800000000000000000008',$p->daily_amount);
        $this->assertTrue($this->engine->ledger->audit()['ok']);
        $this->expectException(ValidationException::class);$this->engine->purchase($a->id,$a->user_id,'101',$key);
    }
    public function test_insufficient_funds_roll_back_operation_plan_quota_and_every_entry():void {
        $a=$this->account();$counts=[DB::table('umi_business_operations')->count(),DB::table('umi_business_entries')->count()];
        try{$this->buy($a);$this->fail();}catch(ValidationException $e){}
        $this->assertSame($counts,[DB::table('umi_business_operations')->count(),DB::table('umi_business_entries')->count()]);$this->eq('0',$this->engine->account($a->id)->quota_total);
    }
    public function test_direct_referral_requires_preexisting_quota_and_is_capped_at_event_time():void {
        $parent=$this->account();$child=$this->account($parent);$this->fund($parent);$this->fund($child,'20000');
        $this->buy($child);$this->eq('0',$this->engine->ledger->balance($parent->id,'referral'));
        $this->buy($parent);$this->buy($child,'4000');$this->eq('300',$this->engine->ledger->balance($parent->id,'referral'));
        $this->buy($child,'100');$this->eq('300',$this->engine->account($parent->id)->quota_used);
        $this->advance();$this->eq('0',$this->engine->ledger->balance($parent->id,'treasure'));$this->assertTrue($this->engine->ledger->audit()['ok']);
    }
    public function test_daily_linear_compounding_and_order_rate_versions_are_correct():void {
        $a=$this->account();$this->fund($a);$this->buy($a);
        $this->advance();$this->eq('.8',$this->engine->ledger->balance($a->id,'treasure'));
        app(Rules::class)->update(['purchase_daily_rate'=>'0.01'],'本地验收新单费率只对未来认购生效',0);
        $this->advance();$this->eq('1.6008',$this->engine->ledger->balance($a->id,'treasure'));
        $this->buy($a);$this->advance();$this->eq('3.4024008',$this->engine->ledger->balance($a->id,'treasure'));
        $this->eq('3.4024008',$this->engine->account($a->id)->quota_used);$this->eq('0',$this->engine->ledger->balance($a->id,'linear'));
    }
    public function test_daily_retry_and_out_of_order_dates_cannot_reissue_rewards():void {
        $a=$this->account();$this->fund($a);$this->buy($a);$d=\Carbon\CarbonImmutable::parse(DB::table('umi_business_state')->value('business_date'))->addDay()->toDateString();$k=$this->key();$s=app(Settlement::class);$s->advance(0,$d,$k);$this->assertTrue($s->advance(0,$d,$k)['replayed']);
        $this->expectException(ValidationException::class);$s->advance(0,$d,$this->key());
    }
    public function test_gifts_add_quota_and_linear_but_not_personal_or_referral():void {
        $p=$this->account();$a=$this->account($p);$this->fund($p);$this->buy($p);
        $this->engine->purchase($a->id,0,'100',$this->key(),['multiplier'=>'3','note'=>'本地验收赠送配置和业绩隔离']);
        $this->eq('0',$this->engine->account($a->id)->personal);$this->eq('300',$this->engine->account($a->id)->quota_total);$this->eq('0',$this->engine->ledger->balance($p->id,'referral'));$this->advance();$this->eq('.8',$this->engine->ledger->balance($a->id,'treasure'));
    }
    public function test_tree_small_area_rank_and_source_excluded_differential():void {
        $root=$this->account();$left=$this->account($root);$leaf=$this->account($left);$right=$this->account($root);
        foreach([$root,$left,$leaf,$right] as $a){$this->fund($a);$this->buy($a,$a->id===$root->id?'100':'1000');}
        $r=$this->engine->account($root->id);$this->eq('3690',$r->team);$this->eq('1230',$r->small_area);$this->assertSame(1,$r->level);
        $this->level($root,3);$this->level($left,1);$this->level($leaf,9);$this->advance();
        $rewards=DB::table('umi_business_rewards')->where('kind','team')->where('source_account_id',$leaf->id)->get()->keyBy('account_id');
        $this->eq('.8',$rewards[$left->id]->paid);$this->eq('1.6',$rewards[$root->id]->paid);
    }
    public function test_peer_candidate_is_nonrecursive_and_nearest_equal_only():void {
        $top=$this->account();$mid=$this->account($top);$bottom=$this->account($mid);
        foreach([$top,$mid,$bottom] as $a){$this->fund($a);$this->buy($a);$this->level($a,4);}
        $this->advance();$p=DB::table('umi_business_rewards')->where('kind','peer')->where('source_account_id',$bottom->id)->get();$this->assertCount(1,$p);$this->assertSame($mid->id,$p[0]->account_id);$this->eq('.08',$p[0]->paid);
        $topPeer=DB::table('umi_business_rewards')->where('kind','peer')->where('account_id',$top->id)->first();$this->eq('1.112',$topPeer->paid); // mid: 0.8 linear + 0.32 team + 10 referral; no peer recursion
    }
    public function test_reserve_fee_insufficiency_rolls_back_and_points_are_separate():void {
        $a=$this->account();$this->fund($a);$this->action($a,'treasure_deposit','100');app(Rules::class)->update(['treasure_fee_source'=>'reserve'],'本地验收储备金扣费及积分计算',0);
        try{$this->action($a,'treasure_withdraw','10');$this->fail();}catch(ValidationException $e){}
        $this->eq('100',$this->engine->ledger->balance($a->id,'treasure'));
        $this->action($a,'reserve_deposit','10');$this->action($a,'treasure_withdraw','10');$this->eq('7',$this->engine->ledger->balance($a->id,'reserve'));$this->eq('3',$this->engine->ledger->balance($a->id,'points','POINTS'));$this->eq('90',$this->engine->ledger->balance($a->id,'treasure'));$this->assertTrue($this->engine->ledger->audit()['ok']);
    }
    public function test_claim_fee_is_charged_once_and_treasure_withdraw_does_not_reuse_quota():void {
        $p=$this->account();$c=$this->account($p);foreach([$p,$c] as $a){$this->fund($a);$this->buy($a);} $this->action($p,'claim','10',['pocket'=>'referral']);$this->eq('9910',$this->engine->ledger->balance($p->id,'main'));
        $this->advance();$before=$this->engine->account($p->id)->quota_used;$this->action($p,'treasure_withdraw','0.8');$this->eq($before,$this->engine->account($p->id)->quota_used);$this->eq('9910.56',$this->engine->ledger->balance($p->id,'main'));
    }
    public function test_vip_unstake_preserves_unlock_time_and_fee_snapshot():void {
        $a=$this->account();$this->fund($a);$this->action($a,'stake','100');app(Rules::class)->update(['vip_fee'=>'0.02'],'本地验收解押费率和时间快照',0);$this->action($a,'unstake','100');app(Rules::class)->update(['vip_fee'=>'0.5','unstake_minutes'=>5000],'后续调整不得修改已经提交的解押',0);
        $this->advance();$this->eq('100',$this->engine->ledger->balance($a->id,'unstaking'));$this->advance();$this->eq('0',$this->engine->ledger->balance($a->id,'unstaking'));$this->eq('9998',$this->engine->ledger->balance($a->id,'main'));
    }
    public function test_price_quote_must_match_rule_version_and_swap_preserves_ledger():void {
        $a=$this->account();$this->fund($a,'123','USDT');$v=app(Rules::class)->current()['id'];$this->action($a,'swap','123',['asset'=>'USDT','quote_rule_id'=>$v]);$this->eq('100',$this->engine->ledger->balance($a->id,'main'));$this->eq('0',$this->engine->ledger->balance($a->id,'main','USDT'));
        app(Rules::class)->update(['price'=>'2'],'本地验收过期报价必须重新确认',0);
        try{$this->action($a,'swap','100',['asset'=>'UMI','quote_rule_id'=>$v]);$this->fail();}catch(ValidationException $e){}$this->eq('100',$this->engine->ledger->balance($a->id,'main'));$this->assertTrue($this->engine->ledger->audit()['ok']);
    }
    public function test_plan_pause_and_global_pause_with_audited_resume():void {
        $a=$this->account();$this->fund($a);$this->buy($a);$id=DB::table('umi_business_plans')->where('account_id',$a->id)->value('id');$this->engine->manage(0,'plan',['plan_id'=>$id,'status'=>'paused','reason'=>'暂停该计划以核对业务状态完整性'],$this->key());$this->advance();$this->eq('0',$this->engine->account($a->id)->quota_used);
        $this->engine->manage(0,'pause',['paused'=>true,'reason'=>'本地验收全局暂停资金业务保护'],$this->key());try{$this->buy($a);$this->fail();}catch(ValidationException $e){}
        $this->engine->manage(0,'pause',['paused'=>false,'reason'=>'本地验收恢复业务继续执行流程'],$this->key());$this->buy($a);
    }
    public function test_user_cannot_select_someone_elses_account_or_call_admin_writes():void {
        $a=$this->account();$b=$this->account();$this->fund($a);$this->fund($b);$this->actingAs(User::find($a->user_id));
        $before=DB::table('umi_business_entries')->count();
        $this->postJson('/umi-ecosystem/finance',['action'=>'stake','amount'=>'10','account_id'=>$b->id,'request_key'=>$this->key()])->assertUnprocessable()->assertJsonValidationErrors('action');
        $this->assertSame($before,DB::table('umi_business_entries')->count());
        // The archived engine still scopes historical operations to their authenticated owner.
        app(UserOperations::class)->execute($a->user_id,['action'=>'stake','amount'=>'10','account_id'=>$b->id],$this->key());
        $this->eq('10',$this->engine->ledger->balance($a->id,'vip'));$this->eq('0',$this->engine->ledger->balance($b->id,'vip'));
        $this->postJson('/exchange-control-panel/umi/business',['action'=>'settle','day'=>'2030-01-01','request_key'=>$this->key()])->assertForbidden();
    }
    public function test_guest_and_disabled_environment_are_denied_and_invalid_amounts_rejected():void {
        $this->get('/umi-ecosystem/finance')->assertRedirect();$a=$this->account();$this->fund($a);$this->actingAs(User::find($a->user_id));
        $before=DB::table('umi_business_entries')->count();
        foreach(['-1','1e9','NaN','0.0000000000000000000000001'] as $n){
            $this->postJson('/umi-ecosystem/finance',['action'=>'purchase','amount'=>$n,'request_key'=>$this->key()])->assertUnprocessable()->assertJsonValidationErrors('action');
            try{app(UserOperations::class)->execute($a->user_id,['action'=>'purchase','amount'=>$n],$this->key());$this->fail('Invalid historical amount accepted');}catch(ValidationException $e){}
        }
        $this->assertSame($before,DB::table('umi_business_entries')->count());
        config(['umi-business.enabled'=>false]);$this->get('/umi-ecosystem/finance')->assertRedirect(route('umi.portfolio',['tab'=>'history']));
        $this->postJson('/umi-ecosystem/finance',['action'=>'purchase','amount'=>'10','request_key'=>$this->key()])->assertStatus(503);
        $this->assertSame($before,DB::table('umi_business_entries')->count());
    }
    public function test_old_archive_and_exchange_wallets_are_untouched_by_full_cycle():void {
        $a=$this->account();
        $before=DB::selectOne("select md5(coalesce(string_agg(row_to_json(a)::text, '' order by legacy_id),'')) as h from umi_legacy_accounts a")->h;
        $wallets=DB::selectOne("select md5(coalesce(string_agg(row_to_json(w)::text, '' order by id),'')) as h from wallets w")->h;
        $this->fund($a);$this->buy($a);$this->advance();
        $this->assertSame($before,DB::selectOne("select md5(coalesce(string_agg(row_to_json(a)::text, '' order by legacy_id),'')) as h from umi_legacy_accounts a")->h);
        $this->assertSame($wallets,DB::selectOne("select md5(coalesce(string_agg(row_to_json(w)::text, '' order by id),'')) as h from wallets w")->h);
    }
    public function test_admin_rule_retry_is_idempotent_and_stale_editor_cannot_overwrite():void {
        $a=$this->account();$admin=User::factory()->create();$admin->assignRole('superadmin');$this->actingAs($admin);$version=app(Rules::class)->current()['id'];
        $v=['action'=>'rules','rules'=>['price'=>'2'],'reason'=>'验收规则并发编辑与请求重试防重保护','expected_rule_id'=>$version,'request_key'=>$this->key()];
        $count=DB::table('umi_business_rules')->count();$operations=DB::table('umi_business_operations')->count();
        $this->postJson('/exchange-control-panel/umi/business',$v)->assertStatus(410);
        $this->assertSame($count,DB::table('umi_business_rules')->count());$this->assertSame($operations,DB::table('umi_business_operations')->count());
        // Exercise the retained pre-cutover controller against a transactional pre-cutover schema.
        // Restoring the table in finally keeps the current deployment's retired endpoint closed.
        \Illuminate\Support\Facades\Schema::rename('umi_v2_rate_schedule','umi_v2_rate_schedule_pre_cutover_fixture');
        try {
            $this->postJson('/exchange-control-panel/umi/business',$v)->assertOk();$this->postJson('/exchange-control-panel/umi/business',$v)->assertOk();$this->assertSame($count+1,DB::table('umi_business_rules')->count());
            $v['request_key']=$this->key();$v['rules']['price']='3';$this->postJson('/exchange-control-panel/umi/business',$v)->assertUnprocessable();$this->eq('2',app(Rules::class)->current()['rules']['price']);
        } finally { \Illuminate\Support\Facades\Schema::rename('umi_v2_rate_schedule_pre_cutover_fixture','umi_v2_rate_schedule'); }
        $this->postJson('/exchange-control-panel/umi/business',$v)->assertStatus(410);$this->assertSame($count+1,DB::table('umi_business_rules')->count());
    }
    public function test_last_fraction_of_quota_is_not_reused_by_interest_or_next_day():void {
        $a=$this->account();$this->fund($a);$this->buy($a);$this->action($a,'treasure_deposit','1000');
        $this->engine->manage(0,'quota',['account_id'=>$a->id,'amount'=>'299.5','direction'=>'subtract','reason'=>'核查共享额度只剩半枚时的奖励截断'],$this->key());
        $this->advance();$this->eq('0.5',$this->engine->account($a->id)->quota_used);$this->eq('1000.5',$this->engine->ledger->balance($a->id,'treasure'));
        $this->advance();$this->eq('1000.5',$this->engine->ledger->balance($a->id,'treasure'));$this->assertTrue($this->engine->ledger->audit()['ok']);
    }
    public function test_transfer_preserves_value_and_invalid_destination_never_charges_fee():void {
        $a=$this->account();$b=$this->account();$this->fund($a,'100');app(Rules::class)->update(['transfer_fee'=>'0.02'],'验收内部转账扣费与目标账户校验',0);
        try{$this->action($a,'transfer','10',['recipient'=>'DOESNOTEXIST']);$this->fail();}catch(ValidationException $e){}
        $this->eq('100',$this->engine->ledger->balance($a->id,'main'));$this->action($a,'transfer','10',['recipient'=>$b->code]);$this->eq('90',$this->engine->ledger->balance($a->id,'main'));$this->eq('9.8',$this->engine->ledger->balance($b->id,'main'));$this->assertTrue($this->engine->ledger->audit()['ok']);
    }
    public function test_linked_original_account_cannot_select_a_new_parent():void {
        $parent=$this->account();$u=User::factory()->create();
        \App\Models\Umi\LegacyAccount::create(['legacy_id'=>998888,'legacy_uuid'=>'business-linked-legacy','parent_legacy_id'=>998887,'user_id'=>$u->id,'batch_id'=>999999,'source_hash'=>str_repeat('a',64),'level'=>'V3','legacy_status'=>1,'identity'=>[],'profile'=>[]]);
        try{$this->engine->enroll($u->id,$parent->code,$this->key());$this->fail();}catch(ValidationException $e){}
        $this->assertNull($this->engine->owned($u->id));$this->assertSame(998887,\App\Models\Umi\LegacyAccount::find(998888)->parent_legacy_id);
    }

    public function test_module_read_only_mode_blocks_writes_without_changing_original_wallet_routes():void {
        $admin=User::factory()->create();$admin->assignRole('superadmin');
        $this->actingAs($admin);$this->app['env']='staging';
        config(['umi-business.funded_live'=>false,'umi-business.enabled'=>false,'umi-business.cloud_read_only'=>true]);
        $before=DB::table('umi_business_operations')->count();
        $this->get('/umi-ecosystem/finance')->assertRedirect(route('umi.portfolio',['tab'=>'history']));
        $this->get('/exchange-control-panel/umi/business')->assertRedirect(route('admin.umi.operations',['tab'=>'history']));
        $this->postJson('/umi-ecosystem/finance',['action'=>'enroll','request_key'=>$this->key()])->assertStatus(503);
        $this->postJson('/exchange-control-panel/umi/business',['action'=>'settle','day'=>'2030-01-01','request_key'=>$this->key()])->assertStatus(410);
        $this->assertSame($before,DB::table('umi_business_operations')->count());
        config(['umi-business.cloud_read_only'=>false]);$this->assertFalse(app(Rules::class)->readable());
    }
}
