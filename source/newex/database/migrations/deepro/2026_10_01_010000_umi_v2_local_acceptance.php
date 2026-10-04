<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local-acceptance extension for the isolated v2 schema. These sandbox rows
 * never represent Deepro wallet assets, an exchange fill, or an on-chain burn.
 */
return new class extends Migration {
    private const TABLES = [
        'umi_v2_sandbox_accounts', 'umi_v2_sandbox_entries', 'umi_v2_burn_lots',
        'umi_v2_burn_batches', 'umi_v2_withdrawal_funding',
        'umi_v2_point_claims', 'umi_v2_daily_runs', 'umi_v2_peer_reward_sources',
    ];

    public function up(): void
    {
        Schema::create('umi_v2_sandbox_accounts', function (Blueprint $t): void {
            $t->string('code', 100)->primary();
            $t->decimal('balance_umi', 54, 24)->default(0);
            $t->unsignedBigInteger('version_no')->default(0);
            $t->timestampsTz();
        });
        Schema::create('umi_v2_sandbox_entries', function (Blueprint $t): void {
            $t->id();
            $t->string('request_key', 160)->unique();
            $t->string('from_code', 100);
            $t->string('to_code', 100);
            $t->decimal('amount_umi', 54, 24);
            $t->string('kind', 40);
            $t->string('source_ref', 160);
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->dateTimeTz('created_at');
            $t->index(['from_code', 'created_at']);
            $t->index(['to_code', 'created_at']);
        });
        Schema::create('umi_v2_burn_batches', function (Blueprint $t): void {
            $t->id();
            $t->string('request_key', 160)->unique();
            $t->string('mode', 24)->default('sandbox');
            $t->string('status', 32)->default('simulated_confirmed');
            $t->decimal('amount_umi', 54, 24);
            $t->string('simulation_ref', 160)->unique();
            $t->unsignedBigInteger('actor_id');
            $t->timestampsTz();
        });
        Schema::create('umi_v2_burn_lots', function (Blueprint $t): void {
            $t->id();
            $t->string('source_key', 160)->unique();
            $t->string('purpose', 24); // activation | withdrawal | injury
            $t->unsignedBigInteger('member_id')->nullable();
            $t->unsignedBigInteger('cycle_id')->nullable();
            $t->unsignedBigInteger('withdrawal_id')->nullable();
            $t->decimal('amount_umi', 54, 24);
            $t->string('status', 24)->default('pending');
            $t->unsignedBigInteger('batch_id')->nullable();
            $t->dateTimeTz('confirmed_at')->nullable();
            $t->timestampsTz();
            $t->index(['status', 'id']);
            $t->index(['member_id', 'created_at']);
        });
        Schema::create('umi_v2_withdrawal_funding', function (Blueprint $t): void {
            $t->unsignedBigInteger('withdrawal_id')->primary();
            $t->decimal('from_income_umi', 54, 24)->default(0);
            $t->decimal('from_wallet_umi', 54, 24)->default(0);
            $t->decimal('external_topup_umi', 54, 24)->default(0);
            $t->decimal('remaining_umi', 54, 24)->default(0);
            $t->string('topup_ref', 160)->nullable()->unique();
            $t->timestampsTz();
        });
        Schema::create('umi_v2_point_claims', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('withdrawal_id')->unique();
            $t->unsignedBigInteger('member_id');
            $t->unsignedBigInteger('batch_id');
            $t->decimal('burned_umi', 54, 24);
            $t->decimal('umi_usd_quote', 54, 24);
            $t->decimal('share_usd_quote', 54, 24);
            $t->decimal('value_factor', 54, 24);
            $t->decimal('points', 54, 24);
            $t->string('status', 32)->default('points_only');
            $t->date('proposed_convert_on');
            $t->date('proposed_unlock_on');
            $t->timestampsTz();
        });
        Schema::create('umi_v2_daily_runs', function (Blueprint $t): void {
            $t->id();
            $t->date('business_date')->unique();
            $t->decimal('static_rate', 54, 24);
            $t->unsignedBigInteger('policy_version_id');
            $t->json('summary_json');
            $t->unsignedBigInteger('actor_id');
            $t->timestampsTz();
        });
        Schema::create('umi_v2_peer_reward_sources', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('parent_member_id');
            $t->unsignedBigInteger('child_member_id');
            $t->date('business_date');
            $t->unsignedTinyInteger('parent_level');
            $t->unsignedTinyInteger('child_level');
            $t->decimal('confirmed_base_umi', 54, 24);
            $t->decimal('candidate_umi', 54, 24);
            $t->decimal('released_umi', 54, 24);
            $t->string('source_event_id', 160)->unique();
            $t->timestampsTz();
        });
    }

    public function down(): void
    {
        // A code rollback must preserve local financial acceptance history.
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && \Illuminate\Support\Facades\DB::table($table)->exists()) {
                throw new RuntimeException('UMI v2 local acceptance data must not be dropped');
            }
        }
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }
    }
};
