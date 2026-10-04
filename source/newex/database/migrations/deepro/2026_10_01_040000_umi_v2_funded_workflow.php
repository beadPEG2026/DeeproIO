<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Real-asset workflow records are separate from the local acceptance ledger. */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('umi_v2_live_settings', function (Blueprint $t): void {
            $t->unsignedTinyInteger('id')->primary();
            $t->unsignedBigInteger('pool_user_id')->nullable();
            $t->unsignedBigInteger('umi_network_id')->nullable();
            $t->string('dedicated_address', 42)->nullable();
            $t->boolean('intake_enabled')->default(false);
            $t->boolean('settlement_enabled')->default(false);
            $t->boolean('withdrawal_enabled')->default(false);
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestampsTz();
        });
        DB::table('umi_v2_live_settings')->insert(['id' => 1, 'created_at' => now(), 'updated_at' => now()]);

        Schema::create('umi_v2_live_settings_audit', function (Blueprint $t): void {
            $t->id();
            $t->string('request_key', 120)->unique();
            $t->json('before_json');
            $t->json('after_json');
            $t->unsignedBigInteger('actor_id');
            $t->dateTimeTz('created_at');
        });

        Schema::create('umi_v2_live_intents', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('member_id')->constrained('umi_v2_members')->restrictOnDelete();
            $t->string('request_key', 120)->unique();
            $t->string('source', 16); // wallet | verified_chain
            $t->string('status', 32); // awaiting_credit | pending_burn | active
            $t->decimal('amount_umi', 54, 24);
            $t->decimal('umi_usd_quote', 54, 24);
            $t->string('quote_source', 100);
            $t->dateTimeTz('quote_at');
            $t->unsignedBigInteger('deposit_id')->nullable()->unique();
            $t->foreignId('cycle_id')->nullable()->unique()->constrained('umi_v2_cycles')->restrictOnDelete();
            $t->dateTimeTz('expires_at')->nullable();
            $t->timestampsTz();
            $t->index(['member_id', 'status'], 'umi_v2_live_intent_member_status_idx');
        });

        Schema::create('umi_v2_live_wallet_moves', function (Blueprint $t): void {
            $t->id();
            $t->string('request_key', 160)->unique();
            $t->string('purpose', 32);
            $t->foreignId('member_id')->nullable()->constrained('umi_v2_members')->restrictOnDelete();
            $t->unsignedBigInteger('from_user_id')->nullable();
            $t->unsignedBigInteger('to_user_id')->nullable();
            $t->decimal('amount_umi', 54, 24);
            $t->string('source_ref', 160);
            $t->dateTimeTz('created_at');
            $t->index(['member_id', 'created_at'], 'umi_v2_live_wallet_member_idx');
        });

        Schema::create('umi_v2_live_quotes', function (Blueprint $t): void {
            $t->id();
            $t->string('asset', 24); // UMI_USDT | HK08379_USDT
            $t->decimal('price', 54, 24);
            $t->string('source', 120);
            $t->string('source_ref', 160);
            $t->dateTimeTz('observed_at');
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->dateTimeTz('created_at');
            $t->unique(['asset', 'source', 'source_ref'], 'umi_v2_live_quote_source_uq');
            $t->index(['asset', 'observed_at'], 'umi_v2_live_quote_asset_time_idx');
        });

        Schema::create('umi_v2_live_burn_lots', function (Blueprint $t): void {
            $t->id();
            $t->string('source_key', 160)->unique();
            $t->string('purpose', 16); // activation | withdrawal | injury
            $t->foreignId('member_id')->nullable()->constrained('umi_v2_members')->restrictOnDelete();
            $t->foreignId('cycle_id')->nullable()->constrained('umi_v2_cycles')->restrictOnDelete();
            $t->foreignId('withdrawal_id')->nullable()->constrained('umi_v2_withdrawals')->restrictOnDelete();
            $t->decimal('amount_umi', 54, 24);
            $t->string('status', 24)->default('pending'); // pending | custody | confirmed | review
            $t->unsignedBigInteger('custody_transfer_id')->nullable()->unique();
            $t->unsignedBigInteger('burn_evidence_id')->nullable()->unique();
            $t->dateTimeTz('confirmed_at')->nullable();
            $t->timestampsTz();
            $t->index(['status', 'created_at'], 'umi_v2_live_burn_pending_idx');
        });

        Schema::create('umi_v2_live_burn_proofs', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('lot_id')->unique()->constrained('umi_v2_live_burn_lots')->restrictOnDelete();
            $t->unsignedBigInteger('chain_id');
            $t->string('token_contract', 100);
            $t->string('tx_hash', 100);
            $t->unsignedInteger('log_index');
            $t->decimal('burned_umi', 54, 24);
            $t->unsignedBigInteger('block_number');
            $t->string('block_hash', 100);
            $t->char('receipt_sha256', 64);
            $t->dateTimeTz('finalized_at');
            $t->dateTimeTz('created_at');
            $t->unique(['chain_id', 'tx_hash', 'log_index'], 'umi_v2_live_burn_chain_log_uq');
        });

        Schema::create('umi_v2_live_withdrawal_funding', function (Blueprint $t): void {
            $t->foreignId('withdrawal_id')->primary()->constrained('umi_v2_withdrawals')->restrictOnDelete();
            $t->decimal('from_income_umi', 54, 24)->default(0);
            $t->decimal('from_wallet_umi', 54, 24)->default(0);
            $t->decimal('from_deposit_umi', 54, 24)->default(0);
            $t->unsignedBigInteger('deposit_id')->nullable()->unique();
            $t->decimal('remaining_umi', 54, 24)->default(0);
            $t->timestampsTz();
        });

        Schema::create('umi_v2_live_daily_runs', function (Blueprint $t): void {
            $t->id();
            $t->date('business_date')->unique();
            $t->decimal('static_rate', 54, 24);
            $t->foreignId('policy_version_id')->constrained('umi_v2_policy_versions')->restrictOnDelete();
            $t->json('summary_json');
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->timestampsTz();
        });

        Schema::create('umi_v2_live_quota_bonuses', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('cycle_id')->constrained('umi_v2_cycles')->restrictOnDelete();
            $t->foreignId('source_member_id')->constrained('umi_v2_members')->restrictOnDelete();
            $t->foreignId('source_cycle_id')->unique()->constrained('umi_v2_cycles')->restrictOnDelete();
            $t->string('request_key', 160)->unique();
            $t->decimal('added_quota_umi', 54, 24);
            $t->char('event_sha256', 64);
            $t->dateTimeTz('created_at');
        });

        Schema::create('umi_v2_live_peer_sources', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('parent_member_id')->constrained('umi_v2_members')->restrictOnDelete();
            $t->foreignId('child_member_id')->constrained('umi_v2_members')->restrictOnDelete();
            $t->date('business_date');
            $t->decimal('confirmed_grade_umi', 54, 24);
            $t->decimal('candidate_umi', 54, 24);
            $t->decimal('released_umi', 54, 24);
            $t->dateTimeTz('created_at');
            $t->unique(['parent_member_id', 'child_member_id', 'business_date'], 'umi_v2_live_peer_source_uq');
        });

        Schema::create('umi_v2_live_point_terms', function (Blueprint $t): void {
            $t->foreignId('point_entry_id')->primary()->constrained('umi_v2_stock_point_entries')->restrictOnDelete();
            $t->dateTimeTz('eligible_at');
            $t->dateTimeTz('unlock_at');
            $t->string('status', 24)->default('points_only');
            $t->dateTimeTz('created_at');
            $t->dateTimeTz('updated_at');
        });
    }

    public function down(): void
    {
        foreach (['umi_v2_live_settings_audit', 'umi_v2_live_intents', 'umi_v2_live_wallet_moves',
            'umi_v2_live_quotes',
            'umi_v2_live_burn_lots', 'umi_v2_live_burn_proofs', 'umi_v2_live_withdrawal_funding',
            'umi_v2_live_daily_runs', 'umi_v2_live_quota_bonuses',
            'umi_v2_live_peer_sources', 'umi_v2_live_point_terms'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('UMI financial records must survive code rollback');
            }
        }
        foreach (['umi_v2_live_point_terms', 'umi_v2_live_peer_sources', 'umi_v2_live_quota_bonuses',
            'umi_v2_live_daily_runs',
            'umi_v2_live_withdrawal_funding', 'umi_v2_live_burn_proofs', 'umi_v2_live_burn_lots',
            'umi_v2_live_wallet_moves', 'umi_v2_live_quotes', 'umi_v2_live_intents',
            'umi_v2_live_settings_audit', 'umi_v2_live_settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
