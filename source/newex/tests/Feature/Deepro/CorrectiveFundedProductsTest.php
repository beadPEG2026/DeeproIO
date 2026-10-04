<?php
namespace Tests\Feature\Deepro;
use Tests\TestCase;
use App\Models\{Staking\Staking,Staking\StakingUser,Currency\Currency,User\User,Wallet\Wallet};
use App\Services\{Staking\FundedTermProduct,Operations\StakingConfiguration};
use Illuminate\Support\Facades\{DB,Event,Mail,Queue};
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
final class CorrectiveFundedProductsTest extends TestCase
{
    private $product;private $user;private $operator;private $source;private $fund;
    protected function setUp():void
    {
        parent::setUp();$this->assertSame('deepro_test',DB::connection()->getDatabaseName());DB::beginTransaction();(require database_path('migrations/2026_06_03_000001_add_meta_to_staking_users_table.php'))->up();if(!\Illuminate\Support\Facades\Schema::hasTable('staking_reward_receipts'))(require database_path('migrations/2026_09_21_050000_staking_reward_receipts.php'))->up();Event::fake();Mail::fake();Queue::fake();config(['app.readonly'=>false]);
        Carbon::setTestNow('2026-10-02 12:00:00');
        $c=Currency::factory()->create();$this->user=User::factory()->create(['is_xn'=>false]);$this->operator=User::factory()->create(['is_xn'=>false]);
        $this->source=Wallet::factory()->create(['user_id'=>$this->user->id,'currency_id'=>$c->id,'balance_in_trade'=>'1','balance_in_wallet'=>'2']);
        $this->fund=Wallet::factory()->create(['user_id'=>$this->operator->id,'currency_id'=>$c->id,'balance_in_trade'=>'0.1']);
        $this->product=Staking::factory()->create(['currency_id'=>$c->id,'allowed_days'=>'30','rewards_percentage'=>'0.1','min_amount'=>'0.0001','max_amount'=>'1','status'=>'active','staking_type'=>0]);
        $this->controls(['funded_term'=>true,'reward_user_id'=>$this->operator->id,'pool_limit'=>'10']);
    }
    private function controls(array $c):void {DB::table('settings')->updateOrInsert(['key'=>'staking.configuration.'.$this->product->id],['value'=>json_encode($c)]);}
    private function subscribe():StakingUser{return app(FundedTermProduct::class)->subscribe($this->product,$this->user,'0.5',30);}
    protected function tearDown():void {Carbon::setTestNow();while(DB::transactionLevel()>0)DB::rollBack();parent::tearDown();}
    public function test_unfunded_product_is_visible_but_cannot_debit_principal():void
    {
        $this->controls(['funded_term'=>true,'reward_user_id'=>null,'pool_limit'=>'10']);
        $this->assertFalse(app(FundedTermProduct::class)->availability($this->product)['ready']);
        try{$this->subscribe();$this->fail('unfunded purchase');}catch(ValidationException $e){}
        $this->assertEquals('1',$this->source->fresh()->balance_in_trade);$this->assertSame(0,StakingUser::where('staking_id',$this->product->id)->count());
    }
    public function test_subscription_reserves_full_reward_and_early_redemption_conserves_balances():void
    {
        $s=$this->subscribe();$this->assertEquals('0.5',$this->source->fresh()->balance_in_trade);$this->assertEquals('0.0995',$this->fund->fresh()->balance_in_trade);
        $this->assertSame('0.00050000',$s->meta['reserved_reward']);$this->assertEquals('2',$this->source->fresh()->balance_in_wallet);
        Carbon::setTestNow('2026-10-12 12:00:00');$f=app(FundedTermProduct::class);$f->settle($s->id);$this->assertGreaterThan(0,(float)$s->fresh()->reward);
        $this->assertTrue($f->settle($s->id,$this->user->id));$this->assertFalse($f->settle($s->id,$this->user->id));
        $this->assertEquals('1',$this->source->fresh()->balance_in_trade);$this->assertEquals('0.1',$this->fund->fresh()->balance_in_trade);$this->assertEquals('0',$s->fresh()->reward);
    }
    public function test_maturity_pays_reserved_reward_once_despite_later_configuration_changes():void
    {
        $s=$this->subscribe();$this->controls(['funded_term'=>true,'reward_user_id'=>null,'pool_limit'=>'10']);
        Carbon::setTestNow('2026-11-02 00:00:00');$f=app(FundedTermProduct::class);$this->assertTrue($f->settle($s->id));$this->assertFalse($f->settle($s->id));
        $this->assertEquals('1.0005',$this->source->fresh()->balance_in_trade);$this->assertEquals('0.0995',$this->fund->fresh()->balance_in_trade);$this->assertSame('completed',$s->fresh()->status);
    }
    public function test_insufficient_reserve_and_duplicate_purchase_are_atomic():void
    {
        $this->fund->update(['balance_in_trade'=>'0']);
        try{$this->subscribe();$this->fail('reserve underfunded');}catch(ValidationException $e){}
        $this->assertEquals('1',$this->source->fresh()->balance_in_trade);
        $this->fund->update(['balance_in_trade'=>'0.1']);$this->subscribe();
        try{$this->subscribe();$this->fail('duplicate stake');}catch(ValidationException $e){}
        $this->assertEquals('0.5',$this->source->fresh()->balance_in_trade);$this->assertEquals('0.0995',$this->fund->fresh()->balance_in_trade);
    }
    public function test_daily_accrual_retries_do_not_duplicate_receipts_and_legacy_command_skips_funded_positions():void
    {
        $s=$this->subscribe();Carbon::setTestNow('2026-10-05 12:00:00');$f=app(FundedTermProduct::class);$f->settle($s->id);$f->settle($s->id);
        $this->assertSame(1,DB::table('staking_reward_receipts')->where('stake_id',$s->id)->count());
        $before=(string)$s->fresh()->reward;config(['deposits.staking_rewards_active_from'=>'2026-10-01']);$this->artisan('staking:rewards-calculate --apply')->assertSuccessful();$this->assertSame($before,(string)$s->fresh()->reward);
    }
    public function test_pool_limits_and_owner_checks_preserve_both_wallets():void
    {
        $this->controls(['funded_term'=>true,'reward_user_id'=>$this->operator->id,'pool_limit'=>'0.4']);
        try{$this->subscribe();$this->fail('pool capacity exceeded');}catch(ValidationException $e){}
        $this->assertEquals('1',$this->source->fresh()->balance_in_trade);$this->assertEquals('0.1',$this->fund->fresh()->balance_in_trade);
        $this->controls(['funded_term'=>true,'reward_user_id'=>$this->operator->id,'pool_limit'=>'10']);$s=$this->subscribe();
        try{app(FundedTermProduct::class)->settle($s->id,$this->operator->id);$this->fail('other owner redeemed');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(403,$e->getStatusCode());}
        $this->assertSame('active',$s->fresh()->status);$this->assertEquals('0.5',$this->source->fresh()->balance_in_trade);
    }

