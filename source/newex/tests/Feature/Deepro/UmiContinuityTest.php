<?php
namespace Tests\Feature\Deepro;
use App\Models\Umi\LegacyAccount;
use App\Models\User\User;
use App\Services\Umi\{LegacyBinding,LegacyActivation};
use App\Services\Umi\Business\{Continuity,Engine,Settlement,CustodyTransfers,RewardCorrections,Amount};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\{DB,Crypt,Hash,Route};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
final class UmiContinuityTest extends TestCase {
    use DatabaseTransactions, \Tests\Support\UmiSponsorFixture;
    private Engine $engine;
    protected function setUp():void {parent::setUp();$this->assertSame('umi_regression',DB::connection()->getDatabaseName());config(['umi-business.enabled'=>true,'umi-business.funded_live'=>true]);$this->engine=app(Engine::class);$this->travelTo(\Carbon\Carbon::parse('2026-09-18 10:00:00','Asia/Shanghai'));if(!Route::has('admin.umi.continuity'))Route::middleware('web')->group(base_path('routes/admin.php'));$this->withSession(['_token'=>'continuity'])->withHeader('X-CSRF-TOKEN','continuity');}
    private function key():string{return (string)Str::uuid();}
    private function eq(string $a,string $b):void {$this->assertSame(0,Amount::cmp($a,$b),"$a != $b");}
    private function legacy(int $id,?int $parent=null,bool $csv=true):LegacyAccount {
        $identity=['id'=>$id,'uuid'=>'continuity-'.$id,'status'=>2,'level'=>'V1','nickname'=>'Test'];
        $profile=['id'=>$id,'uuid'=>$identity['uuid'],'inviter'=>['user_id'=>$parent],'quota_summary'=>['total_quota'=>'300','used_quota'=>'50'],'dapp_balance_umi'=>'110','performance'=>['personal_performance'=>'100','manual_level'=>'V1','current_level'=>'V1','exclude_from_rewards'=>false]];
        $batch=DB::table('umi_import_batches')->insertGetId(['fingerprint'=>hash('sha256',$this->key()),'summary'=>'{}','created_at'=>now()]);
        $a=LegacyAccount::create(['legacy_id'=>$id,'legacy_uuid'=>$identity['uuid'],'parent_legacy_id'=>$parent,'batch_id'=>$batch,'source_hash'=>hash('sha256',json_encode([$identity,$profile],JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION)),'level'=>'V1','legacy_status'=>2,'identity'=>$identity,'profile'=>$profile]);
        if($csv){$values=['总额度(UMI)'=>'300','已用额度(UMI)'=>'50','DApp可提资产(UMI)'=>'110','[核查]主余额(UMI)'=>'5','[核查]理财收益余额(UMI)'=>'2','[核查]团队收益余额(UMI)'=>'2','[核查]直推收益余额(UMI)'=>'1','[核查]复投宝本金(UMI)'=>'100','[核查]备用金(UMI)'=>'3','当前待释放理财收益(UMI)'=>'0.8','购买待释放理财收益(UMI)'=>'0.8','赠送待释放理财收益(UMI)'=>'0'];$raw=json_encode($values,JSON_UNESCAPED_UNICODE);DB::table('umi_legacy_summaries')->insert(['legacy_id'=>$id,'source_hash'=>hash('sha256',$raw),'payload'=>Crypt::encryptString($raw)]);}
        return $a;
    }
    private function import():array {$c=app(Continuity::class);$p=$c->preview();return $c->import(0,$p['fingerprint'],'按用户确认的 9 月 16 日历史快照接续',$this->key());}
    private function account(int $legacy):object {return DB::table('umi_business_accounts')->where('legacy_id',$legacy)->first();}
    private function bound(int $id):User {$u=User::factory()->create();$u->assignRole('user');LegacyAccount::find($id)->update(['user_id'=>$u->id,'activation_status'=>'activated']);app(Continuity::class)->attach($u->id);return $u;}
    public function test_empty_scheduler_does_not_block_the_historical_cutover():void {
        $u=User::factory()->create();$this->engine->enroll($u->id,$this->umiSponsorCode(),$this->key());
        DB::table('umi_business_state')->where('id',1)->update(['business_date'=>'2026-09-15']);
        $this->artisan('umi:settle-funded')->assertSuccessful();$this->assertSame(0,DB::table('umi_business_days')->count());
        $this->legacy(910001);$this->import();$this->assertSame(1,DB::table('umi_continuity_batches')->count());
    }
    public function test_opening_preserves_relations_usage_and_balances_without_replaying_history():void {
        $this->legacy(910001,910000,false);$this->legacy(910002,910001);$wallets=DB::table('wallets')->sum('balance_in_wallet');$this->import();
        $parent=$this->account(910001);$a=$this->account(910002);$this->assertNull($parent->user_id);$this->assertSame($parent->id,$a->parent_id);$this->assertSame(910000,$parent->legacy_parent_id);
        $this->eq('50',$a->quota_used);$this->eq('5',$this->engine->ledger->balance($a->id,'main'));$this->eq('100',$this->engine->ledger->balance($a->id,'treasure'));
        $this->assertSame(0,DB::table('umi_business_rewards')->count());$this->assertSame(0,DB::table('umi_business_plans')->count());$this->assertSame($wallets,DB::table('wallets')->sum('balance_in_wallet'));
        $this->eq('-100000000',DB::table('umi_business_balances')->where('bucket','system:issuance')->value('amount'));$this->assertTrue($this->engine->ledger->audit()['ok']);
    }
    public function test_retry_import_and_second_request_cannot_credit_opening_twice():void {
        $this->legacy(910001);$c=app(Continuity::class);$p=$c->preview();$key=$this->key();$one=$c->import(0,$p['fingerprint'],'重复请求应复用原接续结果而不入账',$key);$again=$c->import(0,$p['fingerprint'],'重复请求应复用原接续结果而不入账',$key);$this->assertSame($one['id'],$again['id']);
        $count=DB::table('umi_business_entries')->count();try{$this->import();$this->fail();}catch(ValidationException $e){}$this->assertSame($count,DB::table('umi_business_entries')->count());
    }
    public function test_preview_rolls_back_then_days_compound_from_original_opening():void {
        $this->legacy(910001);$this->import();$a=$this->account(910001);$before=DB::table('umi_business_entries')->count();
        $preview=app(Settlement::class)->preview(0,'2026-09-17');$this->eq('0.9',$preview['total']);$this->assertSame($before,DB::table('umi_business_entries')->count());$this->assertSame('2026-09-16',DB::table('umi_business_state')->value('business_date'));
        app(Settlement::class)->advance(0,'2026-09-17',$this->key());$this->eq('104.9',$this->engine->ledger->balance($a->id,'treasure'));$this->eq('50.9',$this->engine->account($a->id)->quota_used);
        $this->travelTo(\Carbon\Carbon::parse('2026-09-19 10:00:00','Asia/Shanghai'));app(Settlement::class)->advance(0,'2026-09-18',$this->key());$this->eq('105.8049',$this->engine->ledger->balance($a->id,'treasure'));$this->assertTrue($this->engine->ledger->audit()['ok']);
    }
    public function test_claim_and_native_credit_are_atomic_exact_and_idempotent():void {
        $this->travelTo(\Carbon\Carbon::parse('2026-09-17 10:00:00','Asia/Shanghai'));
        $this->legacy(910001);$this->import();$u=$this->bound(910001);$a=$this->account(910001);$w=DB::table('wallets')->where('user_id',$u->id)->where('currency_id',DB::table('currencies')->where('symbol','UMI')->value('id'))->first();$key=$this->key();
        $s=app(CustodyTransfers::class);$s->claimToWallet($u->id,'treasure','10',$key);$s->claimToWallet($u->id,'treasure','10',$key);
        $this->eq('7',DB::table('wallets')->where('id',$w->id)->value('balance_in_wallet'));$this->eq('90',$this->engine->ledger->balance($a->id,'treasure'));$this->eq('5',$this->engine->ledger->balance($a->id,'main'));$this->assertSame(1,DB::table('umi_custody_transfers')->where('wallet_id',$w->id)->count());
        DB::statement("CREATE FUNCTION pg_temp.reject_continuity_receipt() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''receipt_test_failure''; END'");DB::statement('CREATE TRIGGER test_continuity_receipt BEFORE INSERT ON umi_custody_transfers FOR EACH ROW EXECUTE FUNCTION pg_temp.reject_continuity_receipt()');
        try{$s->claimToWallet($u->id,'treasure','10',$this->key());$this->fail();}catch(\Illuminate\Database\QueryException $e){}
        $this->eq('90',$this->engine->ledger->balance($a->id,'treasure'));$this->eq('7',DB::table('wallets')->where('id',$w->id)->value('balance_in_wallet'));$this->assertTrue($this->engine->ledger->audit()['ok']);
    }
    public function test_claimed_umi_can_move_to_native_trading_account():void {
        $this->travelTo(\Carbon\Carbon::parse('2026-09-17 10:00:00','Asia/Shanghai'));
        $this->legacy(910001);$this->import();$u=$this->bound(910001);$cid=DB::table('currencies')->where('symbol','UMI')->value('id');
        app(CustodyTransfers::class)->claimToWallet($u->id,'treasure','10',$this->key());
        $this->actingAs($u)->postJson(route('wallets.api.transfer'),['currency_id'=>$cid,'amount'=>'7','direction'=>'to_trade'])->assertOk()->assertJson(['success'=>true]);
        $w=DB::table('wallets')->where('user_id',$u->id)->where('currency_id',$cid)->first();
        $this->eq('0',$w->balance_in_wallet);$this->eq('7',$w->balance_in_trade);$this->assertTrue($this->engine->ledger->audit()['ok']);
    }
    public function test_missing_summary_keeps_ancestor_and_creates_reviewable_unpaid_rewards():void {
        $this->legacy(910001,null,false);$this->legacy(910002,910001);$this->import();app(Settlement::class)->advance(0,'2026-09-17',$this->key());
        $parent=$this->account(910001);$pending=DB::table('umi_pending_rewards')->where('account_id',$parent->id)->first();$this->eq('0.08',$pending->amount);$this->eq('50',$this->engine->account($parent->id)->quota_used);
        app(RewardCorrections::class)->pay(0,[$pending->id],'已核对原账户额度及本次团队收益计算依据',$this->key());$this->eq('0.08',$this->engine->ledger->balance($parent->id,'team'));
        try{app(RewardCorrections::class)->pay(0,[$pending->id],'已经补发过的同一来源不得重复发放',$this->key());$this->fail();}catch(ValidationException $e){}$this->assertSame(1,DB::table('umi_reward_corrections')->count());$this->assertTrue($this->engine->ledger->audit()['ok']);
    }
    public function test_existing_wallet_binding_preserves_password_and_does_not_credit_twice():void {
        $this->legacy(910001);$this->import();$u=User::factory()->create();$u->assignRole('user');$a=LegacyAccount::find(910001);$a->update(['approved_email'=>$u->email]);$hash=$u->password;$id=$this->key();
        DB::table('umi_binding_challenges')->insert(['id'=>$id,'legacy_id'=>910001,'user_id'=>$u->id,'email_lookup'=>LegacyActivation::lookup($u->email),'code_hash'=>Hash::make('12345678'),'expires_at'=>now()->addMinutes(10),'created_at'=>now()]);
        $s=app(LegacyBinding::class);try{$s->complete($u,$id,'00000000');$this->fail();}catch(ValidationException $e){}$this->assertSame(1,DB::table('umi_binding_challenges')->find($id)->attempts);
        $s->complete($u,$id,'12345678');$this->assertSame($hash,$u->fresh()->password);$this->assertSame($u->id,$this->account(910001)->user_id);$this->assertSame(1,DB::table('umi_business_accounts')->where('legacy_id',910001)->count());
        try{$s->complete($u,$id,'12345678');$this->fail();}catch(ValidationException $e){}$this->assertTrue($this->engine->ledger->audit()['ok']);
    }
    public function test_changed_preview_and_user_admin_access_are_rejected():void {
        $this->legacy(910001);try{app(Continuity::class)->import(0,str_repeat('0',64),'过期或伪造的资料指纹不得用于权益入账',$this->key());$this->fail();}catch(ValidationException $e){}$this->assertSame(0,DB::table('umi_continuity_batches')->count());
        $u=User::factory()->create();$u->assignRole('user');$this->actingAs($u)->get('/exchange-control-panel/umi/continuity')->assertRedirect(route('admin.login'));$this->postJson('/exchange-control-panel/umi/business',['action'=>'continuity','fingerprint'=>app(Continuity::class)->preview()['fingerprint'],'reason'=>'越权用户不应能够登记历史余额','request_key'=>$this->key()])->assertForbidden();
    }
}
