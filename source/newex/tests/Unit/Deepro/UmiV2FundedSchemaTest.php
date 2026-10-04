<?php

namespace Tests\Unit\Deepro;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;

final class UmiV2FundedSchemaTest extends TestCase
{
    private mixed $previous;
    private object $base;
    private object $funded;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previous = Facade::getFacadeApplication();
        $container = new Container();
        $database = new Capsule($container);
        $database->addConnection(['driver' => 'sqlite', 'database' => ':memory:',
            'prefix' => '', 'foreign_key_constraints' => true]);
        $container->instance('db', $database->getDatabaseManager());
        $container->bind('db.schema', static fn (Container $app) => $app['db']->connection()->getSchemaBuilder());
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();
        Schema::create('users', static function (Blueprint $t): void { $t->id(); });
        $this->base = require __DIR__ . '/../../../database/migrations/deepro/2026_09_30_210000_umi_v2_schema.php';
        $this->funded = require __DIR__ . '/../../../database/migrations/deepro/2026_10_01_040000_umi_v2_funded_workflow.php';
        $this->base->up();
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previous);
        parent::tearDown();
    }

    public function test_real_workflow_is_isolated_and_disabled_by_default(): void
    {
        $this->funded->up();
        foreach (['umi_v2_live_settings', 'umi_v2_live_intents',
            'umi_v2_live_wallet_moves', 'umi_v2_live_burn_lots',
            'umi_v2_live_burn_proofs', 'umi_v2_live_withdrawal_funding',
            'umi_v2_live_daily_runs', 'umi_v2_live_peer_sources',
            'umi_v2_live_quota_bonuses', 'umi_v2_live_quotes',
            'umi_v2_live_point_terms'] as $table) {
            self::assertTrue(Schema::hasTable($table), $table);
        }
        $settings = DB::table('umi_v2_live_settings')->find(1);
        self::assertNull($settings->pool_user_id);
        self::assertFalse((bool) $settings->intake_enabled);
        self::assertFalse((bool) $settings->settlement_enabled);
        self::assertFalse((bool) $settings->withdrawal_enabled);
        self::assertFalse(Schema::hasTable('umi_v2_sandbox_accounts'));
        $this->funded->down();
        self::assertFalse(Schema::hasTable('umi_v2_live_settings'));
        self::assertTrue(Schema::hasTable('umi_v2_cycles'));
    }

    public function test_business_records_block_schema_rollback(): void
    {
        $this->funded->up();
        DB::table('umi_v2_live_settings_audit')->insert([
            'request_key' => 'financial-setting', 'before_json' => '{}',
            'after_json' => '{}', 'actor_id' => 1, 'created_at' => now(),
        ]);
        $this->expectException(\RuntimeException::class);
        $this->funded->down();
    }
}