    public function test_public_api_uses_spot_balance_and_respects_unfunded_and_early_redemption_rules():void
    {
        $this->source->update(['balance_in_wallet'=>'0']);
        \Laravel\Sanctum\Sanctum::actingAs($this->user,['trade']);
        $this->controls(['funded_term'=>true,'reward_user_id'=>null,'pool_limit'=>'10']);
        $this->postJson('/api/v1/staking/submit',['id'=>$this->product->id,'days'=>30,'amount'=>'0.5'])->assertStatus(422);
        $this->controls(['funded_term'=>true,'reward_user_id'=>$this->operator->id,'pool_limit'=>'10']);
        $this->postJson('/api/v1/staking/submit',['id'=>$this->product->id,'days'=>30,'amount'=>'0.5'])->assertOk();
        $s=StakingUser::where('staking_id',$this->product->id)->sole();
        $this->assertEquals('0.5',$this->source->fresh()->balance_in_trade);
        $this->postJson('/api/v1/staking/redeem',['id'=>$s->id])->assertOk();
        $this->assertEquals('1',$this->source->fresh()->balance_in_trade);$this->assertEquals('0',$this->source->fresh()->balance_in_wallet);
    }


    private function platform():void {$this->controls(['funded_term'=>true,'reward_funding'=>'platform','reward_user_id'=>null,'pool_limit'=>'10']);}

