<?php
namespace Tests\Feature\Deepro;
use Tests\TestCase;
use App\Services\Custody\{CustodyService,CustodyBridge,CustodyNetwork,ColdRuleService,CustodyAccess};
use App\Models\{User\User,Wallet\Wallet,Withdrawal\Withdrawal};
use Illuminate\Support\Facades\{DB,Event,Mail,Queue,Http,Crypt};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CustodyWorkflowTest extends TestCase {
    private string $from='0x1111111111111111111111111111111111111111';
    private string $to='0x2222222222222222222222222222222222222222';
    private array $asset;
    protected function setUp():void {
        parent::setUp();$this->assertSame('deepro_test',DB::connection()->getDatabaseName());DB::beginTransaction();
        if (!\Illuminate\Support\Facades\Schema::hasColumn('custody_networks','auto_sweep_scope')) (require database_path('migrations/2026_09_23_120000_custody_sweep_scope.php'))->up();
        Event::fake();Mail::fake();Queue::fake();Http::preventStrayRequests();config(['app.readonly'=>false,'cache.default'=>'array','session.driver'=>'array']);
        DB::table('custody_networks')->where('chain','bnb')->update(['enabled'=>true,'auto_sweep'=>false,'max_fee'=>'0.01','native_max_fee'=>null,'daily_gas_limit'=>'0.05']);
        \Setting::set('bnb.wallet',$this->from);\Setting::set('bnb.private_key','isolated-fixture-not-a-key');
        $c=DB::table('currencies')->where('symbol','BNB')->first();$n=DB::table('networks')->where('slug','bnb')->first();
        $this->asset=CustodyNetwork::asset($c->id,$n->id);
    }
    protected function tearDown():void {while(DB::transactionLevel()>0)DB::rollBack();parent::tearDown();}
    private function bridge(callable $fn):CustodyBridge { $m=\Mockery::mock(CustodyBridge::class);$m->shouldReceive('call')->andReturnUsing($fn);$this->app->instance(CustodyBridge::class,$m);return$m; }
    private function service():CustodyService {return app(CustodyService::class);}
    private function task(array $extra=[]):object {return $this->service()->create($this->asset,array_merge(['key'=>'test:'.Str::uuid(),'purpose'=>'sweep','sender'=>$this->from,'destination'=>$this->to,'amount'=>'2','status'=>'approved'],$extra));}
    private function prepared():array {return ['raw'=>'signed-fixture','txn'=>'fixture-hash','amount'=>'1.999','fee'=>'0.001','decimals'=>18,'raw_digest'=>'fixture-digest'];}
    private function signed(object $t):object {DB::table('custody_transfers')->where('id',$t->id)->update(['status'=>'confirming','signed_payload'=>Crypt::encryptString(json_encode('signed-fixture')),'txn'=>'fixture-hash','sent_amount'=>'1.999','prepared'=>json_encode($this->prepared()),'broadcast_at'=>now()->subMinutes(1)]);return DB::table('custody_transfers')->find($t->id);}
    public function test_disabled_network_and_mismatched_native_asset_are_rejected():void {
        try {CustodyNetwork::asset(2,$this->asset['network_id']);$this->fail('wrong native accepted');}catch(ValidationException $e) {$this->assertArrayHasKey('custody',$e->errors());}
        DB::table('custody_networks')->where('chain','bnb')->update(['enabled'=>false]);$this->expectException(ValidationException::class);$this->task();
    }
    public function test_idempotent_intent_cannot_change_recipient():void {
        $t=$this->task();$again=$this->task(['key'=>$t->key]);$this->assertSame($t->id,$again->id);
        $this->expectException(ValidationException::class);$this->task(['key'=>$t->key,'destination'=>'0x3333333333333333333333333333333333333333']);
    }
    public function test_blank_cold_address_is_saved_disabled_and_cannot_be_approved():void {
        DB::table('cold_storage')->where('currency_id',$this->asset['currency_id'])->where('network_id',$this->asset['network_id'])->delete();
        $id=app(ColdRuleService::class)->save(['currency_id'=>$this->asset['currency_id'],'network_id'=>$this->asset['network_id'],'address'=>'','cold_min_balance_amount'=>'10','cold_transfer_amount'=>'1','hot_reserve'=>'5','daily_limit'=>'3','status'=>true],144);
        $this->assertFalse(DB::table('cold_storage')->find($id)->status);$this->assertNull($this->service()->cold($id));
        $this->expectException(ValidationException::class);app(ColdRuleService::class)->approve($id,145);
    }
    public function test_approval_is_independent_and_cancellation_can_resume_without_signature():void {
        $t=$this->task(['status'=>'awaiting_approval','requested_by'=>144]);
        try{$this->service()->approve($t->id,144);$this->fail('self approval');}catch(ValidationException $e){$this->assertTrue(true);}
        $this->service()->approve($t->id,145);$this->service()->cancel($t->id,145);$this->service()->resume($t->id,145);
        $this->assertSame('awaiting_approval',DB::table('custody_transfers')->find($t->id)->status);
    }
    public function test_signature_is_durable_before_broadcast_and_timeout_never_resigns():void {
        $prepare=0;$broadcast=0;$t=$this->task();$this->bridge(function($chain,$action,$p)use(&$prepare,&$broadcast,$t){
            if($action==='prepare'){$prepare++;return $this->prepared();}
            if($action==='receipt')return ['state'=>'pending'];
            if($action==='broadcast'){$broadcast++;$r=DB::table('custody_transfers')->find($t->id);$this->assertSame('confirming',$r->status);$this->assertSame('signed-fixture',json_decode(Crypt::decryptString($r->signed_payload)));$this->assertSame('fixture-hash',$p['txn']);throw new \RuntimeException('CUSTODY_BROADCAST_UNCERTAIN');}
            throw new \RuntimeException('unexpected');
        });
        $this->service()->run($t->id);DB::table('custody_transfers')->where('id',$t->id)->update(['broadcast_at'=>now()->subMinutes(1)]);$this->service()->run($t->id);
        $this->assertSame(1,$prepare);$this->assertSame(2,$broadcast);$this->assertSame('confirming',DB::table('custody_transfers')->find($t->id)->status);
    }
    public function test_receipt_wrong_amount_stays_locked_and_confirmed_is_idempotent():void {
        $t=$this->signed($this->task());$amount='0.99';$this->bridge(function()use(&$amount){return ['state'=>'confirmed','txn'=>'fixture-hash','amount'=>$amount,'confirmations'=>20];});
        $this->service()->run($t->id);$this->assertSame('confirming',DB::table('custody_transfers')->find($t->id)->status);
        $amount='1.999';$this->service()->run($t->id);$this->service()->run($t->id);
        $this->assertSame('completed',DB::table('custody_transfers')->find($t->id)->status);$this->assertSame(1,DB::table('custody_audits')->where('transfer_id',$t->id)->where('action','transfer.completed')->count());
    }
    public function test_expired_or_nonfinal_failure_cannot_refund_or_resign():void {
        $t=$this->signed($this->task());$this->bridge(fn()=>['state'=>'expired']);$this->service()->run($t->id);
        $this->assertSame('review',DB::table('custody_transfers')->find($t->id)->status);
        $this->expectException(ValidationException::class);$this->service()->cancel($t->id,144);
    }
    private function withdrawal():array {
        $u=User::withoutEvents(fn()=>User::factory()->create(['email'=>'custody-'.Str::uuid().'@example.invalid','deleted'=>false,'deactivated'=>false,'is_xn'=>false]));
        $wallet=Wallet::firstOrCreate(['user_id'=>$u->id,'currency_id'=>$this->asset['currency_id']],['balance_in_wallet'=>'3','balance_in_withdraw'=>'2','balance_in_trade'=>0,'balance_in_order'=>0]);
        $wallet->balance_in_withdraw='2';$wallet->save();
        $w=Withdrawal::create(['withdrawal_id'=>(string)Str::uuid(),'user_id'=>$u->id,'currency_id'=>$this->asset['currency_id'],'network_id'=>$this->asset['network_id'],'amount'=>'2','fee'=>'0.001','address'=>$this->to,'type'=>'coin','status'=>WITHDRAWAL_WAITING_PROVIDER_APPROVAL,'fund_origin'=>'user']);
        $t=$this->signed($this->task(['purpose'=>'withdrawal','withdrawal_id'=>$w->id]));$w->source_id='custody:'.$t->id;$w->save();return [$wallet,$w,$t];
    }
    public function test_successful_withdrawal_debits_only_locked_balance_once():void {
        [$wallet,$w,$t]=$this->withdrawal();$this->bridge(fn()=>['state'=>'confirmed','txn'=>'fixture-hash','amount'=>'1.999','confirmations'=>20]);$this->service()->run($t->id);$this->service()->run($t->id);
        $row=DB::table('custody_transfers')->find($t->id);$this->assertContains($row->status,['failed','completed'],json_encode(['status'=>$row->status,'error'=>$row->last_error]));
        $this->assertEquals('0',$wallet->fresh()->balance_in_withdraw);$this->assertEquals('3',$wallet->fresh()->balance_in_wallet);$this->assertSame(WITHDRAWAL_CONFIRMED_BY_PROVIDER,$w->fresh()->status);
    }
    public function test_final_chain_failure_refunds_locked_balance_once():void {
        [$wallet,$w,$t]=$this->withdrawal();$this->bridge(fn()=>['state'=>'failed','final'=>true,'txn'=>'fixture-hash']);$this->service()->run($t->id);$this->service()->run($t->id);
        $row=DB::table('custody_transfers')->find($t->id);$this->assertContains($row->status,['failed','completed'],json_encode(['status'=>$row->status,'error'=>$row->last_error]));
        $this->assertEquals('0',$wallet->fresh()->balance_in_withdraw);$this->assertEquals('5',$wallet->fresh()->balance_in_wallet);$this->assertSame(WITHDRAWAL_FAILED,$w->fresh()->status);
    }
    public function test_other_signed_transaction_blocks_nonce_allocation():void {
        $this->signed($this->task());$t=$this->task();$m=\Mockery::mock(CustodyBridge::class);$m->shouldNotReceive('call');$this->app->instance(CustodyBridge::class,$m);$this->service()->run($t->id);$this->assertSame('approved',DB::table('custody_transfers')->find($t->id)->status);
    }
    public function test_custody_read_permission_does_not_bypass_two_factor_on_writes():void {
        $u=User::withoutEvents(fn()=>User::factory()->create(['email'=>'custody-role-'.Str::uuid().'@example.invalid','deleted'=>false,'deactivated'=>false,'is_leng'=>1]));$u->assignRole('superadmin');$this->actingAs($u);
        $this->assertTrue(CustodyAccess::allowed($u));$this->assertFalse(CustodyAccess::fresh(request()));
        $this->putJson('/exchange-control-panel/custody/networks/bnb',['enabled'=>true])->assertStatus(422);
        $u->is_leng=0;$u->save();$this->assertFalse(CustodyAccess::allowed($u));
    }
    public function test_fee_above_limit_is_not_broadcast():void {
        $t=$this->task();$this->bridge(function($chain,$action){$this->assertSame('prepare',$action);return array_replace($this->prepared(),['fee'=>'0.02']);});
        $this->service()->run($t->id);$r=DB::table('custody_transfers')->find($t->id);$this->assertSame('approved',$r->status);$this->assertNull($r->signed_payload);
    }
    public function test_cold_transfer_preserves_pending_withdrawals_and_hot_reserve():void {
        [$wallet,$w,$t]=$this->withdrawal();
        $id=DB::table('cold_storage')->insertGetId(['currency_id'=>$this->asset['currency_id'],'network_id'=>$this->asset['network_id'],'address'=>$this->to,'status'=>true,'cold_min_balance_amount'=>'8','cold_transfer_amount'=>'2','hot_reserve'=>'8','daily_limit'=>'4','approved_by'=>145,'approved_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
        $this->bridge(fn()=>['balance'=>'10']);$this->assertNull($this->service()->cold($id));
        DB::table('cold_storage')->where('id',$id)->update(['hot_reserve'=>'6']);$job=$this->service()->cold($id);$this->assertSame(0,bccomp('1.99',$job->amount,18));$this->assertSame('awaiting_approval',$job->status);
    }
    public function test_final_failed_sweep_retry_archives_old_signature():void {
        $t=$this->signed($this->task());$this->bridge(fn()=>['state'=>'failed','final'=>true,'txn'=>'fixture-hash']);$this->service()->run($t->id);$this->service()->resume($t->id,144);
        $this->assertSame(1,DB::table('custody_transfer_attempts')->where('transfer_id',$t->id)->count());$r=DB::table('custody_transfers')->find($t->id);$this->assertSame('awaiting_approval',$r->status);$this->assertNull($r->signed_payload);
    }

    public function test_cancellation_during_signing_cannot_reactivate_or_broadcast_task():void {
        $t=$this->task();
        $this->bridge(function($chain,$action)use($t){
            $this->assertSame('prepare',$action);
            $this->service()->cancel($t->id,145);
            return $this->prepared();
        });
        $this->service()->run($t->id);
        $r=DB::table('custody_transfers')->find($t->id);
        $this->assertSame('cancelled',$r->status);
        $this->assertNull($r->signed_payload);
        $this->assertNull($r->broadcast_at);
    }

    public function test_native_limit_is_separate_and_null_preserves_legacy_behavior():void {
        $legacy=$this->task();$this->assertSame(0,bccomp('0.01',$legacy->max_fee,18));
        DB::table('custody_networks')->where('chain','bnb')->update(['native_max_fee'=>'0.0001']);
        $native=$this->task();$this->assertSame(0,bccomp('0.0001',$native->max_fee,18));
        $n=$this->service()->network('bnb');
        $this->assertSame(0,bccomp('0.01',CustodyNetwork::feeCap($n,$this->to),18));
        $this->assertFalse($n->auto_sweep);
        $this->assertNull($native->signed_payload);
    }

    public function test_approval_tightens_fee_and_confirmations_without_signing():void {
        $t=$this->task(['status'=>'awaiting_approval','requested_by'=>144]);
        DB::table('custody_networks')->where('chain','bnb')->update(['native_max_fee'=>'0.0001','confirmations'=>30]);
        $this->service()->approve($t->id,145);$r=DB::table('custody_transfers')->find($t->id);
        $this->assertSame('approved',$r->status);$this->assertSame(0,bccomp('0.0001',$r->max_fee,18));
        $this->assertSame(30,$r->confirmations);$this->assertNull($r->signed_payload);$this->assertNull($r->txn);
        $audit=json_decode(DB::table('custody_audits')->where('transfer_id',$t->id)->where('action','transfer.approved')->value('detail'),true);
        $this->assertSame(0,bccomp('0.01',$audit['max_fee_before'],18));
    }

    public function test_approval_cannot_relax_original_fee_or_confirmation_limits():void {
        $t=$this->task(['status'=>'awaiting_approval','requested_by'=>144,'max_fee'=>'0.00005','confirmations'=>40]);
        DB::table('custody_networks')->where('chain','bnb')->update(['native_max_fee'=>'0.0001','confirmations'=>15]);
        $this->service()->approve($t->id,145);$r=DB::table('custody_transfers')->find($t->id);
        $this->assertSame(0,bccomp('0.00005',$r->max_fee,18));$this->assertSame(40,$r->confirmations);
    }

    public function test_enabling_automatic_sweeps_does_not_approve_existing_tasks():void {
        $t=$this->task(['status'=>'awaiting_approval']);
        DB::table('custody_networks')->where('chain','bnb')->update(['auto_sweep'=>true,'native_max_fee'=>'0.0001']);
        $m=\Mockery::mock(CustodyBridge::class);$m->shouldNotReceive('call');$this->app->instance(CustodyBridge::class,$m);
        $this->service()->run($t->id);$r=DB::table('custody_transfers')->find($t->id);
        $this->assertSame('awaiting_approval',$r->status);$this->assertNull($r->signed_payload);$this->assertNull($r->txn);
        $this->assertSame(0,bccomp('0.01',$r->max_fee,18));
    }

    public function test_native_cold_reserve_uses_native_cap_and_still_requires_approval():void {
        DB::table('custody_networks')->where('chain','bnb')->update(['max_fee'=>'100','native_max_fee'=>'0.0001']);
        DB::table('cold_storage')->where('currency_id',$this->asset['currency_id'])->where('network_id',$this->asset['network_id'])->delete();
        $id=DB::table('cold_storage')->insertGetId(['currency_id'=>$this->asset['currency_id'],'network_id'=>$this->asset['network_id'],'address'=>$this->to,'status'=>true,'cold_min_balance_amount'=>'2','cold_transfer_amount'=>'1','hot_reserve'=>'1','daily_limit'=>'1','approved_by'=>145,'approved_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
        $this->bridge(fn()=>['balance'=>'2.0001']);$t=$this->service()->cold($id);
        $this->assertNotNull($t);$this->assertSame(0,bccomp('1',$t->amount,18));
        $this->assertSame(0,bccomp('0.0001',$t->max_fee,18));$this->assertSame('awaiting_approval',$t->status);$this->assertNull($t->signed_payload);
    }

    public function test_retry_uses_native_cap_and_keeps_pending():void {
        $t=$this->task();$this->service()->cancel($t->id,145);
        DB::table('custody_networks')->where('chain','bnb')->update(['native_max_fee'=>'0.0001']);
        $this->service()->resume($t->id,145);$r=DB::table('custody_transfers')->find($t->id);
        $this->assertSame(0,bccomp('0.0001',$r->max_fee,18));$this->assertSame('awaiting_approval',$r->status);
    }

    public function test_token_gas_funding_budgets_native_fee_without_using_token_fee_twice():void {
        $u=User::withoutEvents(fn()=>User::factory()->create(['email'=>'custody-gas-'.Str::uuid().'@example.invalid','deleted'=>false,'deactivated'=>false]));
        $sender='0x3333333333333333333333333333333333333333';
        $wallet=Wallet::firstOrCreate(['user_id'=>$u->id,'currency_id'=>$this->asset['currency_id']],['balance_in_wallet'=>'0']);
        $address=new \App\Models\Wallet\WalletAddress();$address->forceFill(['wallet_id'=>$wallet->id,'user_id'=>$u->id,'network_id'=>5,'address'=>$sender,'private_key'=>'isolated-fixture-not-a-key'])->save();
        DB::table('currencies')->where('id',2)->update(['bep_contract'=>$this->to]);
        DB::table('custody_networks')->where('chain','bnb')->update(['native_max_fee'=>'0.0001','max_fee'=>'0.01','daily_gas_limit'=>'0.0101']);
        $asset=CustodyNetwork::asset(2,6);
        $t=$this->service()->create($asset,['key'=>'test:'.Str::uuid(),'purpose'=>'sweep','sender'=>$sender,'destination'=>$this->from,'amount'=>'2','wallet_address_id'=>$address->id,'status'=>'approved']);
        $this->assertSame(0,bccomp('0.01',$t->max_fee,18));
        $this->bridge(function($chain,$action){
            if($action==='prepare')throw new \RuntimeException('CUSTODY_INSUFFICIENT_GAS_OR_BALANCE');
            if($action==='estimate')return ['fee'=>'0.002','native_balance'=>'0'];
            $this->assertSame('balance',$action);return ['balance'=>'2','native_balance'=>'0'];
        });
        $this->service()->run($t->id);
        $gas=DB::table('custody_transfers')->where('key','gas:'.$t->id)->first();
        $this->assertNotNull($gas);$this->assertSame(0,bccomp('0.002',$gas->amount,18));
        $this->assertSame(0,bccomp('0.0001',$gas->max_fee,18));$this->assertNull($gas->signed_payload);
        $this->service()->run($t->id);$this->assertSame(1,DB::table('custody_transfers')->where('key','gas:'.$t->id)->count());
    }

    public function test_network_form_validates_caps_and_requires_fresh_two_factor():void {
        $u=User::withoutEvents(fn()=>User::factory()->create(['email'=>'custody-ui-'.Str::uuid().'@example.invalid','deleted'=>false,'deactivated'=>false,'is_leng'=>1,'two_factor_secret'=>encrypt('isolated-authenticator-fixture')]));
        $u->assignRole('superadmin');$this->actingAs($u);
        $payload=['enabled'=>true,'auto_sweep'=>false,'auto_sweep_scope'=>'all_verified','max_fee'=>'0.01','native_max_fee'=>'0.0001','daily_gas_limit'=>'0','confirmations'=>15];
        $this->putJson('/exchange-control-panel/custody/networks/bnb',$payload)->assertStatus(422);
        $this->bridge(function($chain,$action){$this->assertSame('validate',$action);return ['valid'=>true];});
        $this->withSession(['custody_verified'=>['user'=>$u->id,'until'=>time()+300]])->putJson('/exchange-control-panel/custody/networks/bnb',$payload)->assertRedirect();
        $n=$this->service()->network('bnb');$this->assertSame(0,bccomp('0.0001',$n->native_max_fee,18));$this->assertFalse($n->auto_sweep);$this->assertSame('all_verified',$n->auto_sweep_scope);
        $this->putJson('/exchange-control-panel/custody/networks/bnb',array_replace($payload,['auto_sweep_scope'=>'anything']))->assertUnprocessable();
        foreach(['0','-1','0.0000000000000000001','1e-3']as$invalid)$this->putJson('/exchange-control-panel/custody/networks/bnb',array_replace($payload,['native_max_fee'=>$invalid]))->assertUnprocessable();
        $this->assertSame(0,bccomp('0.0001',$this->service()->network('bnb')->native_max_fee,18));
    }

    public function test_operator_page_exposes_separate_caps_with_writes_locked():void {
        foreach (['admin.peerOrdersAppeals.getChat'=>'app/Modules/P2P/Routes/admin.php','admin.merchant.refunds'=>'app/Modules/Merchant/Routes/web.php'] as $name=>$file) if (!\Illuminate\Support\Facades\Route::has($name)) \Illuminate\Support\Facades\Route::middleware('web')->group(base_path($file));
        $u=User::withoutEvents(fn()=>User::factory()->create(['email'=>'custody-ui-'.Str::uuid().'@example.invalid','deleted'=>false,'deactivated'=>false,'is_leng'=>1,'two_factor_secret'=>encrypt('isolated-authenticator-fixture')]));
        $u->assignRole('superadmin');$this->actingAs($u);
        DB::table('custody_networks')->where('chain','bnb')->update(['native_max_fee'=>'0.0001']);
        $response=$this->get('/exchange-control-panel/custody');$response->assertOk();
        $response->assertInertia(fn($page)=>$page->component('Admin/ColdStorage/Custody')->where('verified',false)->where('twoFactorConfigured',true)->has('networks'));
        // Optional private browser fixture from this rolled-back test transaction.
        if(getenv('CUSTODY_UI_FIXTURE')==='1')file_put_contents('/tmp/custody-operations-ui.html',$response->getContent());
    }

}
