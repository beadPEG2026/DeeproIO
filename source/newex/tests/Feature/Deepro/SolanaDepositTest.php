<?php
namespace Tests\Feature\Deepro;
use Tests\TestCase;
use App\Models\User\User;
use App\Models\Wallet\{Wallet,WalletAddress};
use App\Models\Deposit\DepositChannel;
use App\Services\Deposit\{SolanaDepositClient,DepositChannelPolicy,DepositChannelConfiguration};
use App\Console\Commands\Solana\MonitorSolDepositsCommand;
use App\Services\PaymentGateways\Coin\Solana\Api\SolanaGateway;
use Illuminate\Support\Facades\{DB,Http,Event,Mail,Queue};
use Illuminate\Support\Str;
final class SolanaDepositTest extends TestCase
{
    private Wallet $wallet;
    private WalletAddress $address;
    private DepositChannel $channel;
    private array $tx;
    private string $sig;
    private $override=null;
    protected function setUp(): void {
        parent::setUp();
        $this->assertSame('deepro_test',DB::connection()->getDatabaseName());
        DB::beginTransaction();
        foreach (['deposit_channels'=>['pilot_digest','2026_09_23_110000_deposit_channel_pilots.php'],'custody_networks'=>['auto_sweep_scope','2026_09_23_120000_custody_sweep_scope.php'],'chain_deposit_scan_states'=>['realtime_from','2026_09_24_100000_evm_scan_lanes.php']] as $table=>[$column,$file]) if (!\Illuminate\Support\Facades\Schema::hasColumn($table,$column)) (require database_path('migrations/'.$file))->up();
        if (!\Illuminate\Support\Facades\Schema::hasColumn('chain_deposit_scan_states','realtime_last_success_at')) (require database_path('migrations/2026_09_24_120000_evm_live_health.php'))->up();
        (require database_path('migrations/2026_09_24_130000_solana_deposit_signatures.php'))->up();
        config(['app.readonly'=>false,'solana.rpc_endpoint'=>'https://solana.invalid','cache.default'=>'array']);
        Event::fake();Mail::fake();Queue::fake();Http::preventStrayRequests();
        DB::table('currencies')->where('id',3)->update(['status'=>true,'deposit_status'=>true,'disabled_deposit_networks'=>'']);
        DB::table('networks')->where('id',20)->update(['status'=>true,'deposit_status'=>true]);
        $user=User::withoutEvents(fn()=>User::factory()->create(['email'=>'sol-'.Str::uuid().'@example.invalid','deleted'=>false,'deactivated'=>false,'is_xn'=>false]));
        $this->actingAs($user);
        $this->wallet=Wallet::create(['user_id'=>$user->id,'currency_id'=>3,'balance_in_wallet'=>0,'balance_in_trade'=>0,'balance_in_order'=>0,'balance_in_withdraw'=>0,'balance_in_virtual_wallet'=>0]);
        $keys=(new SolanaGateway())->createSolAddress();
        $this->address=new WalletAddress();$this->address->forceFill(['user_id'=>$user->id,'wallet_id'=>$this->wallet->id,'network_id'=>20,'address'=>$keys['address'],'private_key'=>$keys['private_key'],'created_at'=>now()->subHour()]);$this->address->save();
        DepositChannel::where('currency_id',3)->where('network_id',20)->delete();
        $this->channel=DepositChannel::create(['currency_id'=>3,'network_id'=>20,'chain'=>'solana','kind'=>'native','decimals'=>9,'confirmations'=>1,'minimum'=>'0.1','fee_fixed'=>0,'fee_percent'=>0,'state'=>'active','acceptance_reference'=>'ISOLATED FIXTURE ONLY']);
        $this->approve();$this->sig=str_repeat('3',88);
        $this->tx=['slot'=>123,'blockTime'=>now()->subMinute()->timestamp,'meta'=>['err'=>null,'innerInstructions'=>[]],'transaction'=>['signatures'=>[$this->sig],'message'=>['instructions'=>[$this->instruction('100000001')]]]];
        $this->fake();
    }
    protected function tearDown(): void { while(DB::transactionLevel()>0)DB::rollBack();parent::tearDown(); }
    private function approve(): void {$this->channel->refresh();$this->channel->update(['config_digest'=>$this->channel->digest()]);}
    private function instruction($amount): array {return ['program'=>'system','programId'=>SolanaDepositClient::SYSTEM,'parsed'=>['type'=>'transfer','info'=>['source'=>str_repeat('4',44),'destination'=>$this->address->address,'lamports'=>$amount]]];}
    private function index(string $sig): array {return ['signature'=>$sig,'slot'=>123,'err'=>null,'confirmationStatus'=>'finalized','blockTime'=>now()->subMinute()->timestamp];}
    private function fake(): void {
        Http::swap(new \Illuminate\Http\Client\Factory());Http::preventStrayRequests();
        Http::fake(function($r){
            if($this->override){$o=($this->override)($r);if($o!==null)return $o;}
            $v=match($r['method']) {
                'getGenesisHash'=>SolanaDepositClient::GENESIS,'getSlot'=>200,
                'getSignaturesForAddress'=>isset($r['params'][1]['until'])?[]:[$this->index($this->sig)],
                'getSignatureStatuses'=>['value'=>[['slot'=>123,'err'=>null,'confirmationStatus'=>'finalized']]],
                'getTransaction'=>$this->tx,
                default=>throw new \RuntimeException('Unexpected RPC'),
            };return Http::response(['jsonrpc'=>'2.0','id'=>1,'result'=>$v]);
        });
    }
    private function scan(): bool {return app(MonitorSolDepositsCommand::class)->check($this->address,$this->channel);}
    public function test_finalized_deposit_history_and_retry_are_exactly_once(): void {
        $this->assertTrue($this->scan());$this->scan();
        $this->assertSame('0.100000001000000000',bcadd((string)$this->wallet->fresh()->balance_in_wallet,'0',18));
        $this->assertSame(1,DB::table('chain_deposit_receipts')->where('txn',$this->sig)->count());
        $d=DB::table('deposits')->where('txn',$this->sig)->first();$this->assertSame('confirmed',$d->status);$this->assertSame('review',$d->wallet_transfer_status);
        Http::assertSent(fn($r)=>$r['method']==='getTransaction'&&$r['params'][1]['maxSupportedTransactionVersion']===0&&$r['params'][1]['commitment']==='finalized');
    }
    public function test_outer_inner_and_identical_transfers_are_all_credited_once(): void {
        $this->tx['transaction']['message']['instructions'][]=$this->instruction('100000001');
        $this->tx['meta']['innerInstructions']=[['index'=>0,'instructions'=>[$this->instruction('100000001')]]];
        $this->scan();$this->scan();$this->assertEquals('0.300000003',$this->wallet->fresh()->balance_in_wallet);
        $this->assertSame(3,DB::table('chain_deposit_receipts')->where('txn',$this->sig)->count());
    }
    public function test_wrong_recipient_and_non_system_program_do_not_credit(): void {
        $this->tx['transaction']['message']['instructions'][0]['parsed']['info']['destination']=str_repeat('2',44);
        $bad=$this->instruction(100000000);$bad['programId']=str_repeat('5',44);$this->tx['transaction']['message']['instructions'][]=$bad;
        $this->scan();$this->assertEquals(0,$this->wallet->fresh()->balance_in_wallet);
    }
    public function test_failed_transaction_is_not_credited(): void {
        $this->override=fn($r)=>$r['method']==='getSignatureStatuses'?Http::response(['result'=>['value'=>[['slot'=>123,'err'=>['InstructionError'=>1],'confirmationStatus'=>'finalized']]]]):null;
        $this->scan();$this->assertEquals(0,$this->wallet->fresh()->balance_in_wallet);
    }
    public function test_missing_receipt_preserves_cursor_and_never_credits(): void {
        $this->override=fn($r)=>$r['method']==='getTransaction'?Http::response(['result'=>null]):null;
        try{$this->scan();$this->fail('Missing receipt accepted');}catch(\RuntimeException $e){$this->assertSame('SOL_RECEIPT_MISMATCH',$e->getMessage());}
        $this->assertNull(DB::table('chain_deposit_scan_states')->where('chain','solana')->where('scope','like','%:address:'.$this->address->id)->value('fingerprint'));
        $this->assertEquals(0,$this->wallet->fresh()->balance_in_wallet);
    }
    public function test_confirmed_but_not_finalized_receipt_retries(): void {
        $this->override=fn($r)=>$r['method']==='getSignatureStatuses'?Http::response(['result'=>['value'=>[['slot'=>123,'err'=>null,'confirmationStatus'=>'confirmed']]]]):null;
        $this->expectExceptionMessage('SOL_RECEIPT_NOT_FINAL');$this->scan();
    }
    public function test_signature_mismatch_rejected(): void {$this->tx['transaction']['signatures'][0]=str_repeat('5',88);$this->expectExceptionMessage('SOL_RECEIPT_MISMATCH');$this->scan();}
    public function test_before_assignment_does_not_credit(): void {$this->tx['blockTime']=now()->subDays(2)->timestamp;$this->scan();$this->assertEquals(0,$this->wallet->fresh()->balance_in_wallet);}
    public function test_minimum_and_fixed_fee(): void {
        $this->channel->update(['fee_fixed'=>'0.000000001']);$this->approve();$this->scan();$this->assertEquals('0.1',$this->wallet->fresh()->balance_in_wallet);
    }
    public function test_below_minimum_record_without_credit(): void {
        $this->tx['transaction']['message']['instructions'][0]=$this->instruction(99999999);$this->scan();
        $this->assertSame('ignored',DB::table('deposits')->where('txn',$this->sig)->value('status'));$this->assertEquals(0,$this->wallet->fresh()->balance_in_wallet);
    }
    public function test_wrong_chain_and_rpc_errors_are_not_empty_history(): void {
        $this->override=fn($r)=>Http::response(['error'=>['code'=>429]]);
        $this->expectExceptionMessage('SOL_RPC_INVALID_RESPONSE');$this->scan();
    }
    public function test_wrong_genesis_rejected(): void {
        $this->override=fn($r)=>$r['method']==='getGenesisHash'?Http::response(['result'=>'wrong']):null;
        $this->expectExceptionMessage('SOL_WRONG_CHAIN');$this->scan();
    }
    public function test_pagination_continues_after_budget_across_runs(): void {
        // Fixed fixture pages avoid ambiguous numeric characters in actual Base58 signatures.
        $batch=0;$this->override=function($r)use(&$batch){if($r['method']!=='getSignaturesForAddress')return null;$batch++;if($batch===7)return Http::response(['result'=>[]]);$rows=[];for($i=0;$i<100;$i++){$a='ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';$sig=$a[$batch].$a[intdiv($i,48)].$a[$i%48].str_repeat('3',85);$row=$this->index($sig);$row['err']='failed';$rows[]=$row;}return Http::response(['result'=>$rows]);};
        $this->assertFalse($this->scan());$this->assertTrue($this->scan());
        Http::assertSent(fn($r)=>$r['method']==='getSignaturesForAddress'&&isset($r['params'][1]['before']));
        $this->assertEquals(0,$this->wallet->fresh()->balance_in_wallet);
    }
    public function test_activation_and_scanner_health_make_network_available(): void {
        $c=app(DepositChannelConfiguration::class)->save($this->channel->only(['currency_id','network_id','contract','decimals','confirmations','minimum','fee_fixed','fee_percent','start_block','state','acceptance_reference']),null,'isolated-test');
        $this->assertSame('active',$c->state);
        $this->assertSame(0,app(MonitorSolDepositsCommand::class)->handle());
        $this->assertNull(app(DepositChannelPolicy::class)->error(3,20));
        $result=app(\App\Http\Controllers\Api\v1\WalletController::class)->loadNetworks(new \Illuminate\Http\Request(['symbol'=>'SOL','purpose'=>'deposit']));
        $this->assertStringContainsString('Solana',$result->getContent());
    }
    public function test_fractional_lamports_are_rejected_without_checkpoint(): void {
        $this->tx['transaction']['message']['instructions'][0]=$this->instruction(100000000.5);
        $this->expectExceptionMessage('SOL_INVALID_TRANSFER');$this->scan();
    }
    public function test_ambiguous_address_ownership_never_credits(): void {
        $copy=new WalletAddress();$copy->forceFill(['user_id'=>144,'wallet_id'=>$this->wallet->id,'network_id'=>20,'address'=>$this->address->address,'created_at'=>now()->subHour()]);$copy->save();
        $this->expectExceptionMessage('DEPOSIT_ADDRESS_OWNERSHIP_CONFLICT');$this->scan();
    }
    public function test_disabled_currency_is_not_credited_by_direct_scan(): void {
        DB::table('currencies')->where('id',3)->update(['deposit_status'=>false]);
        $this->expectExceptionMessage('DEPOSIT_CHANNEL_UNAVAILABLE');$this->scan();
    }
    public function test_changed_page_replay_is_idempotent_after_transient_failure(): void {
        $this->tx['transaction']['message']['instructions'][]=$this->instruction('100000000');
        $this->scan();
        DB::table('chain_deposit_scan_states')->where('chain','solana')->where('scope','like','%:address:'.$this->address->id)->update(['fingerprint'=>null]);
        $this->scan();$this->assertEquals('0.200000001',$this->wallet->fresh()->balance_in_wallet);
        $this->assertSame(2,DB::table('chain_deposit_receipts')->where('txn',$this->sig)->count());
    }
    public function test_optional_sol_pilot_uses_finality_without_evm_start_block(): void {
        $data=$this->channel->only(['currency_id','network_id','contract','decimals','confirmations','minimum','fee_fixed','fee_percent','start_block','acceptance_reference']);
        $data['state']='validated';$data['pilot_user_ids']=[$this->wallet->user_id];$data['pilot_minimum']='0.1';$data['pilot_limit']='0.5';
        app(DepositChannelConfiguration::class)->save($data,null,'isolated-test');
        $data['state']='pilot';$this->channel=app(DepositChannelConfiguration::class)->save($data,null,'isolated-test');
        $this->assertNull($this->channel->pilot_start_block);
        $this->assertNull(app(DepositChannelPolicy::class)->error(3,20,false,$this->wallet->user_id));
        $this->assertNotNull(app(DepositChannelPolicy::class)->error(3,20,false,144));
        $this->tx['blockTime']=now()->timestamp;$this->scan();
        $this->assertEquals('0.100000001',$this->wallet->fresh()->balance_in_wallet);
        $this->assertSame('pilot',json_decode(DB::table('deposits')->where('txn',$this->sig)->value('initial_raw'),true)['deposit_mode']);
    }
    public function test_base58_leading_zero_round_trip_and_generated_key_match(): void {
        $g=new SolanaGateway();foreach([str_repeat("\0",32),"\0\0".random_bytes(30),random_bytes(32)] as $b){$this->assertSame($b,$g->base58_decode($g->base58_encode($b)));$this->assertTrue($g->isValidSolanaAddress($g->base58_encode($b)));}
        $kp=$g->createSolAddress();$public=\ParagonIE\Sodium\Compat::crypto_sign_publickey_from_secretkey(hex2bin($kp['private_key']));$this->assertSame($kp['address'],$g->base58_encode($public));
    }
    public function test_receipt_enters_existing_automatic_custody_sweep(): void {
        $this->scan();$d=DB::table('deposits')->where('txn',$this->sig)->first();
        DB::table('custody_networks')->where('chain','solana')->update(['enabled'=>true,'auto_sweep'=>true,'auto_sweep_scope'=>'all_verified','max_fee'=>'0.01','native_max_fee'=>'0.001','confirmations'=>1]);
        \Setting::set('solana.wallet',str_repeat('6',44));
        $task=app(\App\Services\Custody\CustodyService::class)->sweep($d->id);
        $this->assertSame('approved',$task->status);$this->assertSame('solana',$task->chain);$this->assertSame('sweep',$task->purpose);
        $this->assertSame('custody',DB::table('deposits')->where('id',$d->id)->value('wallet_transfer_status'));
        $again=app(\App\Services\Custody\CustodyService::class)->sweep($d->id);$this->assertNull($again);
        $this->assertSame(1,DB::table('custody_transfers')->where('deposit_id',$d->id)->count());
    }
}