    public function test_platform_subscription_needs_no_reserve_and_pays_booked_reward_once():void
    {
        $this->platform();$this->fund->update(['balance_in_trade'=>'0']);
        $this->assertTrue(app(FundedTermProduct::class)->availability($this->product)['ready']);
        $s=$this->subscribe();$this->assertNull($s->meta['reward_wallet_id']);$this->assertSame('0',$s->meta['reserved_reward']);
        $this->assertSame('0.00050000',$s->meta['platform_reward_due']);$this->assertEquals('0.5',$this->source->fresh()->balance_in_trade);
        $this->controls(['funded_term'=>true,'reward_user_id'=>null,'pool_limit'=>'10']);$this->product->update(['rewards_percentage'=>'10']);
        Carbon::setTestNow('2026-11-02 00:00:00');$f=app(FundedTermProduct::class);$this->assertTrue($f->settle($s->id));$this->assertFalse($f->settle($s->id));
        $this->assertEquals('1.0005',$this->source->fresh()->balance_in_trade);$this->assertEquals('0',$this->fund->fresh()->balance_in_trade);
        $this->assertSame('0',$s->fresh()->meta['platform_reward_due']);$this->assertSame('0.00050000',$s->fresh()->meta['platform_reward_expense']);
        $event=DB::table('operations_events')->where('object_type','funded_staking')->where('object_id',(string)$s->id)->where('action','matured')->sole();
        $this->assertSame('0.00050000',json_decode($event->changes,true)['platform_reward_expense']);
    }
    public function test_platform_api_redeems_only_principal_and_cancels_rewards():void
    {
        $this->platform();\Laravel\Sanctum\Sanctum::actingAs($this->user,['trade']);
        $this->postJson('/api/v1/staking/submit',['id'=>$this->product->id,'days'=>30,'amount'=>'0.5'])->assertOk();
        $s=StakingUser::where('staking_id',$this->product->id)->sole();Carbon::setTestNow('2026-10-12 12:00:00');
        app(FundedTermProduct::class)->settle($s->id);$this->assertGreaterThan(0,(float)$s->fresh()->reward);
        $this->postJson('/api/v1/staking/redeem',['id'=>$s->id])->assertOk();
        $this->assertEquals('1',$this->source->fresh()->balance_in_trade);$this->assertEquals('0.1',$this->fund->fresh()->balance_in_trade);
        $this->assertSame('0',$s->fresh()->meta['platform_reward_expense']);$this->assertSame('0',$s->fresh()->meta['platform_reward_due']);
    }
    public function test_platform_mode_still_enforces_balance_duplicates_capacity_and_pause():void
    {
        $this->platform();$this->source->update(['balance_in_trade'=>'0']);
        try{$this->subscribe();$this->fail('insufficient principal');}catch(ValidationException $e){}
        $this->assertSame(0,StakingUser::where('staking_id',$this->product->id)->count());
        $this->source->update(['balance_in_trade'=>'1']);$this->subscribe();
        try{$this->subscribe();$this->fail('duplicate');}catch(ValidationException $e){}
        $this->assertEquals('0.5',$this->source->fresh()->balance_in_trade);
        $this->controls(['funded_term'=>true,'reward_funding'=>'platform','pool_limit'=>'0.5']);
        $this->assertFalse(app(FundedTermProduct::class)->availability($this->product)['ready']);
        $this->product->update(['status'=>'hidden']);$this->platform();
        $this->assertFalse(app(FundedTermProduct::class)->availability($this->product->fresh())['ready']);
    }
    public function test_old_reserved_snapshot_still_returns_reserve_after_product_is_opened():void
    {
        $s=$this->subscribe();$meta=$s->meta;unset($meta['reward_funding'],$meta['term_reward'],$meta['platform_reward_due']);$s->meta=$meta;$s->save();
        $this->platform();$this->assertTrue(app(FundedTermProduct::class)->settle($s->id,$this->user->id));
        $this->assertEquals('1',$this->source->fresh()->balance_in_trade);$this->assertEquals('0.1',$this->fund->fresh()->balance_in_trade);
    }
    public function test_admin_edit_preserves_platform_mode():void
    {
        $this->platform();$this->product=$this->product->fresh();$service=app(StakingConfiguration::class);$data=$this->product->only(StakingConfiguration::FIELDS);
        $service->save($this->product,$data,['revision'=>$service->revision($this->product),'reason'=>'Edit offer cap','pool_limit'=>'12','apr_limit'=>5],$this->operator->id);
        $this->assertSame('platform',$service->controls($this->product->id)['reward_funding']);
        $this->assertTrue(app(FundedTermProduct::class)->availability($this->product->fresh())['ready']);
    }


    public function test_opening_command_is_explicit_idempotent_and_does_not_move_money():void
    {
        $ids=[];
        foreach(['BTC','ETH'] as $symbol){
            $currency=Currency::where('symbol',$symbol)->first()??Currency::factory()->create(['symbol'=>$symbol]);
            $p=Staking::factory()->create(['currency_id'=>$currency->id,'staking_type'=>0,'status'=>'active','min_amount'=>'0.001','max_amount'=>'1','allowed_days'=>'30','rewards_percentage'=>'0.1']);$ids[]=$p->id;
            DB::table('settings')->updateOrInsert(['key'=>'staking.featured_term.'.$symbol],['value'=>(string)$p->id]);
            DB::table('settings')->updateOrInsert(['key'=>'staking.configuration.'.$p->id],['value'=>json_encode(['funded_term'=>true,'reward_user_id'=>null,'pool_limit'=>'10'])]);
        }
        $this->artisan('staking:open-featured')->assertSuccessful();
        $this->assertArrayNotHasKey('reward_funding',app(StakingConfiguration::class)->controls($ids[0]));
        $this->artisan('staking:open-featured --apply')->assertSuccessful();$this->artisan('staking:open-featured --apply')->assertSuccessful();
        foreach($ids as $id){$p=Staking::findOrFail($id);$this->assertTrue(app(FundedTermProduct::class)->availability($p)['ready']);}
        $this->assertSame(2,DB::table('operations_events')->where('action','open_platform_rewards')->whereIn('object_id',array_map('strval',$ids))->count());
        $this->assertEquals('1',$this->source->fresh()->balance_in_trade);$this->assertEquals('0.1',$this->fund->fresh()->balance_in_trade);
    }
}
