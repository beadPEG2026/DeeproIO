<?php

namespace Tests\Unit\Deepro;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;

/**
 * Schema-only probe. It creates a separate SQLite :memory: connection and
 * never boots the Deepro application or touches its configured database.
 */
final class UmiV2SchemaMigrationTest extends TestCase
{
    private mixed $previousFacadeApplication;
    private object $migration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousFacadeApplication = Facade::getFacadeApplication();

        $container = new Container();
        $database = new Capsule($container);
        $database->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $container->instance('db', $database->getDatabaseManager());
        $container->bind('db.schema', static fn (Container $app) => $app['db']->connection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();

        Schema::create('users', static function (Blueprint $table): void {
            $table->id();
        });
        $this->migration = require __DIR__.'/../../../database/migrations/deepro/2026_09_30_210000_umi_v2_schema.php';
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);
        parent::tearDown();
    }

    public function test_new_tables_and_uniqueness_are_isolated_from_legacy_schema(): void
    {
        $this->migration->up();

        foreach ([
            'umi_v2_policy_versions', 'umi_v2_members', 'umi_v2_sponsor_edges',
            'umi_v2_cycles', 'umi_v2_income_accounts', 'umi_v2_release_events',
            'umi_v2_income_transfers', 'umi_v2_income_transfer_allocations',
            'umi_v2_withdrawals', 'umi_v2_withdrawal_allocations',
            'umi_v2_market_fills', 'umi_v2_burn_evidence',
            'umi_v2_stock_point_entries', 'umi_v2_audit_events',
        ] as $table) {
            self::assertTrue(Schema::hasTable($table), $table);
        }
        self::assertFalse(Schema::hasTable('umi_business_accounts'));
        self::assertFalse(Schema::hasTable('umi_legacy_accounts'));

        self::assertTrue(Schema::hasColumns('umi_v2_release_events', [
            'cycle_id', 'kind', 'candidate_umi', 'released_umi', 'business_date',
            'policy_version_id', 'source_event_id', 'request_key',
        ]));
        self::assertTrue(Schema::hasColumns('umi_v2_cycles', [
            'snapshot_json', 'snapshot_sha256', 'version_no',
        ]));
        self::assertTrue(Schema::hasColumns('umi_v2_income_accounts', [
            'static_pending', 'team_pending', 'referral_pending', 'available',
        ]));
        self::assertTrue(Schema::hasColumns('umi_v2_income_transfers', [
            'pocket', 'from_bucket', 'to_bucket', 'amount_umi', 'request_key',
        ]));
        self::assertTrue(Schema::hasColumns('umi_v2_stock_point_entries', [
            'program_id', 'points_per_burned_umi', 'burn_confirmation_ref',
        ]));
        $this->assertUniqueIndex('umi_v2_release_events', ['request_key']);
        $this->assertUniqueIndex('umi_v2_release_events', ['cycle_id', 'kind', 'business_date', 'source_event_id']);
        $this->assertUniqueIndex('umi_v2_income_transfers', ['request_key']);
        self::assertFalse(Schema::hasTable('umi_v2_activation_burn_evidence'));
        self::assertTrue(Schema::hasColumns('umi_v2_burn_evidence', ['purpose', 'cycle_id', 'withdrawal_id']));
        $this->assertUniqueIndex('umi_v2_burn_evidence', ['cycle_id']);
        $this->assertUniqueIndex('umi_v2_burn_evidence', ['chain_id', 'tx_hash', 'log_index']);
        $this->assertUniqueIndex('umi_v2_stock_point_entries', ['burn_evidence_id']);
        $this->assertUniqueIndex('umi_v2_stock_point_entries', ['request_key']);

        $amountColumn = collect(DB::select("PRAGMA table_info('umi_v2_release_events')"))
            ->first(static fn (object $column): bool => $column->name === 'released_umi');
        self::assertNotNull($amountColumn);
        // SQLite stores Laravel DECIMAL as generic NUMERIC; production precision
        // is checked against the migration declaration rather than SQLite math.
        self::assertSame('numeric', strtolower($amountColumn->type));
        self::assertStringContainsString("decimal('released_umi', 54, 24)",
            file_get_contents(__DIR__.'/../../../database/migrations/deepro/2026_09_30_210000_umi_v2_schema.php'));
        self::assertStringContainsString('umi_v2_burn_purpose_ck',
            file_get_contents(__DIR__.'/../../../database/migrations/deepro/2026_09_30_210000_umi_v2_schema.php'));
        self::assertStringContainsString("elseif (\$driver === 'mysql')",
            file_get_contents(__DIR__.'/../../../database/migrations/deepro/2026_09_30_210000_umi_v2_schema.php'));
    }

    public function test_rollback_drops_only_empty_v2_tables_and_refuses_business_data(): void
    {
        $this->migration->up();
        DB::table('umi_v2_policy_versions')->insert([
            'policy_key' => 'demo', 'version' => 1, 'status' => 'draft',
            'rules_json' => '{}', 'rules_sha256' => str_repeat('0', 64),
            'created_at' => '2026-09-30 00:00:00', 'updated_at' => '2026-09-30 00:00:00',
        ]);
        try {
            $this->migration->down();
            self::fail('A populated financial schema must not be dropped.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('business data', $exception->getMessage());
        }
        self::assertTrue(Schema::hasTable('umi_v2_policy_versions'));
        DB::table('umi_v2_policy_versions')->delete();
        $this->migration->down();
        self::assertFalse(Schema::hasTable('umi_v2_policy_versions'));
        self::assertTrue(Schema::hasTable('users'));
    }

    public function test_one_withdrawal_accepts_multiple_burn_logs_but_never_duplicates_a_chain_log(): void
    {
        $this->migration->up();
        $now = '2026-09-30 00:00:00';
        DB::table('users')->insert(['id' => 1]);
        $policyId = DB::table('umi_v2_policy_versions')->insertGetId([
            'policy_key' => 'exit', 'version' => 1, 'status' => 'approved',
            'rules_json' => '{}', 'rules_sha256' => str_repeat('1', 64),
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $memberId = DB::table('umi_v2_members')->insertGetId([
            'user_id' => 1, 'member_code' => 'DEMO-1', 'status' => 'active',
            'joined_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $cycleId = DB::table('umi_v2_cycles')->insertGetId([
            'member_id' => $memberId, 'policy_version_id' => $policyId,
            'cycle_number' => 1, 'activation_request_key' => 'cycle-1',
            'multiplier' => 3, 'principal_umi' => '1000',
            'usd_quote_per_umi' => '1', 'principal_usd' => '1000',
            'cap_umi' => '3000', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $newCycle = DB::table('umi_v2_cycles')->find($cycleId);
        self::assertNull($newCycle->snapshot_json);
        self::assertNull($newCycle->snapshot_sha256);
        self::assertSame(0, (int) $newCycle->version_no);
        $snapshot = json_encode(['cycle_id' => $cycleId, 'events' => []], JSON_THROW_ON_ERROR);
        $snapshotHash = hash('sha256', $snapshot);
        self::assertSame(1, DB::table('umi_v2_cycles')->where('id', $cycleId)
            ->where('version_no', 0)->update([
                'snapshot_json' => $snapshot, 'snapshot_sha256' => $snapshotHash,
                'version_no' => 1, 'updated_at' => $now,
            ]));
        self::assertSame(0, DB::table('umi_v2_cycles')->where('id', $cycleId)
            ->where('version_no', 0)->update(['version_no' => 2]));
        self::assertSame($snapshotHash, DB::table('umi_v2_cycles')->find($cycleId)->snapshot_sha256);
        $activationProof = [
            'purpose' => 'activation', 'cycle_id' => $cycleId, 'chain_id' => 56,
            'token_contract' => '0xDEMO', 'tx_hash' => '0xACTIVATE',
            'log_index' => 0, 'burned_umi' => '1000',
            'block_number' => 99, 'block_hash' => '0xBLOCK99',
            'finality_status' => 'pending', 'receipt_sha256' => str_repeat('4', 64),
            'created_at' => $now,
        ];
        $activationId = DB::table('umi_v2_burn_evidence')->insertGetId($activationProof);
        $pendingActivation = DB::table('umi_v2_burn_evidence')->find($activationId);
        self::assertSame('pending', $pendingActivation->finality_status);
        self::assertNull($pendingActivation->finalized_at);
        $this->assertWriteRejected(static fn () => DB::table('umi_v2_burn_evidence')
            ->where('id', $activationId)->update(['tx_hash' => '0xREPLACED']));
        DB::table('umi_v2_burn_evidence')->where('id', $activationId)
            ->update(['finality_status' => 'final', 'finalized_at' => $now]);
        self::assertSame('final', DB::table('umi_v2_burn_evidence')->find($activationId)->finality_status);
        $this->assertWriteRejected(static fn () => DB::table('umi_v2_burn_evidence')
            ->where('id', $activationId)->update(['finality_status' => 'pending', 'finalized_at' => null]));
        $this->assertWriteRejected(static fn () => DB::table('umi_v2_burn_evidence')
            ->where('id', $activationId)->delete());
        try {
            DB::table('umi_v2_burn_evidence')->insert(
                array_merge($activationProof, ['tx_hash' => '0xOTHER', 'log_index' => 1])
            );
            self::fail('A cycle may have only one activation burn proof.');
        } catch (QueryException) {
            self::assertSame(1, DB::table('umi_v2_burn_evidence')->count());
        }
        $secondCycleId = DB::table('umi_v2_cycles')->insertGetId([
            'member_id' => $memberId, 'policy_version_id' => $policyId,
            'cycle_number' => 2, 'activation_request_key' => 'cycle-2',
            'multiplier' => 3, 'principal_umi' => '1000',
            'usd_quote_per_umi' => '1', 'principal_usd' => '1000',
            'cap_umi' => '3000', 'created_at' => $now, 'updated_at' => $now,
        ]);
        try {
            DB::table('umi_v2_burn_evidence')->insert(
                array_merge($activationProof, ['cycle_id' => $secondCycleId])
            );
            self::fail('A chain log may not activate two cycles.');
        } catch (QueryException) {
            self::assertSame(1, DB::table('umi_v2_burn_evidence')->count());
        }
        $withdrawalId = DB::table('umi_v2_withdrawals')->insertGetId([
            'member_id' => $memberId, 'policy_version_id' => $policyId,
            'request_key' => 'withdrawal-1', 'requested_umi' => '30',
            'required_burn_umi' => '9', 'requested_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $receipt = [
            'purpose' => 'withdrawal', 'withdrawal_id' => $withdrawalId, 'chain_id' => 56,
            'token_contract' => '0xDEMO', 'tx_hash' => '0xTX1',
            'burned_umi' => '4.5', 'block_number' => 100,
            'block_hash' => '0xBLOCK', 'finality_status' => 'final',
            'finalized_at' => $now, 'receipt_sha256' => str_repeat('2', 64),
            'created_at' => $now,
        ];
        $this->assertWriteRejected(static fn () => DB::table('umi_v2_burn_evidence')->insert(
            array_merge($receipt, [
                'purpose' => 'activation', 'cycle_id' => $secondCycleId,
                'tx_hash' => '0xINVALIDPURPOSE', 'log_index' => 5,
            ])
        ));
        $this->assertWriteRejected(static fn () => DB::table('umi_v2_burn_evidence')->insert(
            array_merge($receipt, [
                'tx_hash' => '0xINVALIDFINALITY', 'log_index' => 6,
                'finality_status' => 'pending',
            ])
        ));
        try {
            DB::table('umi_v2_burn_evidence')->insert(array_merge($receipt, [
                'tx_hash' => '0xACTIVATE', 'log_index' => 0,
            ]));
            self::fail('One chain log cannot be reused across activation and withdrawal.');
        } catch (QueryException) {
            self::assertSame(1, DB::table('umi_v2_burn_evidence')->count());
        }
        $burnId = DB::table('umi_v2_burn_evidence')->insertGetId($receipt + ['log_index' => 1]);
        DB::table('umi_v2_burn_evidence')->insert($receipt + ['log_index' => 2]);
        self::assertSame(2, DB::table('umi_v2_burn_evidence')->where('withdrawal_id', $withdrawalId)->count());
        $pendingBurnId = DB::table('umi_v2_burn_evidence')->insertGetId(array_merge($receipt, [
            'tx_hash' => '0xTXPENDING', 'log_index' => 3,
            'finality_status' => 'pending', 'finalized_at' => null,
        ]));
        $pointCredit = [
            'member_id' => $memberId, 'withdrawal_id' => $withdrawalId,
            'burn_evidence_id' => $burnId, 'policy_version_id' => $policyId,
            'request_key' => 'point-burn-1', 'kind' => 'burn_credit',
            'program_id' => 'STOCK-POINTS-V1', 'points_per_burned_umi' => '1',
            'burn_confirmation_ref' => '56:0xTX1:1',
            'delta_points' => '4.5', 'balance_after_points' => '4.5',
            'source_burned_umi' => '4.5', 'source_sha256' => str_repeat('3', 64),
            'created_at' => $now,
        ];
        $this->assertWriteRejected(static fn () => DB::table('umi_v2_stock_point_entries')->insert(
            array_merge($pointCredit, ['request_key' => 'point-missing-proof', 'burn_evidence_id' => null])
        ));
        $this->assertWriteRejected(static fn () => DB::table('umi_v2_stock_point_entries')->insert(
            array_merge($pointCredit, ['request_key' => 'point-activation-proof', 'burn_evidence_id' => $activationId])
        ));
        $this->assertWriteRejected(static fn () => DB::table('umi_v2_stock_point_entries')->insert(
            array_merge($pointCredit, ['request_key' => 'point-pending-proof', 'burn_evidence_id' => $pendingBurnId])
        ));
        $this->assertWriteRejected(static fn () => DB::table('umi_v2_stock_point_entries')->insert(
            array_merge($pointCredit, ['request_key' => 'point-missing-program', 'program_id' => null])
        ));
        $this->assertWriteRejected(static fn () => DB::table('umi_v2_stock_point_entries')->insert(
            array_merge($pointCredit, ['request_key' => 'point-amount-mismatch', 'source_burned_umi' => '4.4'])
        ));
        DB::table('users')->insert(['id' => 2]);
        $otherMemberId = DB::table('umi_v2_members')->insertGetId([
            'user_id' => 2, 'member_code' => 'DEMO-2', 'status' => 'active',
            'joined_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->assertWriteRejected(static fn () => DB::table('umi_v2_stock_point_entries')->insert(
            array_merge($pointCredit, ['request_key' => 'point-other-owner', 'member_id' => $otherMemberId])
        ));
        $pointId = DB::table('umi_v2_stock_point_entries')->insertGetId($pointCredit);
        $point = DB::table('umi_v2_stock_point_entries')->find($pointId);
        self::assertSame('STOCK-POINTS-V1', $point->program_id);
        self::assertSame('56:0xTX1:1', $point->burn_confirmation_ref);
        $this->assertWriteRejected(static fn () => DB::table('umi_v2_stock_point_entries')
            ->where('id', $pointId)->update(['delta_points' => '99']));
        $this->assertWriteRejected(static fn () => DB::table('umi_v2_stock_point_entries')
            ->where('id', $pointId)->delete());

        $this->expectException(QueryException::class);
        DB::table('umi_v2_burn_evidence')->insert($receipt + ['log_index' => 1]);
    }

    private function assertWriteRejected(callable $write): void
    {
        try {
            $write();
            self::fail('The schema must reject this invalid financial write.');
        } catch (QueryException) {
            self::assertTrue(true);
        }
    }

    private function assertUniqueIndex(string $table, array $columns): void
    {
        $indexes = Schema::getIndexes($table);
        self::assertTrue((bool) array_filter($indexes, static fn (array $index): bool =>
            ($index['unique'] ?? false) && ($index['columns'] ?? []) === $columns
        ), $table.' must uniquely index '.implode(',', $columns));
    }
}
