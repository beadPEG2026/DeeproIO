<?php
namespace Tests\Feature\Deepro;

use Tests\TestCase;
use App\Models\User\User;
use App\Models\Wallet\Wallet;
use App\Models\Market\Market;
use App\Models\Option\Option;
use App\Services\Admin\FundTransferReview;
use App\Services\Option\{OptionFunds,OptionPrice};
use App\Services\Settings\AtomicEnvWriter;
use Illuminate\Support\Facades\{DB,Event,Http,Mail,Queue,Cache,Route};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AdminControlsTest extends TestCase
{
    protected function setUp():void {
        parent::setUp();$this->assertSame('deepro_test',DB::connection()->getDatabaseName());
        config(['app.readonly'=>false,'cache.default'=>'array','session.driver'=>'array','broadcasting.default'=>'log','admin-controls.simulation_controls'=>false]);
        DB::beginTransaction();Event::fake();Mail::fake();Queue::fake();Http::preventStrayRequests();
        foreach (['admin.peerOrdersAppeals.getChat'=>'app/Modules/P2P/Routes/admin.php','admin.merchant.refunds'=>'app/Modules/Merchant/Routes/web.php'] as $name=>$file) if (!Route::has($name)) Route::middleware('web')->group(base_path($file));
    }
    protected function tearDown():void {while(DB::transactionLevel()>0) DB::rollBack();parent::tearDown();}
    private function user(string $role='user',bool $virtual=false):User {
        $u=User::withoutEvents(fn()=>User::factory()->create(['email'=>'controls-'.Str::uuid().'@example.invalid','deleted'=>false,'deactivated'=>false,'is_xn'=>$virtual]));$u->assignRole($role);return $u;
    }
    private function wallet(User $u,string $balance='0'):Wallet {return Wallet::firstOrCreate(['user_id'=>$u->id,'currency_id'=>2],['balance_in_wallet'=>$balance,'balance_in_trade'=>'0','balance_in_order'=>'0','balance_in_withdraw'=>'0','balance_in_virtual_wallet'=>'0','balance_in_virtual_trade'=>'0','balance_in_virtual_order'=>'0']);}
    private function proposal(User $actor,Wallet $a,Wallet $b,array $changes=[]):array {return array_replace(['idempotency_key'=>(string)Str::uuid(),'wallet'=>$b->id,'source_wallet_id'=>$a->id,'balance_type'=>'wallet','source_balance_type'=>'wallet','amount'=>'10.000000000000000001','note'=>'Isolated funded transfer test','reference'=>'TEST-20260921'],$changes);}
    public function test_balanced_credit_requires_an_independent_reviewer_and_is_idempotent():void {
        $a=$this->user('superadmin');$b=$this->user('superadmin');$source=$this->wallet($a,'100');$target=$this->wallet($this->user());$s=app(FundTransferReview::class);$payload=$this->proposal($a,$source,$target);
        $row=$s->propose($a,$payload);$this->assertSame($row->id,$s->propose($a,$payload)->id);$this->assertEquals('100',$source->fresh()->balance_in_wallet);$this->assertEquals('0',$target->fresh()->balance_in_wallet);
        try {$s->review($a,$row->id,true,'Cannot review my own proposal');$this->fail('self approval');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(403,$e->getStatusCode());}
        $s->review($b,$row->id,true,'Independent evidence reviewed');$s->review($b,$row->id,true,'Retry after uncertain response');
        $this->assertSame(0,bccomp('89.999999999999999999',$source->fresh()->balance_in_wallet,18));$this->assertSame(0,bccomp($payload['amount'],$target->fresh()->balance_in_wallet,18));
        $this->assertSame(0,bccomp('100',bcadd($source->fresh()->balance_in_wallet,$target->fresh()->balance_in_wallet,18),18));$this->assertNotNull(DB::table('admin_fund_transfers')->where('id',$row->id)->value('ledger'));
    }
    public function test_insufficient_funds_and_domain_mismatch_leave_no_credit():void {
        $a=$this->user('superadmin');$reviewer=$this->user('superadmin');$source=$this->wallet($a,'1');$target=$this->wallet($this->user());$s=app(FundTransferReview::class);$r=$s->propose($a,$this->proposal($a,$source,$target));
        try{$s->review($reviewer,$r->id,true,'Insufficient funds regression');$this->fail('unfunded credit');}catch(ValidationException $e){$this->assertArrayHasKey('amount',$e->errors());}
        $this->assertEquals('0',$target->fresh()->balance_in_wallet);$this->assertSame('pending',DB::table('admin_fund_transfers')->where('id',$r->id)->value('status'));
        $virtual=$this->wallet($this->user('user',true));$this->expectException(ValidationException::class);$s->propose($a,$this->proposal($a,$source,$virtual));
    }
    public function test_unowned_source_and_changed_idempotency_payload_are_rejected():void {
        $a=$this->user('superadmin');$source=$this->wallet($a,'100');$target=$this->wallet($this->user());$s=app(FundTransferReview::class);$data=$this->proposal($a,$source,$target);$s->propose($a,$data);
        try{$s->propose($a,array_replace($data,['amount'=>'9']));$this->fail('payload collision');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(409,$e->getStatusCode());}
        try{$s->propose($a,$this->proposal($a,$target,$source));$this->fail('unowned source');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(403,$e->getStatusCode());}
    }
    public function test_simulation_funding_cannot_mint_real_money_or_change_user_domain():void {
        $admin=$this->user('superadmin');$user=$this->user();$w=$this->wallet($user);$this->actingAs($admin);
        $d=['wallet'=>$w->id,'account_type'=>'virtual','balance_type'=>'wallet','amount'=>'20','note'=>'Simulation funding test','idempotency_key'=>(string)Str::uuid()];
        $this->postJson('/exchange-control-panel/reports/wallets/transfer',$d)->assertStatus(422);$this->assertFalse((bool)$user->fresh()->is_xn);
        $user->is_xn=true;$user->save();$this->postJson('/exchange-control-panel/reports/wallets/transfer',$d)->assertOk();$this->postJson('/exchange-control-panel/reports/wallets/transfer',$d)->assertOk();
        $this->assertSame(0,bccomp('20',$w->fresh()->balance_in_virtual_wallet,18));$this->assertEquals('0',$w->fresh()->balance_in_wallet);
    }
    public function test_option_cancellation_retains_original_domain_even_if_balance_is_zero():void {
        $u=$this->user('user',true);$w=$this->wallet($u);$m=Market::where('name','BTC-USDT')->firstOrFail();
        $o=Option::factory()->create(['user_id'=>$u->id,'market_id'=>$m->id,'currency_id'=>2,'type'=>'buy','status'=>'scheduled','amount'=>'23','funding_domain'=>'virtual','funding_wallet_id'=>$w->id]);
        app(OptionFunds::class)->cancelScheduled($o->id);app(OptionFunds::class)->cancelScheduled($o->id);
        $this->assertSame(0,bccomp('23',$w->fresh()->balance_in_virtual_wallet,18));$this->assertEquals('0',$w->fresh()->balance_in_trade);$this->assertSame('closed',$o->fresh()->status);
    }
    public function test_legacy_option_source_is_not_guessed():void {
        $u=$this->user();$w=$this->wallet($u);$m=Market::where('name','BTC-USDT')->firstOrFail();$o=Option::factory()->create(['user_id'=>$u->id,'market_id'=>$m->id,'currency_id'=>2,'type'=>'buy','status'=>'scheduled']);
        try{app(OptionFunds::class)->cancelScheduled($o->id);$this->fail('guessed domain');}catch(ValidationException $e){$this->assertArrayHasKey('option',$e->errors());}
        $this->assertSame('scheduled',$o->fresh()->status);$this->assertEquals('0',$w->fresh()->balance_in_trade);
    }
    public function test_real_option_price_ignores_editable_cache_and_rejects_stale_or_late_data():void {
        $m=Market::where('name','BTC-USDT')->firstOrFail();$m->chart_source='binance';Cache::put('market.'.$m->id.'.last','999999');
        $stamp=now()->timestamp;Http::fake(function()use(&$stamp){return Http::response(['symbol'=>'BTCUSDT','lastPrice'=>'123.45','closeTime'=>$stamp*1000]);});
        $this->assertSame('123.45',app(OptionPrice::class)->quote($m)['price']);
        try{app(OptionPrice::class)->quote($m,now()->timestamp-60);$this->fail('late expiry');}catch(ValidationException $e){$this->assertArrayHasKey('price',$e->errors());}
        Cache::forget('options:verified-quote:BTCUSDT');$stamp=now()->timestamp-60;$this->expectException(ValidationException::class);app(OptionPrice::class)->quote($m);
    }
    public function test_settings_reject_invalid_values_and_mutations_are_not_get():void {
        $this->actingAs($this->user('superadmin'));
        foreach ([['trade'=>['options_result_mode'=>'invented']],['general'=>['withdrawal_limit'=>10,'withdrawal_limit_kyc'=>20]],['general'=>['default_trade_pair'=>'FAKE-USDT']],['bnb'=>['wallet'=>'wrong']],['mail'=>['mail_encryption'=>'none']]] as $data) $this->putJson('/exchange-control-panel/settings',$data)->assertStatus(422);
        foreach(['admin.system.monitor.test','admin.system.monitor.test-alerts'] as $name){$route=Route::getRoutes()->getByName($name);if($route)$this->assertNotContains('GET',$route->methods());}
    }
    public function test_superadmin_without_compatibility_role_can_access_modules_and_regular_user_cannot():void {
        $a=$this->user('superadmin');$this->actingAs($a);
        $this->get('/exchange-control-panel/admin-controls')->assertOk();
        $this->get(route('admin.merchant.refunds'))->assertOk();
        $this->assertSame(['superadmin'],$a->getRoleNames()->all());
        $this->actingAs($this->user());$this->getJson('/exchange-control-panel/admin-controls')->assertForbidden();$this->getJson(route('admin.merchant.refunds'))->assertForbidden();
    }
    public function test_env_writer_preserves_special_characters_and_appends_missing_keys():void {
        $path=tempnam(sys_get_temp_dir(),'deepro-env-');file_put_contents($path,"APP_NAME=Existing\n");
        $values=['APP_NAME'=>'Quoted "brand"','NEW_SECRET'=>"A'b\\c\"\${HOME} # tail",'EMPTY_VALUE'=>''];
        try{app(AtomicEnvWriter::class)->update($values,$path);$parsed=\Dotenv\Dotenv::parse(file_get_contents($path));foreach($values as $k=>$v)$this->assertSame($v,$parsed[$k]);
            $before=file_get_contents($path);try{app(AtomicEnvWriter::class)->update(['NEW_SECRET'=>"bad\nline"],$path);$this->fail('newline injection');}catch(\InvalidArgumentException $e){}$this->assertSame($before,file_get_contents($path));
        }finally{@unlink($path);@unlink($path.'.lock');}
    }
    private function receiptFixture(): array {
        $sender='0x'.str_repeat('1',40);$destination='0x'.str_repeat('2',40);$contract='0x'.str_repeat('3',40);$hash='0x'.str_repeat('4',64);$block='0x'.str_repeat('5',64);
        \Setting::set('bnb.wallet',$sender);DB::table('currencies')->where('id',2)->update(['bep_contract'=>$contract]);
        $u=$this->user();$wallet=$this->wallet($u);$wallet->balance_in_withdraw='22';$wallet->save();
        $withdrawal=\App\Models\Withdrawal\Withdrawal::create(['withdrawal_id'=>(string)Str::uuid(),'type'=>'coin','currency_id'=>2,'network_id'=>NETWORK_BEP,'amount'=>'11','fee'=>'1','address'=>$destination,'user_id'=>$u->id,'source_id'=>'test','status'=>WITHDRAWAL_WAITING_APPROVAL]);
        $withdrawal->created_at=now()->subMinutes(10);$withdrawal->save();
        $data=['eth_chainId'=>'0x38','eth_blockNumber'=>'0x110','eth_getBlockByNumber'=>['hash'=>$block,'timestamp'=>'0x'.dechex(now()->timestamp-60)],'eth_call'=>'0x12',
        'eth_getTransactionByHash'=>['hash'=>$hash,'from'=>$sender,'to'=>$contract,'blockHash'=>$block],
        'eth_getTransactionReceipt'=>['status'=>'0x1','transactionHash'=>$hash,'blockNumber'=>'0x100','blockHash'=>$block,'logs'=>[['address'=>$contract,'topics'=>['0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef','0x'.str_repeat('0',24).substr($sender,2),'0x'.str_repeat('0',24).substr($destination,2)],'data'=>'0x8ac7230489e80000','logIndex'=>'0x0']]]];
        return [$withdrawal,$wallet,$hash,$data];
    }
    public function test_chain_receipt_validation_rejects_wrong_amount_destination_chain_and_reorg():void {
        [$w,$wallet,$hash,$data]=$this->receiptFixture();$current=$data;
        Http::fake(function($request)use(&$current){return Http::response(['jsonrpc'=>'2.0','id'=>1,'result'=>$current[$request['method']]??null]);});
        $service=app(\App\Services\Withdrawal\VerifiedChainReceipt::class);$this->assertSame(56,$service->verify($w,$hash)['chain_id']);
        foreach(['amount','address','chain','confirmations','reorg','failed'] as $case){
            $current=$data;
            if($case==='amount')$current['eth_getTransactionReceipt']['logs'][0]['data']='0x01';
            if($case==='address')$current['eth_getTransactionReceipt']['logs'][0]['topics'][2]='0x'.str_repeat('0',64);
            if($case==='chain')$current['eth_chainId']='0x1';
            if($case==='confirmations')$current['eth_blockNumber']='0x101';
            if($case==='reorg')$current['eth_getBlockByNumber']['hash']='0x'.str_repeat('6',64);
            if($case==='failed')$current['eth_getTransactionReceipt']['status']='0x0';
            try{$service->verify($w,$hash);$this->fail($case.' accepted');}catch(ValidationException $e){$this->assertArrayHasKey('txn',$e->errors());}
        }
        $this->assertSame(WITHDRAWAL_WAITING_APPROVAL,$w->fresh()->status);$this->assertSame(0,bccomp('22',$wallet->fresh()->balance_in_withdraw,18));
    }
    public function test_verified_withdrawal_debits_locked_funds_once_and_prevents_receipt_reuse():void {
        [$w,$wallet,$hash,$data]=$this->receiptFixture();Http::fake(fn($r)=>Http::response(['result'=>$data[$r['method']]??null]));
        $repository=new \App\Repositories\Withdrawal\WithdrawalRepository();$this->assertTrue($repository->completeVerified($w->id,$hash,true));$this->assertFalse($repository->completeVerified($w->id,$hash,true));
        $this->assertSame(0,bccomp('11',$wallet->fresh()->balance_in_withdraw,18));$this->assertSame(1,DB::table('admin_chain_receipts')->where('withdrawal_id',$w->id)->count());
        $other=$w->replicate();$other->withdrawal_id=(string)Str::uuid();$other->status=WITHDRAWAL_WAITING_APPROVAL;$other->save();$other->created_at=now()->subMinutes(10);$other->save();
        try{$repository->completeVerified($other->id,$hash,true);$this->fail('reused receipt');}catch(ValidationException $e){$this->assertArrayHasKey('txn',$e->errors());}
        $this->assertSame(0,bccomp('11',$wallet->fresh()->balance_in_withdraw,18));
    }
}
