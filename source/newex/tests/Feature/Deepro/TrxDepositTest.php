<?php
namespace Tests\Feature\Deepro;

use Tests\TestCase;
use App\Models\User\User;
use App\Models\Wallet\{Wallet,WalletAddress};
use App\Services\Deposit\{TronGridClient,VerifiedTrxDeposit,DepositCreditService};
use App\Console\Commands\Tron\MonitorTrxDepositsCommand;
use Illuminate\Support\Facades\{DB,Event,Http,Mail,Queue,Schema};
use Illuminate\Support\Str;

final class TrxDepositTest extends TestCase
{
    private WalletAddress $address;
    private Wallet $wallet;
    private array $body;
    private array $receipt;
    private string $hash;
    protected function setUp():void {
        parent::setUp();
        // Receipt/ledger fixtures use fake HTTP. Cross-runtime pacing has its own real-DB suite.
        $budget=\Mockery::mock(\App\Services\Deposit\TronRpcBudget::class);
        $budget->shouldReceive('acquire','outcome')->andReturnNull();
        $this->app->instance(\App\Services\Deposit\TronRpcBudget::class,$budget);
$this->assertSame('deepro_test',DB::connection()->getDatabaseName());
        DB::beginTransaction();if (!Schema::hasTable('tron_deposit_receipts')) (require database_path('migrations/2026_09_21_020000_tron_deposit_receipts.php'))->up();
        config(['app.readonly'=>false,'services.trongrid.key'=>'isolated-test-key','cache.default'=>'array']);Event::fake();Mail::fake();Queue::fake();Http::preventStrayRequests();
        DB::table('currencies')->where('id',6)->update(['deposit_status'=>true,'disabled_deposit_networks'=>'','min_deposit'=>'1','deposit_fee'=>0,'deposit_fee_fixed'=>0]);
        DB::table('networks')->where('id',NETWORK_TRX)->update(['deposit_status'=>true]);
        $user=User::withoutEvents(fn()=>User::factory()->create(['email'=>'trx-'.Str::uuid().'@example.invalid','deleted'=>false,'deactivated'=>false,'is_xn'=>false]));
        $this->wallet=Wallet::create(['user_id'=>$user->id,'currency_id'=>6,'balance_in_wallet'=>0,'balance_in_trade'=>0,'balance_in_order'=>0,'balance_in_withdraw'=>0,'balance_in_virtual_wallet'=>0]);
        $this->address=new WalletAddress();$this->address->forceFill(['user_id'=>$user->id,'wallet_id'=>$this->wallet->id,'network_id'=>NETWORK_TRX,'address'=>TronGridClient::base58Address('41'.substr(hash('sha256',(string)Str::uuid()),0,40)),'created_at'=>now()->subHour()]);$this->address->save();
        $this->hash=hash('sha256',(string)Str::uuid());
        $this->body=['txID'=>$this->hash,'ret'=>[['contractRet'=>'SUCCESS']],'raw_data'=>['contract'=>[['type'=>'TransferContract','parameter'=>['value'=>['owner_address'=>'41'.str_repeat('1',40),'to_address'=>TronGridClient::hexAddress($this->address->address),'amount'=>1000000]]]]]];
        $this->receipt=['id'=>$this->hash,'blockNumber'=>80000001,'blockTimeStamp'=>now()->subMinutes(2)->getTimestamp()*1000,'receipt'=>['net_fee'=>100000]];
        $this->fake();
    }
    private function fake():void {Http::fake(fn($request)=>Http::response(str_contains($request->url(),'gettransactioninfobyid')?$this->receipt:$this->body));}
    private function process(bool $apply=true):array {return app(VerifiedTrxDeposit::class)->process($this->address,$this->hash,false,$apply);}
    private function failsWith(string $code):void {try{$this->process();$this->fail('Accepted '.$code);}catch(\RuntimeException $e){$this->assertSame($code,$e->getMessage());}$this->assertEquals(0,$this->wallet->fresh()->balance_in_wallet);$this->assertSame(0,DB::table('tron_deposit_receipts')->where('txn',$this->hash)->count());}
    protected function tearDown():void {while(DB::transactionLevel()>0)DB::rollBack();parent::tearDown();}

