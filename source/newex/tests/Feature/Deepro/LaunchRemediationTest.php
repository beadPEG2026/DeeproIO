<?php
namespace Tests\Feature\Deepro;
use Tests\TestCase;
use Illuminate\Support\Facades\{DB,Cache,Event,Http,Mail,Queue,Auth};
use App\Models\User\User;
use App\Models\Wallet\Wallet;
use App\Http\Controllers\Api\v1\EmailVerificationCodeController as Codes;
use App\Services\Wallet\WithdrawalNetworkPolicy;

final class LaunchRemediationTest extends TestCase
{
    protected function setUp(): void {
        parent::setUp();
        $this->assertSame('deepro_test',DB::connection()->getDatabaseName());
        config(['app.readonly'=>false,'mail.default'=>'array','broadcasting.default'=>'log','cache.default'=>'array','session.driver'=>'array']);
        if (!\Illuminate\Support\Facades\Route::has('admin.peerOrdersAppeals.getChat')) \Illuminate\Support\Facades\Route::middleware('web')->group(base_path('app/Modules/P2P/Routes/admin.php'));
        app()->setLocale('zh-cn'); DB::beginTransaction(); Event::fake();Queue::fake();Mail::fake();Http::fake(['*'=>Http::response(['test'=>'external_io_disabled'],503)]);
    }
    protected function tearDown():void { while(DB::transactionLevel()>0)DB::rollBack(); $this->travelBack(); parent::tearDown(); }
    private function user():User { $u=User::withoutEvents(fn()=>User::factory()->create(['email'=>'remediation-'.uniqid().'@example.com','email_verified_at'=>now(),'is_xn'=>false]));$u->assignRole('user');return $u; }
    private function asUser($u):void {$this->flushSession();Auth::forgetGuards();$u->withAccessToken(new \Laravel\Sanctum\TransientToken());$this->actingAs($u,'web');}
    private function code(string $email,string $code='314159',int $minutes=5):void {Cache::put('register_email_code:'.md5($email),['code'=>$code,'type'=>'email','email'=>$email,'expires_at'=>now()->addMinutes($minutes)->toIso8601String(),'purpose'=>'identity_verification','attempts'=>0],600);}
    private function wallet($u):Wallet {return Wallet::firstOrCreate(['user_id'=>$u->id,'currency_id'=>2],['balance_in_wallet'=>'0','balance_in_trade'=>'0','balance_in_order'=>'0','balance_in_withdraw'=>'0','balance_in_virtual_wallet'=>'0','balance_in_virtual_trade'=>'0','balance_in_virtual_order'=>'0']);}
    private function fund($u,string $balance='wallet'):Wallet {$u->is_xn=true;$u->save();$w=$this->wallet($u);$this->asUser(User::role('superadmin')->firstOrFail());$this->postJson('/exchange-control-panel/reports/wallets/transfer',['idempotency_key'=>(string)\Illuminate\Support\Str::uuid(),'wallet'=>$w->id,'amount'=>'1000','account_type'=>'virtual','balance_type'=>$balance,'note'=>'Local remediation regression, rolled back'])->assertOk();$this->asUser($u->fresh());return $w->refresh();}
    public function test_codes_expire_bind_recipient_limit_attempts_and_cannot_replay():void {
        $email='remediation@example.com';$this->assertFalse(Codes::verifyEmailCode($email,'314159'));
        $this->code($email);$this->assertFalse(Codes::verifyEmailCode('other@example.com','314159'));
        $this->assertTrue(Codes::verifyEmailCode($email,'314159'));$this->assertFalse(Codes::verifyEmailCode($email,'314159'));
        $this->code($email,'314159',-1);$this->assertFalse(Codes::verifyEmailCode($email,'314159'));
        $this->code($email);for($i=0;$i<5;$i++)$this->assertFalse(Codes::verifyEmailCode($email,'123123'));
        $this->assertFalse(Codes::verifyEmailCode($email,'314159'));
    }
    public function test_registration_requires_sent_code_and_persists_verified_email():void {
        $email='remediation-registration@gmail.com';$base=['email'=>$email,'password'=>'Remediation@Test2026!','password_confirmation'=>'Remediation@Test2026!','terms'=>true];
        $this->postJson('/register',$base+['email_code'=>'999888'])->assertStatus(422);
        $this->assertDatabaseMissing('users',['email'=>$email]);$this->code($email);
        $this->postJson('/register',$base+['email_code'=>'314159'])->assertStatus(201);
        $u=User::where('email',$email)->firstOrFail();$this->assertNotNull($u->email_verified_at);$this->assertTrue($u->hasRole('user'));
        $this->assertFalse(Codes::verifyEmailCode($email,'314159'));
    }
    public function test_email_reports_smtp_acceptance_and_rate_limits():void {
        $email='remediation-send@gmail.com';
        $this->postJson('/api/v1/register/email/code/send',['type'=>'email','email'=>$email])->assertOk()->assertJsonPath('delivery_status','smtp_accepted');
        Mail::assertSent(\App\Mail\EmailVerificationCodeMail::class);
        $this->assertSame('smtp_accepted',Cache::get('verification_mail_delivery:'.md5($email))['status']);
        $this->postJson('/api/v1/register/email/code/send',['type'=>'email','email'=>$email])->assertStatus(429);
    }
    public function test_failed_smtp_clears_code_and_does_not_claim_sent():void {
        $email='remediation-failed@gmail.com';$pending=\Mockery::mock();$pending->shouldReceive('send')->once()->andThrow(new \RuntimeException('test transport failure'));
        Mail::shouldReceive('to')->once()->with($email)->andReturn($pending);
        $this->postJson('/api/v1/register/email/code/send',['type'=>'email','email'=>$email])->assertStatus(500);
        $this->assertNull(Cache::get('register_email_code:'.md5($email)));$this->assertSame('failed',Cache::get('verification_mail_delivery:'.md5($email))['status']);
    }
    public function test_withdrawal_wrong_network_rejected_and_closed_network_blocks_approval():void {
        $u=$this->user();$u->kyc_verified_at=now();$u->save();$w=$this->fund($u);
        $payload=['symbol'=>'USDT','amount'=>'20','network'=>5,'address'=>'0x000000000000000000000000000000000000dEaD'];
        $this->postJson('/api/v1/wallets/withdraw',$payload)->assertStatus(422);$this->assertDatabaseMissing('withdrawals',['user_id'=>$u->id]);
        $this->postJson('/api/v1/wallets/withdraw',array_replace($payload,['network'=>6]))->assertOk();
        $wd=DB::table('withdrawals')->where('user_id',$u->id)->first();$this->assertNotNull($wd);
        DB::table('networks')->where('id',6)->update(['withdraw_status'=>false]);
        $this->asUser(User::role('superadmin')->firstOrFail());
        $this->putJson('/exchange-control-panel/reports/withdrawals/'.$wd->id,['action'=>'approve'])->assertStatus(422);
        $this->assertSame('waiting_approval',DB::table('withdrawals')->where('id',$wd->id)->value('status'));
        $this->putJson('/exchange-control-panel/reports/withdrawals/'.$wd->id,['action'=>'reject','reason'=>'Local test'])->assertRedirect();
        $this->putJson('/exchange-control-panel/reports/withdrawals/'.$wd->id,['action'=>'reject','reason'=>'Repeated'])->assertRedirect();
        $w->refresh();$this->assertSame(0,bccomp($w->balance_in_virtual_wallet,'1000',18));$this->assertSame(0,bccomp($w->balance_in_withdraw,'0',18));
    }
    public function test_network_policy_requires_contract_and_disabled_currency_is_blocked():void {
        $p=app(WithdrawalNetworkPolicy::class);$this->assertNull($p->error(2,6));
        DB::table('currencies')->where('id',2)->update(['bep_contract'=>'']);$this->assertNotNull($p->error(2,6));
        DB::table('currencies')->where('id',2)->update(['withdraw_status'=>false]);$this->assertNotNull($p->error(2,6));
    }
    public function test_no_liquidity_rejection_leaves_no_virtual_frozen_fee():void {
        $u=$this->user();$w=$this->fund($u,'trade');$m=\App\Models\Market\Market::where('name','USDC-USDT')->firstOrFail();
        DB::table('orders')->where('market_id',$m->id)->delete();Cache::put('market.'.$m->id.'.last','1');Cache::forget('markets_liquidity.USDC-USDT.asks');Cache::forget('markets_liquidity.USDC-USDT.bids');
        $this->postJson('/api/v1/orders',['market'=>'USDC-USDT','type'=>'market','side'=>'buy','quoteQuantity'=>'10'])->assertStatus(422);
        $this->assertDatabaseMissing('orders',['user_id'=>$u->id]);
        $w->refresh();$this->assertSame(0,bccomp($w->balance_in_virtual_trade,'1000',18));$this->assertSame(0,bccomp($w->balance_in_virtual_order,'0',18));$this->assertSame(0,bccomp($w->balance_in_trade,'0',18));
    }
    public function test_admin_repaired_pages_and_parameter_validation():void {
        $this->asUser(User::role('superadmin')->firstOrFail());
        foreach(['dashboard','users','positions','orders','trades','deposits','withdrawals','commissions','reports'] as $page)$this->get('/exchange-control-panel/team/'.$page)->assertOk()->assertSee('Deepro');
        foreach(['guess-games'=>'此产品已隐藏，仅保留历史记录查询。','guess-orders'=>'已隐藏产品的历史记录查询。'] as $page=>$notice)$this->get('/exchange-control-panel/'.$page)->assertOk()->assertSee(__($notice));
        $this->getJson('/exchange-control-panel/peer-trades-chat')->assertStatus(422);
    }
    public function test_team_scope_excludes_another_leaders_member():void {
        $leader=$this->user();$leader->assignRole('user_leader');$inside=$this->user();$inside->referral_id=$leader->id;$inside->save();$outside=$this->user();
        $this->asUser($leader);$this->get('/exchange-control-panel/team/users')->assertOk()->assertSee($inside->email)->assertDontSee($outside->email);
        $this->get('/exchange-control-panel/team/users/'.$outside->id)->assertForbidden();
    }
    public function test_ordinary_user_cannot_access_admin_or_team_reports():void {
        $this->asUser($this->user());foreach(['/exchange-control-panel','/exchange-control-panel/team/users','/exchange-control-panel/umi/business','/exchange-control-panel/settings'] as $path){$r=$this->getJson($path);$this->assertSame(403,$r->status(),$path.' roles='.auth()->user()->getRoleNames()->toJson());}
    }
    public function test_all_routes_reference_real_controller_methods():void {
        foreach(\Illuminate\Support\Facades\Route::getRoutes() as $route){$action=$route->getActionName();if(!str_contains($action,'@'))continue;[$class,$method]=explode('@',$action,2);$this->assertTrue(class_exists($class),$action);$this->assertTrue(method_exists($class,$method),$action);}
    }

