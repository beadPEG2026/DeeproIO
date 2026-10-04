<?php
namespace Tests\Feature\Deepro;

use Tests\TestCase;
use App\Models\User\User;
use App\Models\Wallet\Wallet;
use App\Services\Referral\{ExchangeRewards,ExchangeInvitations};
use App\Services\Umi\Business\Engine;
use Illuminate\Support\Facades\{DB,Event,Queue,Mail,Http,Cache};
use Illuminate\Validation\ValidationException;

final class ReferralIsolationTest extends TestCase
{
    protected function setUp(): void {
        parent::setUp(); $this->assertSame('deepro_test',DB::connection()->getDatabaseName());
        config(['app.readonly'=>false,'umi-business.enabled'=>true,'umi-business.funded_live'=>false,'cache.default'=>'array','broadcasting.default'=>'log']);
        DB::beginTransaction();Event::fake();Queue::fake();Mail::fake();Http::fake(['*'=>Http::response([],503)]);
        DB::table('umi_business_state')->where('id',1)->update(['paused'=>false]);
    }
    protected function tearDown(): void {while(DB::transactionLevel()>0) DB::rollBack();parent::tearDown();}
    private function user(?User $parent=null, bool $virtual=false, int $vip=8): User {
        $u=User::withoutEvents(fn()=>User::factory()->create(['email'=>'ref-'.uniqid().'@example.com','referral_id'=>$parent?->id,'referral_code'=>'EX'.strtoupper(bin2hex(random_bytes(5))),'email_verified_at'=>now(),'deactivated'=>false,'deleted'=>false,'is_xn'=>$virtual]));
        DB::table('users')->where('id',$u->id)->update(['vip'=>$vip]);$u->assignRole('user');return $u->fresh();
    }
    private function umi(User $u):object {
        $id=DB::table('umi_business_accounts')->insertGetId(['user_id'=>$u->id,'code'=>'U'.strtoupper(bin2hex(random_bytes(6))),'fixture'=>false,'created_at'=>now(),'updated_at'=>now()]);
        return DB::table('umi_business_accounts')->find($id);
    }
    private function wallet(User $u):Wallet { return Wallet::firstOrCreate(['user_id'=>$u->id,'currency_id'=>2],['balance_in_wallet'=>'0','balance_in_trade'=>'0','balance_in_order'=>'0','balance_in_withdraw'=>'0','balance_in_virtual_wallet'=>'0','balance_in_virtual_trade'=>'0','balance_in_virtual_order'=>'0']); }
    private function rejects(callable $f, string $field):void {try{$f();$this->fail('Invalid operation accepted');}catch(ValidationException $e){$this->assertArrayHasKey($field,$e->errors());}}
    private function record(User $u,string $event='one',string $domain='real',string $business='spot'):string {
        return app(ExchangeRewards::class)->record($business,$event,$event,$u->id,2,'1.00000000',$domain);
    }
    public function test_umi_requires_own_invite_and_leaves_exchange_parent_and_rules_unchanged():void {
        $exchangeParent=$this->user();$umiParent=$this->umi($this->user());$child=$this->user($exchangeParent);$e=app(Engine::class);
        $before=DB::table('umi_business_rules')->get()->toJson();$rewardCount=DB::table('umi_business_rewards')->count();
        $this->rejects(fn()=>$e->enroll($child->id,null,'invalid-empty-umi'), 'parent');
        $this->rejects(fn()=>$e->enroll($child->id,$exchangeParent->referral_code,'invalid-exchange'), 'parent');
        $r=$e->enroll($child->id,$umiParent->code,'valid-umi-enroll');
        $this->assertSame((int)$umiParent->id,(int)$e->owned($child->id)->parent_id);
        $this->assertSame((int)$exchangeParent->id,(int)$child->fresh()->referral_id);
        $this->assertTrue($e->enroll($child->id,$umiParent->code,'valid-umi-enroll')['replayed']);
        $this->rejects(fn()=>$e->enroll($child->id,$umiParent->code,'different-enroll'),'account');
        $this->assertSame($before,DB::table('umi_business_rules')->get()->toJson());
        $this->assertSame($rewardCount,DB::table('umi_business_rewards')->count());
    }
    public function test_umi_rejects_disabled_unactivated_and_self_sponsors():void {
        $u=$this->user();$p=$this->user();$a=$this->umi($p);$e=app(Engine::class);
        DB::table('users')->where('id',$p->id)->update(['deactivated'=>true]);
        $this->rejects(fn()=>$e->enroll($u->id,$a->code,'disabled-sponsor'),'parent');
        DB::table('users')->where('id',$p->id)->update(['deactivated'=>false,'email_verified_at'=>null]);
        $this->rejects(fn()=>$e->enroll($u->id,$a->code,'unverified-sponsor'),'parent');
        $this->rejects(fn()=>app(\App\Services\Umi\Business\Invitations::class)->resolve($p->id,$a->code),'parent');
        $this->assertNull($e->owned($u->id));
    }
    public function test_public_umi_request_cannot_bypass_required_invite_with_fixture_flag():void {
        $u=$this->user();$this->actingAs($u)->postJson('/umi-ecosystem/finance',['action'=>'enroll','request_key'=>'public-no-invite','fixture'=>true])->assertUnprocessable();
        $this->assertNull(app(Engine::class)->owned($u->id));
    }
    public function test_exchange_code_validation_is_independent_and_optional_but_wrong_codes_fail():void {
        $u=$this->user();$umi=$this->umi($u);$s=app(ExchangeInvitations::class);
        $this->assertNull($s->resolve(null));$this->assertSame($u->id,$s->resolve($u->referral_code)->id);
        $this->rejects(fn()=>$s->resolve($umi->code),'referral');$this->rejects(fn()=>$s->resolve(null,true),'referral');
        DB::table('users')->where('id',$u->id)->update(['deactivated'=>true]);$this->rejects(fn()=>$s->resolve($u->referral_code),'referral');
    }
    public function test_all_eight_fee_levels_snapshot_and_replay_once():void {
        $p=null;for($i=0;$i<8;$i++)$p=$this->user($p);$u=$this->user($p);
        $before=DB::table('referral_transactions')->count();$this->assertSame('0.500000000000000000',$this->record($u));$this->record($u);
        $this->assertSame($before+8,DB::table('referral_transactions')->count());
        $event=DB::table('exchange_referral_events')->find('spot:one');$this->assertSame(ExchangeRewards::VERSION,$event->rule_version);
        $this->assertSame(array_values(ExchangeRewards::RATES),array_column(json_decode($event->ancestry,true),'rate'));
        $this->assertSame('0.500000000000000000',$this->record($u,'entry-one','real','futures_entry'));
        $this->assertSame('0.500000000000000000',$this->record($u,'exit-one','real','futures_exit'));
    }
    public function test_vip_depth_eligibility_does_not_skip_levels():void {
        $root=$this->user(null,false,1);$parent=$this->user($root,false,0);$u=$this->user($parent);
        $this->assertSame('0.150000000000000000',$this->record($u));
        $snapshot=json_decode(DB::table('exchange_referral_events')->find('spot:one')->ancestry,true);
        $this->assertFalse($snapshot[1]['eligible']);$this->assertEquals(2,$snapshot[1]['level']);
    }
    public function test_credit_is_atomic_idempotent_and_virtual_zero_never_receives_real_money():void {
        $p=$this->user();$u=$this->user($p);$w=$this->wallet($p);$realBefore=$w->balance_in_wallet;
        $this->record($u,'virtual','virtual');$row=DB::table('referral_transactions')->where('event_key','spot:virtual')->first();$s=app(ExchangeRewards::class);
        $this->assertSame('credited',$s->credit($row->id));$this->assertSame('skipped',$s->credit($row->id));$w->refresh();
        $this->assertSame(0,bccomp($realBefore,$w->balance_in_wallet,18));$this->assertSame(0,bccomp('0.15',$w->balance_in_virtual_trade,18));
        $this->assertSame(1,DB::table('exchange_referral_receipts')->where('referral_id',$row->id)->count());
        $this->record($u,'real','real');$id=DB::table('referral_transactions')->where('event_key','spot:real')->value('id');$s->credit($id);$w->refresh();
        $this->assertSame(0,bccomp(bcadd($realBefore,'0.15',18),$w->balance_in_wallet,18));
        $this->assertSame(0,bccomp('0.15',$w->balance_in_virtual_trade,18));
    }
    public function test_invalid_snapshot_and_legacy_pending_are_held_without_credit():void {
        $p=$this->user();$u=$this->user($p);$w=$this->wallet($p);$this->record($u);
        $row=DB::table('referral_transactions')->where('event_key','spot:one')->first();DB::table('referral_transactions')->where('id',$row->id)->update(['amount'=>'99']);
        $this->assertSame('review',app(ExchangeRewards::class)->credit($row->id));
        $legacy=DB::table('referral_transactions')->insertGetId(['transaction_id'=>'legacy-test','user_id'=>$p->id,'currency_id'=>2,'amount'=>'1','is_credited'=>false]);
        $this->assertSame('review',app(ExchangeRewards::class)->credit($legacy));
        $this->assertSame(0,bccomp('0',$w->refresh()->balance_in_wallet,18));
        $this->artisan('transaction:referral-credits',['--dry-run'=>true])->assertSuccessful();
    }
    public function test_missing_wallet_can_retry_once_it_exists():void {
        $p=$this->user();$u=$this->user($p);$this->record($u);$id=DB::table('referral_transactions')->where('event_key','spot:one')->value('id');
        $this->assertSame('failed',app(ExchangeRewards::class)->credit($id));$w=$this->wallet($p);
        $this->assertSame('credited',app(ExchangeRewards::class)->credit($id));$this->assertSame(0,bccomp('0.15',$w->refresh()->balance_in_wallet,18));
    }
    public function test_delegated_user_editor_cannot_reset_admin_credentials():void {
        $operator=$this->user();$operator->assignRole('perm_users');$target=$this->user();$target->assignRole('superadmin');
        $this->actingAs($operator);$this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        \App\Support\AdminUserAccess::check($target,true);
    }
    public function test_admin_code_status_never_returns_plaintext_code():void {
        $u=$this->user();$u->assignRole('superadmin');$this->actingAs($u);$email='secret-status@example.com';
        Cache::put('register_email_code:'.md5($email),['code'=>'314159','email'=>$email,'type'=>'email','expires_at'=>now()->addMinutes(5)->toIso8601String(),'purpose'=>'identity_verification','attempts'=>0],600);
        $r=$this->postJson('/exchange-control-panel/verification-codes/query',['account'=>$email]);$r->assertOk();
        $this->assertStringNotContainsString('314159',$r->getContent());$r->assertJsonMissingPath('data.code');
    }
    public function test_admin_center_and_public_referral_report_render():void {
        $p=$this->user();$u=$this->user($p);$p->assignRole('superadmin');$this->record($u);
        $this->actingAs($p)->get('/exchange-control-panel/referral-center')->assertOk();
        $this->get('/reports/referral-transactions')->assertOk();
        $this->actingAs($u)->getJson('/exchange-control-panel/referral-center')->assertForbidden();
    }
    public function test_settings_reject_unknown_fields_bad_percentages_and_keep_masked_keys():void {
        $u=$this->user();$u->assignRole('superadmin');$this->actingAs($u);
        $this->putJson('/exchange-control-panel/settings',['invalid'=>['key'=>'value']])->assertUnprocessable();
        $this->putJson('/exchange-control-panel/settings',['trade'=>['maker_fee'=>'-5']])->assertUnprocessable();
        $this->putJson('/exchange-control-panel/settings',['trade'=>['private_override'=>'yes']])->assertUnprocessable();
        $this->putJson('/exchange-control-panel/settings',['unlimit'=>['exchange_rate'=>'0']])->assertUnprocessable();
        $service=new \App\Services\Settings\SettingsService();
        \Setting::shouldReceive('set')->never();
        $source=['private_key'=>['location'=>'database','key'=>'test.private_key']];
        $service->update('private_key',str_repeat('*',20),$source);$service->update('private_key','',$source);
        $this->assertTrue(true);
    }
    public function test_futures_market_merge_and_close_record_distinct_fee_events():void {
        $p=$this->user();$u=$this->user($p,true);$w=$this->wallet($u);DB::table('wallets')->where('id',$w->id)->update(['balance_in_virtual_trade'=>'1000']);$this->wallet($p);
        $m=\App\Models\Market\Market::where('name','ETH-USDT')->firstOrFail();
        Cache::put('market.'.$m->id.'.last','2500');Cache::put('markets_liquidity.ETH-USDT.asks',collect([['price'=>'2500','quantity'=>'100']]));Cache::put('markets_liquidity.ETH-USDT.bids',collect([['price'=>'2500','quantity'=>'100']]));
        $this->actingAs($u);$payload=['market'=>'ETH-USDT','type'=>'market','side'=>'buy','quoteQuantity'=>'20','leverage'=>2];
        $first=$this->postJson('/api/v1/futures',$payload)->assertOk();
        $this->postJson('/api/v1/futures',$payload)->assertOk();
        $this->assertSame(2,DB::table('exchange_referral_events')->where('source_user_id',$u->id)->where('business','futures_entry')->count());
        $this->postJson('/api/v1/orders/futures/cancel',['uuid'=>$first->json('message')])->assertOk();
        $this->assertSame(1,DB::table('exchange_referral_events')->where('source_user_id',$u->id)->where('business','futures_exit')->count());
        $this->assertSame(['virtual'],DB::table('exchange_referral_events')->where('source_user_id',$u->id)->distinct()->pluck('balance_domain')->all());
    }
    public function test_spot_taker_and_maker_use_the_same_multilevel_fee_policy():void {
        $parent=$this->user();$buyer=$this->user($parent);$seller=$this->user($parent);
        $market=\App\Models\Market\Market::where('name','BTC-USDT')->firstOrFail();
        foreach([$buyer,$seller] as $u)foreach([$market->base_currency_id,$market->quote_currency_id] as $c)Wallet::firstOrCreate(['user_id'=>$u->id,'currency_id'=>$c],['balance_in_order'=>'100000','balance_in_trade'=>'0','balance_in_wallet'=>'0']);
        $buy=\App\Models\Order\Order::factory()->create(['user_id'=>$buyer->id,'market_id'=>$market->id,'base_currency_id'=>$market->base_currency_id,'quote_currency_id'=>$market->quote_currency_id,'side'=>'buy','type'=>'limit','price'=>'50000','quantity'=>'1','fee_rate'=>'0.25']);
        $sell=\App\Models\Order\Order::factory()->create(['user_id'=>$seller->id,'market_id'=>$market->id,'base_currency_id'=>$market->base_currency_id,'quote_currency_id'=>$market->quote_currency_id,'side'=>'sell','type'=>'limit','price'=>'50000','quantity'=>'1','fee_rate'=>'0.25']);
        $process=(string)\Illuminate\Support\Str::uuid();
        app(\App\Services\Transaction\TransactionService::class)->process(['process_id'=>$process,'order'=>$buy,'matched_order'=>$sell,'filled_quantity'=>'1','cursor_quantity'=>'1','triggeredField'=>'quantity','initialQuantity'=>'1','is_order_price_greater'=>false,'cursor_remaining'=>'0']);
        $events=DB::table('exchange_referral_events')->whereIn('source_user_id',[$buyer->id,$seller->id])->get();$this->assertCount(2,$events);
        $this->assertSame($events[0]->reward_total,$events[1]->reward_total);
        $this->assertSame('real',$events->firstWhere('source_user_id',$seller->id)->balance_domain);
        $this->assertSame('real',$events->firstWhere('source_user_id',$buyer->id)->balance_domain);
        foreach(DB::table('transactions')->where('process_id',$process)->get() as $t){$event=DB::table('exchange_referral_events')->find('spot:'.$t->id);$this->assertSame(0,bccomp($event->reward_total,$t->referral_fee,18));$this->assertSame(0,bccomp(bcdiv(bcmul($t->fee,'15',26),'100',8),$t->referral_fee,18));}
    }

    public function test_unknown_fee_domain_is_held_and_virtual_provenance_survives_account_changes():void {
        $p=$this->user();$u=$this->user($p);$w=$this->wallet($p);
        $this->record($u,'unknown','unknown','futures_exit');
        $r=DB::table('referral_transactions')->where('event_key','futures_exit:unknown')->first();
        $this->assertSame('review',$r->credit_status);$this->assertSame('review',app(ExchangeRewards::class)->credit($r->id));
        $class=\App\Repositories\Order\OrderRepository::class;
        $repo=(new \ReflectionClass($class))->newInstanceWithoutConstructor();
        $method=new \ReflectionMethod($class,'addFuturesReferralTransactions');$method->setAccessible(true);
        $future=(object)['id'=>'virtual-snapshot','user_id'=>$u->id,'quote_currency_id'=>2,'referral_balance_domain'=>'virtual'];
        $method->invoke($repo,$future,'1','exit','virtual-snapshot');
        $this->assertSame('virtual',DB::table('exchange_referral_events')->find('futures_exit:virtual-snapshot')->balance_domain);
        $this->assertSame(0,bccomp('0',$w->refresh()->balance_in_wallet,18));
    }

}
