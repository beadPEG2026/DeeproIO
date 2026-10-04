<?php

namespace Tests\Unit\Umi\V2;

use App\Services\Umi\V2\CycleStore;
use App\Services\Umi\V2\LocalEngine;
use Illuminate\Config\Repository;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;

/** Full sandbox flow against a fresh SQLite memory DB; no Deepro DB is opened. */
final class LocalEngineTest extends TestCase
{
    private mixed $oldFacadeApplication;
    private mixed $oldContainer;
    private LocalEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->oldFacadeApplication = Facade::getFacadeApplication();
        $this->oldContainer = \Illuminate\Container\Container::getInstance();
        $app = new Application();
        $app->instance('env', 'testing');
        $app->instance('config', new Repository([
            'app' => ['timezone' => 'UTC'],
            'umi-v2' => ['local_acceptance' => true, 'umi_usd_quote' => '1',
                'share_usd_quote' => '0.1', 'points_value_factor' => '0.1',
                'timezone' => 'Asia/Shanghai', 'sandbox_initial_supply_umi' => '100000000',
                'sandbox_terminal_floor_umi' => '21000000'],
        ]));
        $database = new Capsule($app);
        $database->addConnection(['driver' => 'sqlite', 'database' => ':memory:',
            'prefix' => '', 'foreign_key_constraints' => true]);
        $app->instance('db', $database->getDatabaseManager());
        $app->bind('db.schema', static fn (Application $app) => $app['db']->connection()->getSchemaBuilder());
        Facade::setFacadeApplication($app);
        Facade::clearResolvedInstances();
        Schema::create('users', static function (Blueprint $t): void { $t->id(); });
        (require __DIR__.'/../../../../database/migrations/deepro/2026_09_30_210000_umi_v2_schema.php')->up();
        (require __DIR__.'/../../../../database/migrations/deepro/2026_10_01_010000_umi_v2_local_acceptance.php')->up();
        (require __DIR__.'/../../../../database/migrations/deepro/2026_10_01_020000_umi_v2_chain_settings.php')->up();
        (require __DIR__.'/../../../../database/migrations/deepro/2026_10_01_030000_umi_v2_dynamic_rank.php')->up();
        DB::table('users')->insert([['id' => 1], ['id' => 2], ['id' => 3]]);
        $this->engine = new LocalEngine(new CycleStore());
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->oldFacadeApplication);
        \Illuminate\Container\Container::setInstance($this->oldContainer);
        parent::tearDown();
    }

    public function test_full_funded_flow_preserves_w_and_mints_only_sandbox_points(): void
    {
        $a = $this->engine->enroll(1, null, 'enroll-a');
        $b = $this->engine->enroll(2, $a->member_code, 'enroll-b');
        $this->engine->fundTestRewardInventory('10000', 'inventory-1', 99);
        $this->engine->fundTestWallet($a->id, '1000', 'wallet-a', 99);
        $this->engine->fundTestWallet($b->id, '1000', 'wallet-b', 99);
        $aCycle = $this->engine->activate(1, '100', 'activation-a');
        $bCycle = $this->engine->activate(2, '100', 'activation-b');
        self::assertSame($aCycle->id, $this->engine->activate(1, '100', 'activation-a')->id);
        self::assertSame('pending_burn', $aCycle->status);
        self::assertSame('0', (string) DB::table('umi_v2_release_events')->count());
        $batch = $this->engine->confirmSandboxBatch('batch-1', 99);
        self::assertSame('200', $batch['amount_umi']);
        self::assertSame('active', DB::table('umi_v2_cycles')->find($aCycle->id)->status);
        self::assertSame('10', (string) DB::table('umi_v2_income_accounts')
            ->where('member_id', $a->id)->value('referral_pending'));
        $this->engine->setTestLevel($a->id, 4);
        $this->engine->setTestLevel($b->id, 4);
        $day = \Carbon\CarbonImmutable::now('Asia/Shanghai')->addDay()->toDateString();
        $run = $this->engine->runDay($day, '0.01', 'day-1', 99);
        // Two static releases (2) and V4 differential (.4). B has no grade
        // award, so B's static and direct income do not create a peer award.
        self::assertSame('2.4', $run['released_umi']);
        self::assertSame(0, $run['peer_rewards']);
        self::assertTrue($this->engine->runDay($day, '0.01', 'day-1', 99)['replayed']);
        $this->engine->transfer(2, $bCycle->id, 'static', '1', 'transfer-b');
        $withdrawal = $this->engine->withdraw(2, '1', 'withdraw-b');
        self::assertSame('paid', $withdrawal->status);
        self::assertSame('1', (string) $withdrawal->paid_umi);
        self::assertSame('0', (string) $withdrawal->confirmed_burn_umi);
        $funding = DB::table('umi_v2_withdrawal_funding')->where('withdrawal_id', $withdrawal->id)->first();
        self::assertSame(0, bccomp((string) $funding->from_wallet_umi, '0.3', 24));
        self::assertSame(0, DB::table('umi_v2_point_claims')->count());
        $this->engine->confirmSandboxBatch('batch-2', 99);
        self::assertSame(0, bccomp((string) DB::table('umi_v2_point_claims')
            ->where('withdrawal_id', $withdrawal->id)->value('points'), '0.3', 24));
        self::assertSame('points_only', DB::table('umi_v2_point_claims')->value('status'));
        self::assertSame('0', $this->engine->dashboard(2)['burn']['onchain_confirmed_umi']);
        self::assertSame(0, bccomp((string) DB::table('umi_v2_sandbox_accounts')
            ->where('code', 'system:paid')->value('balance_umi'), '1', 24));
        self::assertTrue($this->engine->reconcile()['ok']);
    }

    public function test_shortfall_waits_for_test_purchase_before_full_w_payout(): void
    {
        $c = $this->engine->enroll(3, null, 'enroll-c');
        $this->engine->fundTestRewardInventory('100', 'inventory-c', 99);
        $this->engine->fundTestWallet($c->id, '100', 'wallet-c', 99);
        $cycle = $this->engine->activate(3, '100', 'activation-c');
        $this->engine->confirmSandboxBatch('batch-c1', 99);
        $day = \Carbon\CarbonImmutable::now('Asia/Shanghai')->addDay()->toDateString();
        $this->engine->runDay($day, '0.01', 'day-c', 99);
        $this->engine->transfer(3, $cycle->id, 'static', '1', 'transfer-c');
        $w = $this->engine->withdraw(3, '1', 'withdraw-c');
        self::assertSame('awaiting_topup', $w->status);
        self::assertSame('0', (string) $w->paid_umi);
        self::assertSame(0, bccomp((string) DB::table('umi_v2_withdrawal_funding')
            ->where('withdrawal_id', $w->id)->value('remaining_umi'), '0.3', 24));
        self::assertSame('paid', $this->engine->simulateExternalTopup($w->id, 'topup-c', 99)->status);
        self::assertSame('paid', $this->engine->simulateExternalTopup($w->id, 'topup-c', 99)->status);
        self::assertSame(1, DB::table('umi_v2_burn_lots')->where('withdrawal_id', $w->id)->count());
        $this->engine->confirmSandboxBatch('batch-c2', 99);
        self::assertSame(1, DB::table('umi_v2_point_claims')->where('withdrawal_id', $w->id)->count());
        self::assertTrue($this->engine->reconcile()['ok']);
    }

    public function test_excess_referral_is_real_sandbox_inventory_moved_to_injury_lot(): void
    {
        $a = $this->engine->enroll(1, null, 'enroll-injury-a');
        $b = $this->engine->enroll(2, $a->member_code, 'enroll-injury-b');
        $this->engine->fundTestRewardInventory('1000', 'inventory-injury', 99);
        $this->engine->fundTestWallet($a->id, '100', 'wallet-injury-a', 99);
        $this->engine->fundTestWallet($b->id, '5000', 'wallet-injury-b', 99);
        $aCycle = $this->engine->activate(1, '100', 'activation-injury-a');
        $this->engine->activate(2, '5000', 'activation-injury-b');
        $this->engine->confirmSandboxBatch('batch-injury', 99);
        $injury = DB::table('umi_v2_burn_lots')->where('purpose', 'injury')->first();
        self::assertNotNull($injury);
        self::assertSame('200', (string) $injury->amount_umi);
        self::assertSame('300', (string) DB::table('umi_v2_income_accounts')
            ->where('member_id', $a->id)->value('referral_pending'));
        self::assertSame('completed', DB::table('umi_v2_cycles')->find($aCycle->id)->status);
        self::assertSame('200', (string) DB::table('umi_v2_sandbox_accounts')
            ->where('code', 'system:dedicated')->value('balance_umi'));
        self::assertSame(0, DB::table('umi_v2_point_claims')->count());
        self::assertTrue($this->engine->reconcile()['ok']);
    }

    public function test_parallel_cycles_count_only_while_active_and_each_release_static(): void
    {
        $member = $this->engine->enroll(1, null, 'parallel-member');
        $this->engine->fundTestRewardInventory('100', 'parallel-inventory', 99);
        $this->engine->fundTestWallet($member->id, '200', 'parallel-wallet', 99);
        $first = $this->engine->activate(1, '100', 'parallel-first');
        $second = $this->engine->activate(1, '100', 'parallel-second');
        self::assertSame(1, (int) $first->cycle_number);
        self::assertSame(2, (int) $second->cycle_number);
        $this->engine->confirmSandboxBatch('parallel-batch', 99);
        $dashboard = $this->engine->dashboard(1);
        self::assertSame('200', $dashboard['performance']['personal_usd']);
        self::assertSame(2, $dashboard['cycle_summary']['active_count']);
        self::assertSame('600', $dashboard['cycle_summary']['active_remaining_umi']);
        $day = \Carbon\CarbonImmutable::now('Asia/Shanghai')->addDay()->toDateString();
        $this->engine->runDay($day, '0.01', 'parallel-day', 99);
        self::assertSame(2, DB::table('umi_v2_release_events')->where('kind', 'static')->count());
        self::assertSame('2', $this->engine->dashboard(1)['cycle_summary']['total_released_umi']);
        self::assertTrue($this->engine->reconcile()['ok']);
    }

    public function test_one_direct_candidate_fills_oldest_cycle_then_next_without_duplicate_injury(): void
    {
        $a = $this->engine->enroll(1, null, 'spill-a');
        $b = $this->engine->enroll(2, $a->member_code, 'spill-b');
        $this->engine->fundTestRewardInventory('500', 'spill-inventory', 99);
        $this->engine->fundTestWallet($a->id, '200', 'spill-wallet-a', 99);
        $this->engine->fundTestWallet($b->id, '5000', 'spill-wallet-b', 99);
        $first = $this->engine->activate(1, '100', 'spill-first');
        $second = $this->engine->activate(1, '100', 'spill-second');
        $child = $this->engine->activate(2, '5000', 'spill-child');
        $this->engine->confirmSandboxBatch('spill-batch', 99);
        self::assertSame('completed', DB::table('umi_v2_cycles')->find($first->id)->status);
        self::assertSame('active', DB::table('umi_v2_cycles')->find($second->id)->status);
        self::assertSame('300', (string) DB::table('umi_v2_cycles')->find($first->id)->referral_released_umi);
        self::assertSame('200', (string) DB::table('umi_v2_cycles')->find($second->id)->referral_released_umi);
        self::assertSame('500', (string) DB::table('umi_v2_income_accounts')
            ->where('member_id', $a->id)->value('referral_pending'));
        self::assertSame(0, DB::table('umi_v2_burn_lots')->where('purpose', 'injury')->count());
        self::assertSame('100', $this->engine->dashboard(1)['performance']['personal_usd']);
        self::assertSame('500', (string) DB::table('umi_v2_release_events')
            ->where('source_event_id', 'activation:' . $child->id)->sum('released_umi'));
        self::assertTrue($this->engine->reconcile()['ok']);
    }

    public function test_dynamic_injury_starts_only_after_all_parallel_cycles_are_full(): void
    {
        $a = $this->engine->enroll(1, null, 'overflow-a');
        $b = $this->engine->enroll(2, $a->member_code, 'overflow-b');
        $this->engine->fundTestRewardInventory('700', 'overflow-inventory', 99);
        $this->engine->fundTestWallet($a->id, '200', 'overflow-wallet-a', 99);
        $this->engine->fundTestWallet($b->id, '7000', 'overflow-wallet-b', 99);
        $this->engine->activate(1, '100', 'overflow-first');
        $this->engine->activate(1, '100', 'overflow-second');
        $this->engine->activate(2, '7000', 'overflow-child');
        $this->engine->confirmSandboxBatch('overflow-batch', 99);
        self::assertSame('600', (string) DB::table('umi_v2_income_accounts')
            ->where('member_id', $a->id)->value('referral_pending'));
        self::assertSame(2, DB::table('umi_v2_cycles')->where('member_id', $a->id)
            ->where('status', 'completed')->count());
        self::assertSame('100', (string) DB::table('umi_v2_burn_lots')
            ->where('purpose', 'injury')->value('amount_umi'));
        self::assertSame(1, DB::table('umi_v2_burn_lots')->where('purpose', 'injury')->count());
        self::assertTrue($this->engine->reconcile()['ok']);
    }

    public function test_peer_award_uses_only_direct_child_paid_grade_differential(): void
    {
        $a = $this->engine->enroll(1, null, 'peer-a');
        $b = $this->engine->enroll(2, $a->member_code, 'peer-b');
        $c = $this->engine->enroll(3, $b->member_code, 'peer-c');
        $this->engine->fundTestRewardInventory('1000', 'peer-inventory', 99);
        foreach ([$a, $b, $c] as $member) {
            $this->engine->fundTestWallet($member->id, '100', 'peer-wallet-' . $member->id, 99);
            $this->engine->activate((int) $member->user_id, '100', 'peer-activation-' . $member->id);
        }
        $this->engine->confirmSandboxBatch('peer-batch', 99);
        $this->engine->setTestLevel($a->id, 4);
        $this->engine->setTestLevel($b->id, 4);
        $day = \Carbon\CarbonImmutable::now('Asia/Shanghai')->addDay()->toDateString();
        $summary = $this->engine->runDay($day, '0.01', 'peer-day', 99);
        $peer = DB::table('umi_v2_peer_reward_sources')->where('parent_member_id', $a->id)->first();
        self::assertNotNull($peer);
        self::assertSame('0.4', (string) $peer->confirmed_base_umi);
        self::assertSame('0.04', (string) $peer->released_umi);
        self::assertSame('3.84', $summary['released_umi']);
        self::assertSame(1, $summary['peer_rewards']);
        self::assertSame('10', (string) DB::table('umi_v2_income_accounts')
            ->where('member_id', $b->id)->value('referral_pending'));
        self::assertTrue($this->engine->reconcile()['ok']);
    }

    public function test_precise_thirty_percent_preview_and_sandbox_terminal_floor(): void
    {
        $member = $this->engine->enroll(1, null, 'enroll-cap');
        $this->engine->fundTestWallet($member->id, '100', 'wallet-cap', 99);
        config(['umi-v2.sandbox_initial_supply_umi' => '200',
            'umi-v2.sandbox_terminal_floor_umi' => '100']);
        $this->engine->activate(1, '100', 'activation-cap');
        self::assertSame('300', $this->engine->dashboard(1)['cycles'][0]->remaining_umi);
        try {
            $this->engine->activate(1, '100', 'activation-cap-2');
            self::fail('A terminal sandbox must reject new commercial activity');
        } catch (\DomainException $e) {
            self::assertStringContainsString('terminal floor', $e->getMessage());
        }
        self::assertSame('100', $this->engine->confirmSandboxBatch('batch-cap', 99)['amount_umi']);
        self::assertSame('0', $this->engine->dashboard(1)['burn']['onchain_confirmed_umi']);
    }

    public function test_two_direct_branches_derive_v1_from_small_area_without_touching_legacy(): void
    {
        $parent = $this->engine->enroll(1, null, 'rank-parent');
        $left = $this->engine->enroll(2, $parent->member_code, 'rank-left');
        $right = $this->engine->enroll(3, $parent->member_code, 'rank-right');
        $this->engine->fundTestRewardInventory('1000', 'rank-inventory', 99);
        foreach ([[$parent, 100], [$left, 500], [$right, 500]] as [$member, $principal]) {
            $this->engine->fundTestWallet($member->id, (string) $principal,
                'rank-wallet-' . $member->id, 99);
            $this->engine->activate((int) $member->user_id, (string) $principal,
                'rank-activation-' . $member->id);
        }
        $this->engine->confirmSandboxBatch('rank-batch', 99);
        $dashboard = $this->engine->dashboard(1);
        self::assertSame('100', $dashboard['performance']['personal_usd']);
        self::assertSame('1000', $dashboard['performance']['team_usd']);
        self::assertSame('500', $dashboard['performance']['small_area_usd']);
        self::assertSame(1, (int) $dashboard['member']->level);
        self::assertTrue($this->engine->reconcile()['ok']);
    }

    public function test_completed_cycle_drops_out_of_small_area_and_member_rank(): void
    {
        $parent = $this->engine->enroll(1, null, 'drop-parent');
        $left = $this->engine->enroll(2, $parent->member_code, 'drop-left');
        $right = $this->engine->enroll(3, $parent->member_code, 'drop-right');
        $this->engine->fundTestRewardInventory('1000', 'drop-inventory', 99);
        foreach ([[$parent, 100], [$left, 500], [$right, 500]] as [$member, $principal]) {
            $this->engine->fundTestWallet($member->id, (string) $principal,
                'drop-wallet-' . $member->id, 99);
            $this->engine->activate((int) $member->user_id, (string) $principal,
                'drop-activation-' . $member->id);
        }
        $this->engine->confirmSandboxBatch('drop-batch', 99);
        self::assertSame(1, (int) DB::table('umi_v2_members')
            ->where('id', $parent->id)->value('level'));
        DB::table('umi_v2_cycles')->where('member_id', $right->id)->update([
            'status' => 'completed', 'updated_at' => now(),
        ]);
        $day = \Carbon\CarbonImmutable::now('Asia/Shanghai')->addDay()->toDateString();
        $this->engine->runDay($day, '0.01', 'drop-day', 99);
        $dashboard = $this->engine->dashboard(1);
        self::assertSame(0, (int) $dashboard['member']->level);
        self::assertSame('500', $dashboard['performance']['team_usd']);
        self::assertSame('0', $dashboard['performance']['small_area_usd']);
        self::assertSame(1, $dashboard['performance']['effective_direct_count']);
        self::assertTrue($this->engine->reconcile()['ok']);
    }

    public function test_bsc_configuration_is_audited_and_never_enables_real_transfers(): void
    {
        $contract = '0x1111111111111111111111111111111111111111';
        $burn = LocalEngine::BSC_DEAD_ADDRESS;
        $saved = $this->engine->saveChainSettings($contract, $burn, null, 'settings-1', 99);
        self::assertFalse($saved['replayed']);
        self::assertTrue($this->engine->saveChainSettings($contract, $burn, null, 'settings-1', 99)['replayed']);
        $settings = $this->engine->chainSettings();
        self::assertSame(56, $settings['chain_id']);
        self::assertNull($settings['dedicated_address']);
        self::assertFalse($settings['configured']);
        self::assertFalse($settings['real_transfer_enabled']);
        self::assertSame(1, DB::table('umi_v2_chain_settings_audit')->count());
        $this->expectException(\DomainException::class);
        $this->engine->saveChainSettings($contract,
            '0x2222222222222222222222222222222222222222', null, 'settings-2', 99);
    }
}
