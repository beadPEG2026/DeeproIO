<?php

namespace Tests\Unit\Umi\V2;

use App\Services\Umi\V2\CycleStore;
use DomainException;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Isolated SQLite integration test: never boots or migrates the Deepro DB. */
final class CycleStoreTest extends TestCase
{
    private mixed $previousFacadeApplication;
    private CycleStore $store;
    private int $policyId;
    private int $memberId;
    private int $cycleId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $container = new Container();
        $capsule = new Capsule($container);
        $capsule->addConnection([
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $container->instance('db', $capsule->getDatabaseManager());
        $container->bind('db.schema', static fn (Container $app) => $app['db']->connection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();
        Schema::create('users', static function (Blueprint $t): void { $t->id(); });
        (require __DIR__ . '/../../../../database/migrations/deepro/2026_09_30_210000_umi_v2_schema.php')->up();
        $this->store = new CycleStore();
        $now = '2026-09-30 00:00:00';
        DB::table('users')->insert(['id' => 1]);
        $this->policyId = DB::table('umi_v2_policy_versions')->insertGetId([
            'policy_key' => 'cycle', 'version' => 1, 'status' => 'approved',
            'rules_json' => '{}', 'rules_sha256' => str_repeat('a', 64),
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->memberId = DB::table('umi_v2_members')->insertGetId([
            'user_id' => 1, 'member_code' => 'UMI-1', 'status' => 'active',
            'joined_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->cycleId = DB::table('umi_v2_cycles')->insertGetId([
            'member_id' => $this->memberId, 'policy_version_id' => $this->policyId,
            'cycle_number' => 1, 'activation_request_key' => 'activation-1',
            'multiplier' => 3, 'principal_umi' => '100', 'usd_quote_per_umi' => '1',
            'principal_usd' => '100', 'cap_umi' => '300', 'status' => 'active',
            'starts_on' => '2026-09-30', 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);
        parent::tearDown();
    }

    public function test_static_release_and_transfer_write_snapshot_events_and_balances_atomically(): void
    {
        $release = $this->store->releaseStatic($this->cycleId, 'static-1', '2026-09-30', '0.01', $this->policyId);
        self::assertFalse($release['replayed']);
        self::assertSame('1', $release['event']['paid']);
        self::assertSame('299', $release['cycle']['remaining']);
        $row = DB::table('umi_v2_cycles')->find($this->cycleId);
        self::assertSame(1, (int) $row->version_no);
        self::assertSame(hash('sha256', $row->snapshot_json), $row->snapshot_sha256);
        self::assertSame('1', (string) $row->released_umi);
        self::assertSame('1', (string) $row->static_released_umi);
        $posted = DB::table('umi_v2_release_events')->first();
        self::assertSame('static', $posted->kind);
        self::assertSame('300', (string) $posted->cap_before_umi);
        self::assertSame('299', (string) $posted->cap_after_umi);
        self::assertSame('1', (string) DB::table('umi_v2_income_accounts')->value('static_pending'));

        $transfer = $this->store->transfer($this->cycleId, 'transfer-1', 'static', '0.4');
        self::assertFalse($transfer['replayed']);
        self::assertSame('1', $transfer['cycle']['used']);
        self::assertSame('0.6', $transfer['cycle']['pending_transfer']['static']);
        self::assertSame('0.4', $transfer['cycle']['withdrawable']);
        $account = DB::table('umi_v2_income_accounts')->first();
        self::assertSame(0, bccomp((string) $account->static_pending, '0.6', 24));
        self::assertSame(0, bccomp((string) $account->available, '0.4', 24));
        self::assertSame(1, DB::table('umi_v2_income_transfers')->count());
        self::assertSame(1, DB::table('umi_v2_income_transfer_allocations')->count());
        self::assertSame(0, bccomp((string) DB::table('umi_v2_income_transfer_allocations')->value('amount_umi'), '0.4', 24));
        self::assertSame(2, (int) DB::table('umi_v2_cycles')->value('version_no'));
    }

    public function test_request_replays_return_original_events_without_second_write(): void
    {
        $first = $this->store->releaseStatic($this->cycleId, 'static-1', '2026-09-30', '0.01', $this->policyId);
        $replay = $this->store->releaseStatic($this->cycleId, 'static-1', '2026-09-30', '0.010', $this->policyId);
        self::assertTrue($replay['replayed']);
        self::assertSame($first['event'], $replay['event']);
        self::assertSame(1, DB::table('umi_v2_release_events')->count());
        self::assertSame(1, (int) DB::table('umi_v2_cycles')->value('version_no'));
        $this->store->transfer($this->cycleId, 'transfer-1', 'static', '0.5');
        $again = $this->store->transfer($this->cycleId, 'transfer-1', 'static', '0.50');
        self::assertTrue($again['replayed']);
        self::assertSame(1, DB::table('umi_v2_income_transfers')->count());
        self::assertSame(1, DB::table('umi_v2_income_transfer_allocations')->count());
        self::assertSame(2, (int) DB::table('umi_v2_cycles')->value('version_no'));
    }

    public function test_static_day_and_transfer_amount_conflicts_do_not_partially_write(): void
    {
        $this->store->releaseStatic($this->cycleId, 'static-1', '2026-09-30', '0.01', $this->policyId);
        try {
            $this->store->releaseStatic($this->cycleId, 'static-2', '2026-09-30', '0.01', $this->policyId);
            self::fail('Second static release on one day should fail');
        } catch (DomainException $expected) {
            self::assertSame(1, DB::table('umi_v2_release_events')->count());
        }
        $this->store->transfer($this->cycleId, 'transfer-1', 'static', '0.5');
        try {
            $this->store->transfer($this->cycleId, 'transfer-1', 'static', '0.6');
            self::fail('Changed request input should fail');
        } catch (DomainException $expected) {
            self::assertSame(1, DB::table('umi_v2_income_transfers')->count());
            self::assertSame(2, (int) DB::table('umi_v2_cycles')->value('version_no'));
        }
    }

    public function test_team_and_direct_release_use_one_cap_and_distinct_pending_pockets(): void
    {
        DB::table('users')->insert(['id' => 2]);
        $child = DB::table('umi_v2_members')->insertGetId([
            'user_id' => 2, 'member_code' => 'UMI-2', 'status' => 'active',
            'joined_at' => '2026-09-30 00:00:00', 'created_at' => '2026-09-30 00:00:00',
            'updated_at' => '2026-09-30 00:00:00',
        ]);
        $this->store->releaseStatic($this->cycleId, 'static-1', '2026-09-30', '0.01', $this->policyId);
        $team = $this->store->releaseTeam($this->cycleId, 'team-1', '2026-09-30', $child,
            'child-static-1', '10', '0.2', $this->policyId);
        $direct = $this->store->releaseDirectReferral($this->cycleId, 'direct-1', '2026-09-30', $child,
            'child-purchase-1', '100', '0.1', $this->policyId);
        self::assertSame('2', $team['event']['paid']);
        self::assertSame('10', $direct['event']['paid']);
        self::assertSame('300', $direct['cycle']['quota']);
        self::assertSame('13', $direct['cycle']['used']);
        self::assertSame('287', $direct['cycle']['remaining']);
        $account = DB::table('umi_v2_income_accounts')->first();
        self::assertSame('1', (string) $account->static_pending);
        self::assertSame('2', (string) $account->team_pending);
        self::assertSame('10', (string) $account->referral_pending);
        self::assertSame(['static', 'team', 'referral'], DB::table('umi_v2_release_events')->orderBy('id')->pluck('kind')->all());
        self::assertSame('2', (string) DB::table('umi_v2_cycles')->value('team_released_umi'));
        self::assertSame('10', (string) DB::table('umi_v2_cycles')->value('referral_released_umi'));
        try {
            $this->store->releaseTeam($this->cycleId, 'team-2', '2026-09-30', $child,
                'child-static-1', '10', '0.2', $this->policyId);
            self::fail('Same source team event must not be rewarded twice');
        } catch (DomainException $expected) {
            self::assertSame(3, DB::table('umi_v2_release_events')->count());
        }
    }

    public function test_tampered_snapshot_hash_blocks_mutation(): void
    {
        $this->store->releaseStatic($this->cycleId, 'static-1', '2026-09-30', '0.01', $this->policyId);
        DB::table('umi_v2_cycles')->where('id', $this->cycleId)->update(['snapshot_sha256' => str_repeat('0', 64)]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('checksum mismatch');
        $this->store->transfer($this->cycleId, 'transfer-1', 'static', '0.5');
    }

    public function test_failed_allocation_rolls_back_transfer_row_balance_and_snapshot(): void
    {
        $this->store->releaseStatic($this->cycleId, 'static-1', '2026-09-30', '0.01', $this->policyId);
        $before = DB::table('umi_v2_cycles')->find($this->cycleId);
        DB::table('umi_v2_release_events')->where('request_key', 'static-1')->update(['released_umi' => '0.2']);
        try {
            $this->store->transfer($this->cycleId, 'transfer-1', 'static', '0.5');
            self::fail('A mismatched normalized release row must abort the whole transfer');
        } catch (RuntimeException $expected) {
            self::assertStringContainsString('allocation does not match', $expected->getMessage());
            self::assertSame(0, DB::table('umi_v2_income_transfers')->count());
            self::assertSame(0, DB::table('umi_v2_income_transfer_allocations')->count());
            self::assertSame(0, bccomp((string) DB::table('umi_v2_income_accounts')->value('static_pending'), '1', 24));
            self::assertSame('0', (string) DB::table('umi_v2_income_accounts')->value('available'));
            $after = DB::table('umi_v2_cycles')->find($this->cycleId);
            self::assertSame($before->snapshot_sha256, $after->snapshot_sha256);
            self::assertSame((int) $before->version_no, (int) $after->version_no);
        }
    }

    public function test_unconfirmed_cycle_cannot_release_and_does_not_initialize_snapshot(): void
    {
        DB::table('umi_v2_cycles')->where('id', $this->cycleId)->update(['status' => 'pending_burn']);
        try {
            $this->store->releaseStatic($this->cycleId, 'static-1', '2026-09-30', '0.01', $this->policyId);
            self::fail('Pending burn must block release');
        } catch (DomainException $expected) {
            self::assertNull(DB::table('umi_v2_cycles')->value('snapshot_json'));
            self::assertSame(0, DB::table('umi_v2_release_events')->count());
        }
    }
}
