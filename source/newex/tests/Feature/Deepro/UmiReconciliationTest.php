<?php
namespace Tests\Feature\Deepro;

use App\Models\{Umi\LegacyAccount,User\User};
use App\Services\Umi\{LegacyIntegrity,LegacyReconciliation,LegacyRelations};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\{DB,Crypt,Route};
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class UmiReconciliationTest extends TestCase
{
    use DatabaseTransactions;
    private int $batch;
    protected function setUp():void {
        parent::setUp();$this->assertSame('umi_regression',DB::connection()->getDatabaseName());
        if(!Route::has('admin.umi.reconciliation'))Route::middleware('web')->group(base_path('routes/admin.php'));
        $this->batch=DB::table('umi_import_batches')->insertGetId(['fingerprint'=>hash('sha256',uniqid('umi-review',true)),'summary'=>'{}','created_at'=>now()]);
    }
    private function account(int $id=910001,?int $parent=919999,array $overrides=[]):LegacyAccount {
        $i=['id'=>$id,'uuid'=>'umi-review-'.$id,'nickname'=>'Review fixture '.$id,'level'=>'V2','status'=>2];
        $p=array_replace_recursive(['id'=>$id,'uuid'=>$i['uuid'],'inviter'=>['user_id'=>$parent],
            'quota_summary'=>['total_quota'=>'100.000000000000000000000001','used_quota'=>'10'],
            'dapp_balance_umi'=>'6','performance'=>['manual_level'=>'V2','exclude_from_rewards'=>true]],$overrides);
        return LegacyAccount::create(['legacy_id'=>$id,'legacy_uuid'=>$i['uuid'],'parent_legacy_id'=>$parent,'level'=>'V2','legacy_status'=>2,'batch_id'=>$this->batch,'identity'=>$i,'profile'=>$p,'source_hash'=>hash('sha256',json_encode([$i,$p],JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION))]);
    }
    private function summary(int $id=910001,array $overrides=[]):void {
        $v=array_replace(['总额度(UMI)'=>'100.000000000000000000000001','已用额度(UMI)'=>'10','用户购买额度(UMI)'=>'60','管理员赠送额度(UMI)'=>'40',
            '总利润(UMI)'=>'10','直推利润(UMI)'=>'1','团队利润(UMI)'=>'2','理财利润(UMI)'=>'3','UMI宝利润(UMI)'=>'4',
            'DApp可提资产(UMI)'=>'6','[核查]主余额(UMI)'=>'1','[核查]理财收益余额(UMI)'=>'1','[核查]团队收益余额(UMI)'=>'1','[核查]直推收益余额(UMI)'=>'1','[核查]复投宝本金(UMI)'=>'2'],$overrides);
        $raw=json_encode($v,JSON_UNESCAPED_UNICODE);DB::table('umi_legacy_summaries')->insert(['legacy_id'=>$id,'payload'=>Crypt::encryptString($raw),'source_hash'=>hash('sha256',$raw)]);
    }
    private function record(string $source,int $id,array $values=[]):void {
        $v=array_replace(['id'=>$id,'user_id'=>910001,'created_at'=>'2026-09-01 00:00:00'],$values);$raw=json_encode($v,JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION);
        DB::table('umi_legacy_records')->insert(['batch_id'=>$this->batch,'source'=>$source,'source_id'=>(string)$id,'legacy_id'=>$v['user_id'],'payload'=>Crypt::encryptString($raw),'source_hash'=>hash('sha256',$raw)]);
    }
    private function check(array $report,string $code):array {return collect($report['checks'])->firstWhere('code',$code);}

    public function test_original_projection_is_checked_even_when_encrypted_payload_hash_is_unchanged():void {
        $a=$this->account();$this->assertSame([],app(LegacyIntegrity::class)->account($a));
        $a->parent_legacy_id=910999;$a->level='V9';$errors=app(LegacyIntegrity::class)->account($a);
        $this->assertContains('parent_legacy_id',$errors);$this->assertContains('level',$errors);$this->assertNotContains('source_hash',$errors);
    }
    public function test_original_relations_and_history_are_protected_at_database_boundary():void {
        $a=$this->account();$this->summary();$this->record('burn__records',910001,['burn_amount'=>'1']);$before=$a->fresh()->getRawOriginal();
        foreach([
            fn()=>DB::table('umi_legacy_accounts')->where('legacy_id',910001)->update(['parent_legacy_id'=>1]),
            fn()=>DB::table('umi_legacy_accounts')->where('legacy_id',910001)->update(['level'=>'V9']),
            fn()=>DB::table('umi_legacy_accounts')->where('legacy_id',910001)->delete(),
            fn()=>DB::table('umi_legacy_records')->where('legacy_id',910001)->delete(),
            fn()=>DB::table('umi_legacy_summaries')->where('legacy_id',910001)->update(['source_hash'=>str_repeat('0',64)]),
            fn()=>DB::statement('TRUNCATE umi_legacy_accounts CASCADE'),
            fn()=>DB::statement('TRUNCATE umi_legacy_records'),
            fn()=>DB::statement('TRUNCATE umi_source_files'),
        ] as $operation){try{DB::transaction($operation);$this->fail('Protected history changed');}catch(\Illuminate\Database\QueryException $e){$this->assertMatchesRegularExpression('/umi_original_account_immutable|umi_snapshot_append_only/',$e->getMessage());}}
        $this->assertSame($before,$a->fresh()->getRawOriginal());
        $a->update(['approved_email'=>'review@example.test']);$this->assertSame('review@example.test',$a->fresh()->approved_email);
    }
    public function test_high_precision_differences_are_reported_without_recrediting_or_rewriting():void {
        $a=$this->account();$this->summary();$before=$a->fresh()->getRawOriginal();$wallets=DB::table('wallets')->count();
        $r=app(LegacyReconciliation::class)->report(910001);
        $this->assertSame('difference',$this->check($r,'quota.sources')['state']);
        $this->assertSame('0.000000000000000000000001',$this->check($r,'quota.sources')['delta']);
        foreach(['quota.used_profit','profit.components','asset.components','quota.csv_total','quota.csv_used','asset.csv_total'] as $code)$this->assertSame('matched',$this->check($r,$code)['state']);
        $this->assertFalse($r['funds_credited']);$this->assertFalse($r['settlement_enabled']);$this->assertSame($before,$a->fresh()->getRawOriginal());$this->assertSame($wallets,DB::table('wallets')->count());
        $this->assertSame($r['snapshot_fingerprint'],app(LegacyReconciliation::class)->report(910001)['snapshot_fingerprint']);
    }
    public function test_missing_fields_and_non_decimal_values_are_not_coerced_to_zero():void {
        $this->account();$this->summary(910001,['[核查]复投宝本金(UMI)'=>null,'总利润(UMI)'=>'1e1']);
        $r=app(LegacyReconciliation::class)->report(910001);
        $this->assertSame('missing',$this->check($r,'asset.components')['state']);$this->assertNull($this->check($r,'asset.components')['delta']);
        $this->assertSame('invalid',$this->check($r,'quota.used_profit')['state']);
    }
    public function test_deleted_burn_record_is_preserved_and_recomputed_using_its_own_multiplier():void {
        $this->account();$this->record('burn__records',910101,['burn_amount'=>'10','token_price'=>'1.23','burn_value_usdt'=>'12.3','multiplier'=>'5','quota_granted'=>'50','status'=>3]);
        $r=app(LegacyReconciliation::class)->report(910001);
        $this->assertSame('matched',$this->check($r,'burn.quota')['state']);$this->assertSame('matched',$this->check($r,'burn.valuation')['state']);
        $this->assertSame('difference',$this->check($r,'burn.tier_exception')['state']);$this->assertSame(1,$r['deleted_burn_records']);
        $this->assertSame('notice',$this->check($r,'burn.deleted')['state']);
    }
    public function test_usdt_views_match_by_balances_with_original_times_retained():void {
        $this->account();$v=['amount'=>'-2.00','balance_before'=>'5','balance_after'=>'3'];
        $this->record('team-usdt-flow',910102,$v);$this->record('usdt__transactions',910103,array_replace($v,['amount'=>'2','created_at'=>'2026-09-01 00:00:35']));
        $r=app(LegacyReconciliation::class)->report(910001);
        $this->assertSame('matched',$this->check($r,'flow.usdt_overlap')['state']);$this->assertSame('910103',$this->check($r,'flow.usdt_overlap')['expected']);
        $this->assertSame('notice',$this->check($r,'flow.time_difference')['state']);$this->assertSame(2,DB::table('umi_legacy_records')->where('legacy_id',910001)->count());
    }
    public function test_ambiguous_usdt_rows_are_never_auto_merged():void {
        $this->account();$v=['amount'=>'2','balance_before'=>'5','balance_after'=>'3'];
        $this->record('team-usdt-flow',910104,$v);$this->record('usdt__transactions',910105,$v);$this->record('usdt__transactions',910106,$v);
        $r=app(LegacyReconciliation::class)->report(910001);$this->assertSame('ambiguous',$this->check($r,'flow.usdt_overlap')['state']);$this->assertNull($this->check($r,'flow.usdt_overlap')['expected']);
    }
    public function test_team_tree_stays_within_original_subtree_and_keeps_external_parent():void {
        $root=$this->account();$child=$this->account(910002,910001);$this->account(910003,910002);$this->account(910004,919999);
        $r=app(LegacyRelations::class)->forAccount($root);$this->assertSame([910002,910003],array_column($r['rows'],'id'));$this->assertSame([1,2],array_column($r['rows'],'depth'));
        $this->assertSame([['id'=>919999,'in_scope'=>false]],$r['ancestors']);$this->assertFalse($r['rewards_recalculated']);$this->assertSame(1,$r['known_direct']);
        $r=app(LegacyRelations::class)->forAccount($child);$this->assertSame([910003],array_column($r['rows'],'id'));$this->assertSame([910001,919999],array_column($r['ancestors'],'id'));
        $this->assertArrayNotHasKey('email',$r['rows'][0]);$this->assertArrayNotHasKey('profile',$r['rows'][0]);
    }
    public function test_cycle_does_not_loop_or_include_owner_as_own_descendant():void {
        $a=$this->account(910001,910002);$this->account(910002,910001);$r=app(LegacyRelations::class)->forAccount($a);
        $this->assertTrue($r['cycle_detected']);$this->assertSame([910002],array_column($r['rows'],'id'));
        $this->assertSame('invalid',$this->check(app(LegacyReconciliation::class)->report(910001),'team.path')['state']);
    }
    public function test_relation_pagination_has_no_duplicates_or_scope_expansion():void {
        $a=$this->account();for($id=910010;$id<910033;$id++)$this->account($id,910001);
        $s=app(LegacyRelations::class);$one=$s->forAccount($a);$two=$s->forAccount($a,2);
        $this->assertCount(20,$one['rows']);$this->assertCount(3,$two['rows']);$this->assertSame(23,$one['known_descendants']);$this->assertSame([],array_intersect(array_column($one['rows'],'id'),array_column($two['rows'],'id')));
    }
    public function test_report_and_download_require_superadmin_and_are_not_public_cacheable():void {
        $this->account();$user=User::factory()->create();$user->assignRole(Role::findOrCreate('user','web'));
        $this->actingAs($user)->getJson('/exchange-control-panel/umi/reconciliation?format=json')->assertForbidden();
        $admin=User::factory()->create();$admin->assignRole(Role::findOrCreate('superadmin','web'));
        $r=$this->actingAs($admin)->get('/exchange-control-panel/umi/reconciliation?legacy_id=910001&format=json')->assertOk()->assertJsonPath('read_only',true)->assertJsonPath('accounts',1);
        $this->assertStringContainsString('no-store',$r->headers->get('Cache-Control'));$this->assertStringContainsString('attachment',$r->headers->get('Content-Disposition'));
        $this->get('/exchange-control-panel/umi/reconciliation?legacy_id=910001')->assertOk()->assertInertia(fn($page)=>$page->component('Admin/Umi/Reconciliation')->where('report.accounts',1));
    }
    public function test_normal_user_cannot_select_another_umi_tree_with_query_parameters():void {
        $owner=User::factory()->create();$owner->assignRole(Role::findOrCreate('user','web'));$a=$this->account();$a->update(['user_id'=>$owner->id]);$this->account(910002,910001);$this->account(910003,919999);
        $target=route('umi.portfolio',['tab'=>'history','legacy_section'=>'team','page'=>1]);
        $this->actingAs($owner)->get('/umi-ecosystem/account?section=team&legacy_id=910003')->assertRedirect($target);
        $this->get($target)->assertOk()->assertInertia(fn($p)=>$p->component('Umi/FundedHome')->where('legacy.id',910001)->where('legacy.team.known_descendants',1)->where('legacy.team.rows.0.id',910002));
    }
}
