<?php

namespace Tests\Feature\Deepro;

use Tests\TestCase;
use App\Models\Staking\StakingUser;
use Illuminate\Support\Facades\{DB, Schema, Event, Mail, Queue, Http};
use Illuminate\Support\Carbon;
final class StakingAccrualSafetyTest extends TestCase
{
    private StakingUser $stake;
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('deepro_test', DB::connection()->getDatabaseName());
        DB::beginTransaction();
        if (!Schema::hasTable('staking_reward_receipts')) {
            (require database_path('migrations/2026_09_21_050000_staking_reward_receipts.php'))->up();
        }
        Event::fake();
        Mail::fake();
        Queue::fake();
        Http::fake(['*' => Http::response([], 503)]);
        Carbon::setTestNow(Carbon::parse('2026-09-21 10:00:00'));
        config(['app.readonly' => false, 'deposits.staking_rewards_active_from' => '2026-09-21']);
        DB::table('staking_users')->update(['status' => 'redeemed']);
        $this->stake = StakingUser::factory()->create(['amount' => '1000', 'apy' => '3', 'days' => 30, 'reward' => '0', 'value_date' => now()->subDays(2)->startOfDay(), 'redemption_date' => now()->addDays(28)->startOfDay()]);
    }
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }
    public function test_default_is_preview_without_mutation(): void
    {
        $this->artisan('staking:rewards-calculate')->assertSuccessful();
        $this->assertEquals(0, $this->stake->fresh()->reward);
        $this->assertSame(0, DB::table('staking_reward_receipts')->count());
    }
    public function test_daily_reward_can_only_be_recorded_once(): void
    {
        $this->artisan('staking:rewards-calculate --apply')->assertSuccessful();
        $this->artisan('staking:rewards-calculate --apply')->assertSuccessful();
        $this->assertEquals(1, $this->stake->fresh()->reward);
        $this->assertSame(1, DB::table('staking_reward_receipts')->where('stake_id', $this->stake->id)->count());
    }
    public function test_missed_days_are_not_replayed(): void
    {
        $this->artisan('staking:rewards-calculate --apply')->assertSuccessful();
        $this->assertEquals(1, $this->stake->fresh()->reward);
        Carbon::setTestNow(now()->addDays(3));
        $this->artisan('staking:rewards-calculate --apply')->assertSuccessful();
        $this->assertEquals(2, $this->stake->fresh()->reward);
    }
    public function test_reward_is_capped_by_period_entitlement(): void
    {
        $this->stake->update(['reward' => '29.5']);
        $this->artisan('staking:rewards-calculate --apply')->assertSuccessful();
        $this->assertEquals(30, $this->stake->fresh()->reward);
    }
    public function test_matured_stake_is_not_paid_again(): void
    {
        $this->stake->forceFill(['redemption_date' => now()->subDay()])->save();
        $this->artisan('staking:rewards-calculate --apply')->assertSuccessful();
        $this->assertEquals(0, $this->stake->fresh()->reward);
    }
    public function test_apply_requires_explicit_cutover(): void
    {
        config(['deposits.staking_rewards_active_from' => null]);
        $this->artisan('staking:rewards-calculate --apply')->assertFailed();
        $this->assertEquals(0, $this->stake->fresh()->reward);
    }
    public function test_readonly_apply_does_not_mutate(): void
    {
        config(['app.readonly' => true]);
        $this->artisan('staking:rewards-calculate --apply')->assertFailed();
        $this->assertEquals(0, $this->stake->fresh()->reward);
    }
    public function test_journal_failure_rolls_back_reward(): void
    {
        DB::statement("ALTER TABLE staking_reward_receipts ADD CONSTRAINT isolated_receipt_failure CHECK (amount < 0)");
        $this->artisan('staking:rewards-calculate --apply')->assertFailed();
        $this->assertEquals(0, $this->stake->fresh()->reward);
    }
}