    public function test_decimal_normalization_keeps_18_digits_and_exponents():void {
        $this->assertSame('100.000000000000000001',\App\Support\Decimal::normalize('100.000000000000000001'));
        $this->assertSame('999.8005',\App\Support\Decimal::normalize('999.800500000000000000'));
        $this->assertSame('0.00000001',\App\Support\Decimal::normalize('1e-8'));
        $this->assertSame('7',\App\Support\Decimal::normalize('7.90',0));
    }
    public function test_futures_virtual_same_price_close_has_exact_fee_arithmetic():void {
        $u=$this->user();$w=$this->fund($u,'trade');$m=\App\Models\Market\Market::where('name','ETH-USDT')->firstOrFail();
        Cache::put('market.'.$m->id.'.last','2500');Cache::put('markets_liquidity.ETH-USDT.asks',collect([['price'=>'2500','quantity'=>'100']]));Cache::put('markets_liquidity.ETH-USDT.bids',collect([['price'=>'2500','quantity'=>'100']]));
        $r=$this->postJson('/api/v1/futures',['market'=>'ETH-USDT','type'=>'market','side'=>'buy','quoteQuantity'=>'20','leverage'=>2])->assertOk();
        $this->postJson('/api/v1/orders/futures/cancel',['uuid'=>$r->json('message')])->assertOk();
        $w->refresh();$this->assertSame(0,bccomp($w->balance_in_virtual_trade,'999.8005',18));$this->assertSame(0,bccomp($w->balance_in_virtual_order,'0',18));
    }