    public function test_exact_minimum_credits_real_wallet_once_and_holds_sweep():void {
        $r=$this->process();$this->assertSame('credited',$r['result']);$this->assertSame('already_processed',$this->process()['result']);
        $this->assertSame(0,bccomp('1',$this->wallet->fresh()->balance_in_wallet,18));$this->assertEquals(0,$this->wallet->fresh()->balance_in_virtual_wallet);
        $d=DB::table('deposits')->where('id',$r['deposit_id'])->first();$this->assertSame('confirmed',$d->status);$this->assertSame('review',$d->wallet_transfer_status);$this->assertSame(1,DB::table('deposits')->where('txn',$this->hash)->count());
        $proof=json_decode(DB::table('tron_deposit_receipts')->where('txn',$this->hash)->value('evidence'),true);$this->assertSame(0,bccomp('1',$proof['balance_after'],18));
    }
    public function test_dry_run_does_not_create_deposit_or_balance():void {$this->assertSame('verified_dry_run',$this->process(false)['result']);$this->assertSame(0,DB::table('deposits')->where('txn',$this->hash)->count());$this->assertEquals(0,$this->wallet->fresh()->balance_in_wallet);}
    public function test_below_minimum_is_visible_but_not_credited():void {$this->body['raw_data']['contract'][0]['parameter']['value']['amount']=999999;$r=$this->process();$this->assertSame('below_minimum_or_fee',$r['result']);$this->assertSame('ignored',DB::table('deposits')->where('id',$r['deposit_id'])->value('status'));$this->assertEquals(0,$this->wallet->fresh()->balance_in_wallet);}
    public function test_six_decimal_precision_and_configured_fee():void {$this->body['raw_data']['contract'][0]['parameter']['value']['amount']=1000001;DB::table('currencies')->where('id',6)->update(['deposit_fee_fixed'=>'0.000001']);$this->assertSame('1.000000000000000000',$this->process()['credited']);}
    public function test_wrong_destination_is_rejected():void {$this->body['raw_data']['contract'][0]['parameter']['value']['to_address']='41'.str_repeat('2',40);$this->failsWith('TRON_TRANSFER_MISMATCH');}
    public function test_failed_execution_is_rejected():void {$this->body['ret'][0]['contractRet']='REVERT';$this->failsWith('TRON_NOT_SUCCESSFUL_SOLID_TRANSFER');}
    public function test_unconfirmed_receipt_is_rejected():void {$this->receipt=[];$this->failsWith('TRON_NOT_SUCCESSFUL_SOLID_TRANSFER');}
    public function test_wrong_receipt_id_is_rejected():void {$this->receipt['id']=str_repeat('0',64);$this->failsWith('TRON_NOT_SUCCESSFUL_SOLID_TRANSFER');}
    public function test_token_transfer_cannot_be_credited_as_native_trx():void {$this->body['raw_data']['contract'][0]['type']='TriggerSmartContract';$this->failsWith('TRON_NOT_SUCCESSFUL_SOLID_TRANSFER');}
    public function test_transaction_before_address_assignment_is_rejected():void {$this->receipt['blockTimeStamp']=now()->subDays(2)->getTimestamp()*1000;$this->failsWith('TRON_TRANSFER_PREDATES_ADDRESS');}
    public function test_invalid_key_fails_closed_without_anonymous_retry():void {Http::swap(new \Illuminate\Http\Client\Factory());Http::fake(fn()=>Http::response(['Error'=>'ApiKey not exists'],401));$this->failsWith('TRONGRID_HTTP_401');Http::assertSentCount(1);}
    public function test_missing_key_makes_no_network_request():void {config(['services.trongrid.key'=>'']);$this->failsWith('TRONGRID_KEY_MISSING');Http::assertNothingSent();}
    public function test_legacy_pending_record_is_not_blindly_recredited():void {DB::table('deposits')->insert(['deposit_id'=>(string)Str::uuid(),'source_id'=>'old','txn'=>$this->hash,'currency_id'=>6,'network_id'=>NETWORK_TRX,'user_id'=>$this->address->user_id,'amount'=>'1','type'=>'coin','status'=>'pending','address'=>$this->address->address]);$this->failsWith('TRON_LEGACY_DEPOSIT_REQUIRES_RECONCILIATION');}
    public function test_existing_confirmed_record_is_preserved_without_recredit():void {DB::table('deposits')->insert(['deposit_id'=>(string)Str::uuid(),'source_id'=>'old','txn'=>$this->hash,'currency_id'=>6,'network_id'=>NETWORK_TRX,'user_id'=>$this->address->user_id,'amount'=>'1','type'=>'coin','status'=>'confirmed','address'=>$this->address->address]);$this->assertSame('legacy_record_preserved',$this->process()['result']);$this->assertEquals(0,$this->wallet->fresh()->balance_in_wallet);}
    public function test_disabled_deposit_setting_prevents_credit():void {DB::table('currencies')->where('id',6)->update(['deposit_status'=>false]);$this->failsWith('TRON_DEPOSITS_DISABLED');}
    public function test_wallet_update_failure_rolls_back_deposit_and_receipt():void {$mock=\Mockery::mock(DepositCreditService::class);$mock->shouldReceive('credit')->once()->andThrow(new \RuntimeException('TRON_TEST_WRITE_FAILURE'));$this->app->instance(DepositCreditService::class,$mock);$this->failsWith('TRON_TEST_WRITE_FAILURE');$this->assertSame(0,DB::table('deposits')->where('txn',$this->hash)->count());}
    public function test_duplicate_address_ownership_is_rejected():void {$other=$this->address->replicate();$other->user_id=1;$other->save();$this->failsWith('TRON_ADDRESS_OWNERSHIP_CONFLICT');}
    public function test_pages_preserve_window_and_failure_retries_same_cursor():void {
        $calls=[];$failed=true;Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(function($req)use(&$calls,&$failed){parse_str(parse_url($req->url(),PHP_URL_QUERY),$q);$calls[]=$q;if(isset($q['fingerprint'])&&$failed)return Http::response([],429);return Http::response(['success'=>true,'data'=>[],'meta'=>isset($q['fingerprint'])?[]:['fingerprint'=>'next-page']]);});
        $scanner=app(MonitorTrxDepositsCommand::class);
        try{$scanner->check($this->address);$this->fail('ignored 429');}catch(\RuntimeException $e){$this->assertSame('TRONGRID_HTTP_429',$e->getMessage());}
        $this->assertSame('next-page',DB::table('tron_deposit_scan_states')->where('address',$this->address->address)->value('fingerprint'));
        $failed=false;$scanner->check($this->address);$this->assertSame($calls[1],$calls[2]);$this->assertSame($calls[0]['max_timestamp'],$calls[2]['max_timestamp']);$this->assertNull(DB::table('tron_deposit_scan_states')->where('address',$this->address->address)->value('fingerprint'));
    }
    public function test_scanner_discovers_credits_and_checkpoints_a_new_transaction_once():void {
        Http::swap(new \Illuminate\Http\Client\Factory());Http::fake(function($r){if(str_contains($r->url(),'/v1/accounts/'))return Http::response(['success'=>true,'data'=>[$this->body],'meta'=>[]]);return Http::response(str_contains($r->url(),'gettransactioninfobyid')?$this->receipt:$this->body);});
        $scanner=app(MonitorTrxDepositsCommand::class);$scanner->check($this->address);$scanner->check($this->address);$this->assertEquals(1,$this->wallet->fresh()->balance_in_wallet);$this->assertSame(1,DB::table('deposits')->where('txn',$this->hash)->count());$state=DB::table('tron_deposit_scan_states')->where('address',$this->address->address)->first();$this->assertNotNull($state->scanned_through);$this->assertNull($state->last_error);
    }
    public function test_privileged_env_write_preserves_application_owner_and_mode():void {
        if(!function_exists('posix_geteuid')||posix_geteuid()!==0)$this->markTestSkipped('Privileged local harness required');
        $owner=posix_getpwnam('www');$path=tempnam(sys_get_temp_dir(),'trx-env-owner-');file_put_contents($path,"APP_NAME=Test\n");chown($path,$owner['uid']);chgrp($path,$owner['gid']);chmod($path,0600);
        try{app(\App\Services\Settings\AtomicEnvWriter::class)->update(['TRONGRID_API_KEY'=>'fixture-only'],$path);clearstatcache(true,$path);$this->assertSame($owner['uid'],fileowner($path));$this->assertSame($owner['gid'],filegroup($path));$this->assertSame(0600,fileperms($path)&0777);$this->assertSame($owner['uid'],fileowner($path.'.lock'));}finally{unlink($path);unlink($path.'.lock');}
    }
    public function test_one_hash_public_read_omits_invalid_key_without_changing_scanner_config():void {
        config(['services.trongrid.key'=>'invalid-local-test']);
        $r=app(VerifiedTrxDeposit::class)->process($this->address,$this->hash,true,false);$this->assertSame('verified_dry_run',$r['result']);
        Http::assertSent(fn($req)=>!$req->hasHeader('TRON-PRO-API-KEY'));$this->assertSame('invalid-local-test',config('services.trongrid.key'));
    }
}
