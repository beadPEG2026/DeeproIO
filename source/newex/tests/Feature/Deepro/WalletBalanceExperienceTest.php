<?php
namespace Tests\Feature\Deepro;

use Tests\TestCase;
use App\Models\{User\User,Wallet\Wallet,Currency\Currency};
use App\Http\Resources\Wallet\Wallet as WalletResource;
use App\Services\Performance\ReadModelCacheService;
use Illuminate\Support\Facades\{DB,Cache,Event,Queue,Http};
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;

final class WalletBalanceExperienceTest extends TestCase
{
    private User $user;
    private Wallet $wallet;
    protected function setUp():void {
        parent::setUp();self::assertSame('deepro_test',DB::connection()->getDatabaseName());
        DB::beginTransaction();config(['cache.default'=>'array','broadcasting.default'=>'log']);Event::fake();Queue::fake();Http::preventStrayRequests();
        $this->user=User::withoutEvents(fn()=>User::factory()->create(['email_verified_at'=>now(),'deleted'=>false,'deactivated'=>false,'is_xn'=>false,'is_xm'=>false,'vip'=>0,'referral_id'=>null]));
        $this->wallet=Wallet::factory()->create(['user_id'=>$this->user->id,'currency_id'=>Currency::whereSymbol('USDT')->value('id'),'balance_in_wallet'=>'0','balance_in_trade'=>'19.88672781','balance_in_order'=>'0','balance_in_withdraw'=>'0']);
        Sanctum::actingAs($this->user,['trade']);$this->actingAs($this->user);
    }
    protected function tearDown():void {while(DB::transactionLevel()>0)DB::rollBack();parent::tearDown();}
    public function test_resource_preserves_precision_and_separates_funding_from_trading():void {
        $r=(new WalletResource($this->wallet->fresh()->load('currency','address')))->toArray(Request::create('/'));
        self::assertSame(0,bccomp((string)$r['balance_in_wallet'],'0',18));
        self::assertSame(0,bccomp((string)$r['balance_in_trade'],'19.88672781',18));
        self::assertSame($this->wallet->currency_id,$r['currency_id']);
        $this->wallet->balance_in_wallet='0.000000001234567891';
        $r=(new WalletResource($this->wallet))->toArray(Request::create('/'));
        self::assertSame(0,bccomp($r['balance_in_wallet'],'0.000000001234567891',18));
    }
    public function test_fresh_balance_bypasses_cache_only_for_the_authenticated_owner():void {
        $cache=app(ReadModelCacheService::class);$id=$this->user->id;$request=Request::create('/wallets','GET');app()->instance('request',$request);
        self::assertSame('old',$cache->rememberWallets($id,fn()=>'old'));
        self::assertSame('old',$cache->rememberWallets($id,fn()=>'new'));
        $request->query->set('fresh','1');self::assertSame('new',$cache->rememberWallets($id,fn()=>'new'));
        self::assertSame('other-old',$cache->rememberWallets($id+1000,fn()=>'other-old'));
        self::assertSame('other-old',$cache->rememberWallets($id+1000,fn()=>'other-new'));
    }
    public function test_account_balance_api_rejects_translated_enum_instead_of_reporting_zero():void {
        $id=$this->wallet->currency_id;
        $this->getJson('/api/v1/wallets/balance?currency='.$id.'&type=trade')->assertOk()->assertJsonPath('success',true);
        $this->getJson('/api/v1/wallets/balance?currency='.$id.'&type='.urlencode('下单与撤单'))->assertStatus(422)->assertJsonPath('success',false);
    }
    public function test_funding_transfer_changes_the_actual_accounts_and_replay_does_not_duplicate():void {
        $payload=['currency_id'=>$this->wallet->currency_id,'amount'=>'9.88672781','direction'=>'to_funding','account_type'=>'real','use_virtual_wallet'=>false];
        $first=$this->withHeader('Idempotency-Key','wallet-experience-fixture')->postJson('/api/v1/wallets/transfer',$payload)->assertOk();
        $this->wallet->refresh();self::assertSame(0,bccomp($this->wallet->balance_in_trade,'10',18));
        $funding=(string)$this->wallet->balance_in_wallet;self::assertGreaterThan(0,(float)$funding);
        self::assertLessThanOrEqual(9.88672781,(float)$funding);
        $again=$this->withHeader('Idempotency-Key','wallet-experience-fixture')->postJson('/api/v1/wallets/transfer',$payload)->assertOk();
        self::assertSame($first->getContent(),$again->getContent());self::assertSame(0,bccomp($this->wallet->fresh()->balance_in_wallet,$funding,18));
        self::assertSame(0,bccomp($this->wallet->fresh()->balance_in_trade,'10',18));
    }
}