    public function test_admin_content_crud_network_switch_and_logo_pages():void {
        $this->asUser(User::role('superadmin')->firstOrFail());$payload=['title'=>'Local remediation draft','slug'=>'local-remediation-draft','content'=>'Local regression only','status'=>false,'is_html'=>false,'mode'=>'draft','reason'=>'Isolated content workflow regression'];
        $this->postJson('/exchange-control-panel/pages',$payload)->assertRedirect();$id=DB::table('pages')->where('slug',$payload['slug'])->value('id');$this->assertNotNull($id);
        $payload['title']='Updated local draft';$payload['id']=$id;$payload['revision']=app(\App\Services\Content\PagePublication::class)->revision(\App\Models\Page\Page::findOrFail($id));$this->putJson('/exchange-control-panel/pages/'.$id,$payload)->assertRedirect();$this->assertSame($payload['title'],app(\App\Services\Content\PagePublication::class)->draft($id)['title']);$this->assertDatabaseHas('pages',['id'=>$id,'title'=>'Local remediation draft','status'=>false]);
        $this->deleteJson('/exchange-control-panel/pages/'.$id)->assertRedirect();$this->assertDatabaseMissing('pages',['id'=>$id]);
        $n=DB::table('networks')->where('id',6)->first();$this->putJson('/exchange-control-panel/networks/6',['id'=>6,'name'=>$n->name,'slug'=>$n->slug,'status'=>true,'deposit_status'=>false,'withdraw_status'=>false])->assertRedirect();$this->assertDatabaseHas('networks',['id'=>6,'deposit_status'=>false,'withdraw_status'=>false]);
        $this->asUser($this->user());foreach(['deposit','withdraw'] as $type)foreach(['USDT','AAPLon'] as $symbol)$this->get('/wallets/'.$type.'/crypto/'.$symbol)->assertOk();
    }
    public function test_login_transfer_and_umi_round_trip_remain_usable():void {
        $u=$this->user();$this->postJson('/login',['email'=>$u->email,'password'=>'incorrect'])->assertStatus(422);
        $this->postJson('/login',['email'=>$u->email,'password'=>'password'])->assertOk();$this->postJson('/logout')->assertStatus(204);
        $w=$this->fund($u);$this->postJson('/api/v1/wallets/transfer',['currency_id'=>2,'amount'=>'100','direction'=>'to_trade'])->assertOk();$w->refresh();
        $this->assertSame(0,bccomp($w->balance_in_virtual_wallet,'900',18));$this->assertSame(0,bccomp($w->balance_in_virtual_trade,'100',18));$this->assertSame(0,bccomp($w->balance_in_trade,'0',18));
        foreach(['-1','9999'] as $amount)$this->postJson('/api/v1/wallets/transfer',['currency_id'=>2,'amount'=>$amount,'direction'=>'to_trade'])->assertStatus(422);
        config(['umi-business.enabled'=>true,'umi-business.funded_live'=>true]);$engine=app(\App\Services\Umi\Business\Engine::class);$custody=app(\App\Services\Umi\Business\CustodyTransfers::class);
        // Freeze this snapshot-based regression at its next business day; do not bypass settlement guards.
        $this->travelTo(\Carbon\Carbon::parse(DB::table('umi_business_state')->where('id',1)->value('business_date'),config('umi-business.defaults.timezone'))->addDay()->setTime(12,0));
        $key=fn()=>(string)\Illuminate\Support\Str::uuid();
        $sponsor=$this->user();$sponsorCode='U'.strtoupper(bin2hex(random_bytes(6)));
        DB::table('umi_business_accounts')->insert(['user_id'=>$sponsor->id,'code'=>$sponsorCode,'fixture'=>false,'created_at'=>now(),'updated_at'=>now()]);
        $engine->enroll($u->id,$sponsorCode,$key());
        try {$custody->transfer($u->id,'USDT','in','10',$key());$this->fail('Virtual funds entered UMI real ledger');}catch(\Illuminate\Validation\ValidationException $e){$this->assertNotEmpty($e->errors());}
        DB::table('wallets')->where('id',$w->id)->update(['balance_in_wallet'=>'100.000000000000000001']);$requestId=$key();$first=$custody->transfer($u->id,'USDT','in','10.000000000000000001',$requestId);$again=$custody->transfer($u->id,'USDT','in','10.000000000000000001',$requestId);$this->assertSame($first['id'],$again['id']);
        $custody->transfer($u->id,'USDT','out','10.000000000000000001',$key());$w->refresh();$this->assertSame(0,bccomp($w->balance_in_wallet,'100.000000000000000001',18));$this->assertTrue($engine->ledger->audit()['ok']);
    }
    public function test_107_admin_get_routes_do_not_throw_server_errors():void {
        $this->asUser(User::role('superadmin')->firstOrFail());$results=[];
        foreach(json_decode(file_get_contents(base_path('tests/Fixtures/deepro-admin-pages.json')),true) as $uri) {
            $r=$this->get($uri,['Accept'=>'text/html']);$results[]=['uri'=>$uri,'status'=>$r->status()];$this->assertLessThan(500,$r->status(),$uri);
        }
        file_put_contents('/tmp/remediation-admin-results.json',json_encode($results,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
    }
    public function test_sequence_repair_advances_unowned_sequence_and_is_repeatable():void {
        DB::statement('CREATE SEQUENCE remediation_probe_seq START 1');DB::statement("CREATE TABLE remediation_probe (id bigint PRIMARY KEY DEFAULT nextval('remediation_probe_seq'))");DB::table('remediation_probe')->insert(['id'=>10]);
        $this->artisan('deepro:database-sequences',['--json'=>true])->assertExitCode(1);
        $this->assertSame(1,(int)DB::selectOne('SELECT last_value FROM remediation_probe_seq')->last_value);
        $this->artisan('deepro:database-sequences',['--apply'=>true,'--json'=>true])->assertExitCode(0);
        $next=DB::selectOne('INSERT INTO remediation_probe DEFAULT VALUES RETURNING id')->id;$this->assertSame(11,(int)$next);
        $this->assertNotNull(DB::selectOne("SELECT pg_get_serial_sequence('remediation_probe','id') AS seq")->seq);
        $this->artisan('deepro:database-sequences',['--apply'=>true,'--json'=>true])->assertExitCode(0);
    }

    public function test_bridge_http_200_with_failed_body_is_not_a_success(): void {
        $cases=[
            [200,['success'=>false],false], [200,['message'=>'Not successful'],false],
            [200,['error'=>'RPC failed'],false], [200,['unrelated'=>'ok'],false],
            [503,['success'=>true],false], [200,['success'=>true],true],
            [200,['message'=>'Started'],true], [200,['transactionHash'=>'abc'],true],
        ];
        foreach($cases as [$status,$body,$expected]) {
            $response=new \Illuminate\Http\Client\Response(new \GuzzleHttp\Psr7\Response($status,['Content-Type'=>'application/json'],json_encode($body)));
            $this->assertSame($expected,\App\Services\Wallet\BridgeResponse::accepted($response));
        }
    }

    public function test_tron_checksum_and_evm_prefix_are_required(): void {
        $this->asUser($this->user());
        $rule=new \App\Http\Requests\Web\Wallet\Rules\WithdrawAddressValidationRule;
        request()->merge(['network'=>NETWORK_TRC]);
        $address='T9yD14Nj9j7xAB4dbGeiX9h8unkKHxuWwb';
        $this->assertTrue($rule->passes('address',$address));
        $this->assertFalse($rule->passes('address',substr($address,0,-1).'c'));
        request()->merge(['network'=>NETWORK_ERC]);
        $this->assertTrue($rule->passes('address','0x'.str_repeat('1',40)));
        $this->assertFalse($rule->passes('address',str_repeat('1',40)));
    }

    public function test_wallet_readiness_does_not_treat_http_200_error_as_ready(): void {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['*'=>Http::response(['error'=>'bad credentials'],200)]);
        $health=app(\App\Services\Wallet\WalletReadiness::class)->inspect();
        $this->assertEqualsCanonicalizing(['ethereum','bsc','tron','polygon','xlayer','solana','ton','bitcoin','ripple'],array_column($health['chains'],'chain'));
        foreach($health['chains'] as $row) {
            $this->assertFalse($row['service']['rpc']??false);
            $this->assertSame('needs_configuration',$row['status']);
        }
    }
    public function test_bitcoin_loaded_signer_does_not_hide_unsynced_chain(): void {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['*'=>function ($request) {
            if (($request['method']??null)==='getblockchaininfo') return Http::response(['error'=>null,'result'=>['chain'=>'main','blocks'=>0,'initialblockdownload'=>true,'verificationprogress'=>0]]);
            if (($request['method']??null)==='getwalletinfo') return Http::response(['error'=>null,'result'=>['walletname'=>'deepro','private_keys_enabled'=>true]]);
            return Http::response(['error'=>'not configured'],503);
        }]);
        $row=collect(app(\App\Services\Wallet\WalletReadiness::class)->inspect()['chains'])->firstWhere('chain','bitcoin');
        $this->assertTrue($row['service']['rpc']);$this->assertTrue($row['service']['wallet_loaded']);
        $this->assertTrue($row['wallet_key_configured']);$this->assertFalse($row['service']['chain_synced']);
        $this->assertContains(__('Bitcoin Core 主网区块尚未同步完成'),$row['issues']);$this->assertSame('needs_configuration',$row['status']);
    }
    public function test_bitcoin_locked_wallet_is_not_reported_as_signable(): void {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['*'=>function ($request) {
            if (($request['method']??null)==='getblockchaininfo') return Http::response(['error'=>null,'result'=>['chain'=>'main','blocks'=>900000,'initialblockdownload'=>false,'verificationprogress'=>1]]);
            if (($request['method']??null)==='getwalletinfo') return Http::response(['error'=>null,'result'=>['walletname'=>'deepro','private_keys_enabled'=>true,'unlocked_until'=>0]]);
            return Http::response(['error'=>'not configured'],503);
        }]);
        $row=collect(app(\App\Services\Wallet\WalletReadiness::class)->inspect()['chains'])->firstWhere('chain','bitcoin');
        $this->assertTrue($row['service']['chain_synced']);$this->assertFalse($row['wallet_key_configured']);
        $this->assertContains(__('Bitcoin Core 钱包只读或处于锁定状态'),$row['issues']);
    }
}
