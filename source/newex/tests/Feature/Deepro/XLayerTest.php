<?php
namespace Tests\Feature\Deepro;

use Tests\TestCase;
use App\Console\Commands\Deepro\ConfigureXLayer;
use App\Models\Currency\Currency;
use App\Models\Deposit\DepositChannel;
use App\Models\User\User;
use App\Models\Wallet\{Wallet,WalletAddress};
use App\Services\Custody\CustodyNetwork;
use App\Services\Deposit\{EvmDepositClient,DepositChannelPolicy,VerifiedChainDeposit};
use App\Services\Wallet\{NetworkRules,XLayerAddress,WithdrawalNetworkPolicy};
use App\Services\Withdrawal\WithdrawalFeeService;
use Illuminate\Support\Facades\{DB,Schema,Http,Event,Mail,Queue};
use Illuminate\Support\Str;

final class XLayerTest extends TestCase
{
    protected function setUp(): void {
        parent::setUp();$this->assertSame('deepro_test',DB::connection()->getDatabaseName());DB::beginTransaction();
        if(!Schema::hasColumn('custody_networks','auto_sweep_scope'))(require database_path('migrations/2026_09_23_120000_custody_sweep_scope.php'))->up();
        if(!Schema::hasColumn('deposit_channels','pilot_digest'))(require database_path('migrations/2026_09_23_110000_deposit_channel_pilots.php'))->up();
        if(!Schema::hasColumn('chain_deposit_scan_states','realtime_from'))(require database_path('migrations/2026_09_24_100000_evm_scan_lanes.php'))->up();
        if(!Schema::hasColumn('chain_deposit_scan_states','realtime_last_success_at'))(require database_path('migrations/2026_09_24_120000_evm_live_health.php'))->up();
        if(!Schema::hasColumn('currencies','xlayer_contract'))(require database_path('migrations/2026_09_28_160000_xlayer_channels.php'))->up();
        Event::fake();Mail::fake();Queue::fake();Http::preventStrayRequests();
        config(['app.readonly'=>false,'deposits.evm.xlayer.rpc'=>'https://xlayer.invalid','deposits.evm.xlayer.rpc_fallbacks'=>[],'deposits.explorer.proxy_fallback'=>false]);
        $this->artisan('deepro:configure-xlayer',['--apply'=>true])->assertExitCode(0);
    }
    protected function tearDown(): void {while(DB::transactionLevel())DB::rollBack();parent::tearDown();}
    public function test_onboarding_is_idempotent_and_never_opens_a_channel(): void {
        $this->artisan('deepro:configure-xlayer',['--apply'=>true])->assertExitCode(0);
        $this->assertSame(1,Currency::where('symbol','OKB')->count());
        $this->assertSame(2,DepositChannel::where('chain','xlayer')->count());
        $this->assertSame(['draft'],DepositChannel::where('chain','xlayer')->pluck('state')->unique()->values()->all());
        $this->assertFalse(DB::table('networks')->where('id',24)->value('deposit_status'));
        $this->assertFalse(DB::table('custody_networks')->where('chain','xlayer')->value('enabled'));
        $this->assertSame('Withdrawals are not allowed for this network',app(WithdrawalNetworkPolicy::class)->error(2,25));
    }
    public function test_reusing_ethereum_wallet_validates_on_xlayer_and_keeps_secret_encrypted(): void {
        $address='0x'.str_repeat('a',40);$key='isolated-test-'.Str::uuid();
        \Setting::set('ethereum.wallet',$address);\Setting::set('ethereum.private_key',$key);\Setting::save();
        $bridge=\Mockery::mock(\App\Services\Custody\CustodyBridge::class);
        $bridge->shouldReceive('call')->once()->with('xlayer','validate',['sender'=>$address,'private_key'=>$key])->andReturn(['success'=>true,'valid'=>true]);$this->app->instance(\App\Services\Custody\CustodyBridge::class,$bridge);
        $this->artisan('deepro:configure-xlayer',['--apply'=>true,'--reuse-ethereum-wallet'=>true])->assertExitCode(0);
        $this->assertSame($address,setting('xlayer.wallet'));$this->assertSame($key,setting('xlayer.private_key'));
        $this->assertSame($key,setting('ethereum.private_key'));
        $this->assertStringNotContainsString($key,DB::table('custody_audits')->where('action','xlayer.configured')->orderByDesc('id')->value('detail'));
    }
    public function test_explicit_deposit_activation_keeps_withdrawals_closed_and_retains_cursor(): void {
        Http::fake(fn($r)=>Http::response(['result'=>match($r['method']){'eth_chainId'=>'0xc4','eth_getBlockByNumber'=>['number'=>'0x3e8'],'eth_getCode'=>'0x1234','eth_call'=>'0x6',default=>null}]));
        $this->artisan('deepro:configure-xlayer',['--apply'=>true,'--enable-deposits'=>true])->assertExitCode(0);
        $rows=DepositChannel::where('chain','xlayer')->get();
        $this->assertSame(['active'],$rows->pluck('state')->unique()->values()->all());
        foreach($rows as $c){$this->assertNull(app(DepositChannelPolicy::class)->error($c->currency_id,$c->network_id,false));$this->assertSame(937,$c->start_block);}
        $this->assertFalse(DB::table('networks')->whereIn('id',[24,25])->where('withdraw_status',true)->exists());
        $this->artisan('deepro:configure-xlayer',['--apply'=>true,'--enable-deposits'=>true])->assertExitCode(0);
        $this->assertSame([937,937],DepositChannel::where('chain','xlayer')->pluck('start_block')->all());
    }
    public function test_network_specific_fees_do_not_change_existing_usdt_networks(): void {
        $c=Currency::findOrFail(2);$c->withdraw_fee_xlayer=0;$c->withdraw_fee_xlayer_fixed='0.2';$c->withdraw_fee_erc=0;$c->withdraw_fee_erc_fixed='3';
        $this->assertEquals('0.2',app(WithdrawalFeeService::class)->calculateCryptoFee($c,'20','xlayer20'));
        $this->assertEquals('3',app(WithdrawalFeeService::class)->calculateCryptoFee($c,'20','erc20'));
        $this->assertEquals('0.2',NetworkRules::withdrawal($c,25)['fee_fixed']);
        $this->assertSame('xlayer',CustodyNetwork::asset($c->id,25)['chain']);
        $this->assertSame(ConfigureXLayer::USDT,CustodyNetwork::asset($c->id,25)['contract']);
    }
    public function test_xko_conversion_preserves_the_address_body(): void {
        $a='70586BeEB7b7Aa2e7966DF9c8493C6CbFd75C625';$this->assertSame('0x'.$a,XLayerAddress::normalize('XKO'.$a));
        $this->assertSame('0x'.$a,XLayerAddress::normalize('xko'.$a));$this->assertSame('XKO0x'.$a,XLayerAddress::normalize('XKO0x'.$a));
    }
    public function test_finalized_head_and_wrong_chain_fail_closed(): void {
        Http::fake(fn($r)=>Http::response(['result'=>$r['method']==='eth_chainId'?'0xc4':['number'=>'0x64']]));
        $this->assertSame(100,app(EvmDepositClient::class)->height('xlayer'));
        Http::swap(new \Illuminate\Http\Client\Factory());Http::fake(fn()=>Http::response(['result'=>'0x1']));
        $this->expectExceptionMessage('DEPOSIT_WRONG_CHAIN');app(EvmDepositClient::class)->height('xlayer');
    }
    public function test_missing_finalized_head_does_not_fall_back_to_latest_or_explorer(): void {
        config(['deposits.explorer.proxy_fallback'=>true]);
        Http::fake(fn($r)=>Http::response($r['method']==='eth_chainId'?['result'=>'0xc4']:['error'=>['code'=>-32602]]));
        $this->expectExceptionMessage('DEPOSIT_RPC_FAILED');app(EvmDepositClient::class)->height('xlayer');
    }
    public function test_native_scan_resumes_without_gaps_under_public_rpc_batch_limit(): void {
        $batches=[];
        Http::fake(function($r)use(&$batches){
            $data=$r->data();
            if(array_is_list($data)){
                if(count($data)>10)return Http::response(['id'=>null,'error'=>['code'=>-32014,'message'=>'too many RPC calls in batch request']]);
                $batches[]=array_column($data,'id');
                return Http::response(array_map(fn($q)=>['id'=>$q['id'],'result'=>['number'=>$q['params'][0],'transactions'=>[]]],$data));
            }
            return Http::response(['result'=>match($r['method']){'eth_chainId'=>'0xc4','eth_getBlockByNumber'=>['number'=>'0x12b'],'eth_getCode'=>'0x1234','eth_call'=>'0x6',default=>null}]);
        });
        $this->artisan('deepro:configure-xlayer',['--apply'=>true,'--enable-deposits'=>true])->assertExitCode(0);
        $c=DepositChannel::where('chain','xlayer')->where('kind','native')->firstOrFail();
        $c->update(['start_block'=>100]);$c->update(['config_digest'=>$c->digest()]);
        DB::table('chain_deposit_scan_states')->where('chain','xlayer')->where('scope',$c->scanScope())->delete();
        $scanner=app(\App\Console\Commands\Deepro\ScanEvmDeposits::class);
        $state=fn()=>DB::table('chain_deposit_scan_states')->where('chain','xlayer')->where('scope',$c->scanScope())->first();
        $this->assertTrue($scanner->runChannels([$c]));
        $this->assertSame(219,$state()->scanned_through);
        $this->assertNotNull(app(DepositChannelPolicy::class)->error($c->currency_id,$c->network_id));
        $this->assertTrue($scanner->runChannels([$c]));
        $this->assertSame(236,$state()->scanned_through);
        $this->assertNull(app(DepositChannelPolicy::class)->error($c->currency_id,$c->network_id));
        $this->assertSame(range(100,236),array_merge(...$batches));
        $this->assertLessThanOrEqual(10,max(array_map('count',$batches)));
    }
    public function test_native_withdrawal_receipt_requires_finality_and_correct_network(): void {
        $sender='0x'.str_repeat('a',40);$dest='0x'.str_repeat('b',40);$hash='0x'.str_repeat('c',64);$block='0x'.str_repeat('d',64);
        \Setting::set('xlayer.wallet',$sender);
        $w=new \App\Models\Withdrawal\Withdrawal(['currency_id'=>Currency::where('symbol','OKB')->value('id'),'network_id'=>24,'amount'=>'1','fee'=>'0','address'=>$dest]);$w->created_at=now()->subHour();
        $finalized=200;
        Http::fake(function($r)use($hash,$block,$sender,$dest,&$finalized){return Http::response(['result'=>match($r['method']){
            'eth_chainId'=>'0xc4','eth_blockNumber'=>'0xc8',
            'eth_getTransactionReceipt'=>['transactionHash'=>$hash,'status'=>'0x1','blockNumber'=>'0x64','blockHash'=>$block],
            'eth_getTransactionByHash'=>['hash'=>$hash,'from'=>$sender,'to'=>$dest,'value'=>'0xde0b6b3a7640000','blockHash'=>$block],
            'eth_getBlockByNumber'=>['number'=>'0x'.dechex($r['params'][0]==='finalized'?$finalized:100),'hash'=>$block,'timestamp'=>'0x'.dechex(now()->subMinute()->timestamp)],default=>null}]);});
        $svc=app(\App\Services\Withdrawal\VerifiedChainReceipt::class);$this->assertSame(196,$svc->verify($w,$hash)['chain_id']);
        $this->assertStringContainsString('/xlayer/tx/',$w->forceFill(['txn'=>$hash])->txn_link);
        $finalized=99;$this->expectException(\Illuminate\Validation\ValidationException::class);$svc->verify($w,$hash);
    }
    public function test_explicit_withdrawal_activation_keeps_sweeps_off_and_sends_no_transaction(): void {
        \Setting::set('xlayer.wallet','0x'.str_repeat('a',40));\Setting::set('xlayer.private_key','test-only-key');
        $bridge=\Mockery::mock(\App\Services\Custody\CustodyBridge::class);
        $bridge->shouldReceive('call')->once()->with('xlayer','validate',\Mockery::type('array'))->andReturn(['success'=>true,'valid'=>true]);
        $this->app->instance(\App\Services\Custody\CustodyBridge::class,$bridge);
        Http::fake(fn($r)=>Http::response(['result'=>match($r['method']){'eth_chainId'=>'0xc4','eth_getBlockByNumber'=>['number'=>'0x3e8'],'eth_getCode'=>'0x1234','eth_call'=>'0x6',default=>null}]));
        $before=DB::table('custody_transfers')->count();
        $this->artisan('deepro:configure-xlayer',['--apply'=>true,'--enable-withdrawals'=>true])->assertExitCode(0);
        foreach([['OKB',24],['USDT',25]] as [$symbol,$network])$this->assertNull(app(WithdrawalNetworkPolicy::class)->error(Currency::where('symbol',$symbol)->value('id'),$network));
        $this->assertTrue(DB::table('custody_networks')->where('chain','xlayer')->value('enabled'));
        $this->assertFalse(DB::table('custody_networks')->where('chain','xlayer')->value('auto_sweep'));
        $this->assertEquals(0,DB::table('custody_networks')->where('chain','xlayer')->value('daily_gas_limit'));
        $this->assertSame($before,DB::table('custody_transfers')->count());
    }
    public function test_usdt_minimum_update_preserves_all_scan_progress_and_network_switches(): void {
        // Other fixture channels stay draft; their identity is not part of this test.
        DepositChannel::where('currency_id',2)->where('chain','!=','xlayer')->update(['state'=>'draft','config_digest'=>null]);
        Http::fake(fn($r)=>Http::response(['result'=>match($r['method']){'eth_chainId'=>'0xc4','eth_getBlockByNumber'=>['number'=>'0x3e8'],'eth_getCode'=>'0x1234','eth_call'=>'0x6',default=>null}]));
        $this->artisan('deepro:configure-xlayer',['--apply'=>true,'--enable-deposits'=>true])->assertExitCode(0);
        $c=DepositChannel::where('currency_id',2)->where('chain','xlayer')->firstOrFail();
        DB::table('chain_deposit_scan_states')->updateOrInsert(['chain'=>'xlayer','scope'=>$c->scanScope()],['scanned_through'=>950,'window_end'=>950,'last_success_at'=>now(),'last_error'=>null]);
        $before=(array)DB::table('chain_deposit_scan_states')->where('scope',$c->scanScope())->first();
        $switches=DB::table('networks')->orderBy('id')->get(['id','deposit_status','withdraw_status'])->toJson();
        $oldDigest=$c->config_digest;
        $this->artisan('deepro:usdt-deposit-minimum',['minimum'=>'2','--apply'=>true])->assertExitCode(0);
        $this->assertEquals(2,Currency::findOrFail(2)->min_deposit);
        foreach(DepositChannel::where('currency_id',2)->get() as $row)$this->assertEquals(2,$row->minimum);
        $this->assertSame($before,(array)DB::table('chain_deposit_scan_states')->where('scope',$c->scanScope())->first());
        $this->assertSame($switches,DB::table('networks')->orderBy('id')->get(['id','deposit_status','withdraw_status'])->toJson());
        $this->assertNotSame($oldDigest,$c->fresh()->config_digest);
        $this->assertNull(app(DepositChannelPolicy::class)->error(2,25));
        $this->assertEquals(2,NetworkRules::deposit(Currency::findOrFail(2),25,$c->fresh())['minimum']);
        $audit=DB::table('deposit_channel_audits')->count();
        $this->artisan('deepro:usdt-deposit-minimum',['minimum'=>'2','--apply'=>true])->assertExitCode(0);
        $this->assertSame($audit,DB::table('deposit_channel_audits')->count());
    }
    public static function assets(): array {return [['OKB',24,'1000000000000000000','1',18],['USDT',25,'30000000','30',6],['USDT',25,'2000000','2',6],['USDT',25,'1999999','1.999999',6,false]];}
    /** @dataProvider assets */
    public function test_verified_native_and_token_receipts_credit_exactly_once(string $symbol,int $network,string $raw,string $amount,int $decimals,bool $eligible=true): void {
        $c=Currency::where('symbol',$symbol)->firstOrFail();$c->update(['deposit_status'=>true,'disabled_deposit_networks'=>'']);DB::table('networks')->where('id',$network)->update(['deposit_status'=>true]);
        $user=User::withoutEvents(fn()=>User::factory()->create(['email'=>'xlayer-'.Str::uuid().'@example.invalid','deleted'=>false,'deactivated'=>false,'is_xn'=>false]));$this->actingAs($user);
        $wallet=Wallet::firstOrCreate(['user_id'=>$user->id,'currency_id'=>$c->id]);$wallet->update(['balance_in_wallet'=>0]);
        $addr='0x'.substr(hash('sha256',(string)Str::uuid()),0,40);$address=new WalletAddress();$address->forceFill(['user_id'=>$user->id,'wallet_id'=>$wallet->id,'network_id'=>$network,'address'=>$addr,'created_at'=>now()->subHour()])->save();
        $channel=DepositChannel::where('currency_id',$c->id)->where('network_id',$network)->firstOrFail();$channel->update(['state'=>'active','decimals'=>$decimals,'start_block'=>1,'confirmations'=>64,'minimum'=>$symbol==='USDT'?2:0,'fee_fixed'=>0,'fee_percent'=>0,'acceptance_reference'=>'ISOLATED TEST ONLY']);$channel->refresh();$channel->config_digest=$channel->digest();$channel->save();
        $this->assertNull(app(DepositChannelPolicy::class)->configurationError($channel));
        $hash='0x'.hash('sha256',(string)Str::uuid());$bh='0x'.str_repeat('b',64);$from='0x'.str_repeat('1',40);
        $log=['address'=>ConfigureXLayer::USDT,'transactionHash'=>$hash,'blockHash'=>$bh,'logIndex'=>'0x0','topics'=>['0x'.\App\Services\Deposit\ChainAmount::TRANSFER,'0x'.str_repeat('0',24).substr($from,2),'0x'.str_repeat('0',24).substr($addr,2)],'data'=>'0x'.str_pad(dechex((int)$raw),64,'0',STR_PAD_LEFT)];
        Http::fake(fn($r)=>Http::response(['result'=>match($r['method']){
            'eth_chainId'=>'0xc4','eth_blockNumber'=>'0xc8',
            'eth_getBlockByNumber'=>['number'=>$r['params'][0]==='finalized'?'0xc8':'0x64','hash'=>$bh,'timestamp'=>'0x'.dechex(now()->subMinute()->timestamp)],
            'eth_getTransactionReceipt'=>['transactionHash'=>$hash,'status'=>'0x1','blockNumber'=>'0x64','blockHash'=>$bh,'logs'=>[$log]],
            'eth_getTransactionByHash'=>['hash'=>$hash,'blockHash'=>$bh,'to'=>$addr,'from'=>$from,'value'=>'0x'.dechex((int)$raw)],
            'eth_call'=>'0x'.dechex($decimals),'eth_getCode'=>'0x1234',default=>null}]));
        $client=app(EvmDepositClient::class);$client->validateToken($channel);$proof=$client->verify($channel,$hash,$addr,$client->height('xlayer'))[0];$service=app(VerifiedChainDeposit::class);
        $this->assertSame($eligible?'credited':'below_minimum_or_fee',$service->process($channel,$address,$proof)['result']);$this->assertSame('already_processed',$service->process($channel,$address,$proof)['result']);
        $this->assertEquals($eligible?$amount:0,$wallet->fresh()->balance_in_wallet);$this->assertSame(1,DB::table('chain_deposit_receipts')->where('txn',$hash)->count());
    }
}
