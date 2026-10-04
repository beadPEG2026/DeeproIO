<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Independent UMI v2 schema. No legacy table is modified or imported here.
 *
 * Every token and point quantity is DECIMAL(54,24). Application code must use
 * decimal strings; PHP floats cannot safely represent these balances.
 * Financial rows are retained across code rollback. down() only drops a schema
 * which has never received business data.
 */
return new class extends Migration {
    private const TABLES = [
        'umi_v2_audit_events',
        'umi_v2_stock_point_entries',
        'umi_v2_burn_evidence',
        'umi_v2_market_fills',
        'umi_v2_withdrawal_allocations',
        'umi_v2_withdrawals',
        'umi_v2_income_transfer_allocations',
        'umi_v2_income_transfers',
        'umi_v2_release_events',
        'umi_v2_income_accounts',
        'umi_v2_cycles',
        'umi_v2_sponsor_edges',
        'umi_v2_members',
        'umi_v2_policy_versions',
    ];

    public function up(): void
    {
        Schema::create('umi_v2_policy_versions', function (Blueprint $t): void {
            $t->id();
            $t->string('policy_key', 80);
            $t->unsignedInteger('version');
            $t->string('status', 24)->default('draft');
            $t->json('rules_json');
            $t->char('rules_sha256', 64);
            $t->dateTimeTz('effective_at')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->dateTimeTz('approved_at')->nullable();
            $t->text('change_reason')->nullable();
            $t->timestampsTz();
            $t->unique(['policy_key', 'version'], 'umi_v2_policy_key_version_uq');
            $t->index(['status', 'effective_at'], 'umi_v2_policy_effective_idx');
        });

        Schema::create('umi_v2_members', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $t->string('legacy_identity_ref', 120)->nullable()->unique();
            $t->string('member_code', 40)->unique();
            $t->string('status', 24)->default('active');
            $t->unsignedTinyInteger('level')->default(0);
            $t->dateTimeTz('joined_at');
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestampsTz();
        });

        Schema::create('umi_v2_sponsor_edges', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('child_member_id')->unique()->constrained('umi_v2_members')->restrictOnDelete();
            $t->foreignId('parent_member_id')->constrained('umi_v2_members')->restrictOnDelete();
            $t->string('source_event_id', 120)->unique();
            $t->unsignedBigInteger('assigned_by')->nullable();
            $t->dateTimeTz('assigned_at');
            $t->text('reason')->nullable();
            $t->index('parent_member_id', 'umi_v2_sponsor_parent_idx');
        });

        Schema::create('umi_v2_cycles', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('member_id')->constrained('umi_v2_members')->restrictOnDelete();
            $t->foreignId('policy_version_id')->constrained('umi_v2_policy_versions')->restrictOnDelete();
            $t->unsignedInteger('cycle_number');
            $t->string('activation_request_key', 120)->unique();
            $t->string('activation_burn_ref', 160)->nullable()->unique();
            $t->unsignedTinyInteger('multiplier');
            $t->decimal('principal_umi', 54, 24);
            $t->decimal('usd_quote_per_umi', 54, 24);
            $t->decimal('principal_usd', 54, 24);
            $t->decimal('cap_umi', 54, 24);
            $t->decimal('released_umi', 54, 24)->default(0);
            $t->decimal('static_released_umi', 54, 24)->default(0);
            $t->decimal('team_released_umi', 54, 24)->default(0);
            $t->decimal('referral_released_umi', 54, 24)->default(0);
            $t->json('snapshot_json')->nullable();
            $t->char('snapshot_sha256', 64)->nullable();
            $t->unsignedBigInteger('version_no')->default(0);
            $t->string('status', 24)->default('pending_burn');
            $t->date('starts_on')->nullable();
            $t->date('last_settled_on')->nullable();
            $t->dateTimeTz('completed_at')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestampsTz();
            $t->unique(['member_id', 'cycle_number'], 'umi_v2_member_cycle_uq');
            $t->index(['member_id', 'status'], 'umi_v2_cycle_status_idx');
        });

        Schema::create('umi_v2_income_accounts', function (Blueprint $t): void {
            $t->foreignId('member_id')->primary()->constrained('umi_v2_members')->restrictOnDelete();
            foreach (['static_pending', 'team_pending', 'referral_pending', 'available', 'withdrawal_reserved', 'paid_total'] as $column) {
                $t->decimal($column, 54, 24)->default(0);
            }
            $t->unsignedBigInteger('version_no')->default(0);
            $t->timestampsTz();
        });

        Schema::create('umi_v2_release_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('cycle_id')->constrained('umi_v2_cycles')->restrictOnDelete();
            $t->foreignId('member_id')->constrained('umi_v2_members')->restrictOnDelete();
            $t->foreignId('policy_version_id')->constrained('umi_v2_policy_versions')->restrictOnDelete();
            $t->foreignId('source_member_id')->nullable()->constrained('umi_v2_members')->restrictOnDelete();
            $t->string('kind', 16); // static | team | referral
            $t->date('business_date');
            $t->string('source_event_id', 120);
            $t->string('request_key', 160)->unique();
            $t->decimal('candidate_umi', 54, 24);
            $t->decimal('released_umi', 54, 24);
            $t->decimal('cap_before_umi', 54, 24);
            $t->decimal('cap_after_umi', 54, 24);
            $t->string('status', 24)->default('posted');
            $t->char('calculation_sha256', 64);
            $t->unsignedBigInteger('created_by')->nullable();
            $t->dateTimeTz('created_at');
            $t->unique(['cycle_id', 'kind', 'business_date', 'source_event_id'], 'umi_v2_release_source_uq');
            $t->index(['member_id', 'business_date'], 'umi_v2_release_member_day_idx');
        });

        Schema::create('umi_v2_income_transfers', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('member_id')->constrained('umi_v2_members')->restrictOnDelete();
            $t->string('request_key', 160)->unique();
            $t->string('pocket', 16); // static | team | referral
            $t->string('from_bucket', 32)->default('pending');
            $t->string('to_bucket', 32)->default('available');
            $t->decimal('amount_umi', 54, 24);
            $t->decimal('pending_before_umi', 54, 24);
            $t->decimal('pending_after_umi', 54, 24);
            $t->decimal('available_before_umi', 54, 24);
            $t->decimal('available_after_umi', 54, 24);
            $t->unsignedBigInteger('created_by')->nullable();
            $t->dateTimeTz('created_at');
            $t->index(['member_id', 'created_at'], 'umi_v2_transfer_member_time_idx');
        });

        Schema::create('umi_v2_income_transfer_allocations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('transfer_id')->constrained('umi_v2_income_transfers')->restrictOnDelete();
            $t->foreignId('release_event_id')->constrained('umi_v2_release_events')->restrictOnDelete();
            $t->decimal('amount_umi', 54, 24);
            $t->dateTimeTz('created_at');
            $t->unique(['transfer_id', 'release_event_id'], 'umi_v2_transfer_release_uq');
        });

        Schema::create('umi_v2_withdrawals', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('member_id')->constrained('umi_v2_members')->restrictOnDelete();
            $t->foreignId('policy_version_id')->constrained('umi_v2_policy_versions')->restrictOnDelete();
            $t->string('request_key', 160)->unique();
            $t->string('status', 32)->default('requested');
            $t->decimal('requested_umi', 54, 24);
            $t->decimal('required_burn_umi', 54, 24);
            $t->decimal('confirmed_burn_umi', 54, 24)->default(0);
            $t->decimal('paid_umi', 54, 24)->default(0);
            $t->string('buy_quote_asset', 24)->nullable();
            $t->decimal('buy_cost', 54, 24)->nullable();
            $t->string('market_order_ref', 160)->nullable()->unique();
            $t->string('payout_ref', 160)->nullable()->unique();
            $t->string('last_error_code', 80)->nullable();
            $t->unsignedBigInteger('requested_by')->nullable();
            $t->dateTimeTz('requested_at');
            $t->dateTimeTz('paid_at')->nullable();
            $t->timestampsTz();
            $t->index(['member_id', 'status'], 'umi_v2_withdraw_member_status_idx');
        });

        Schema::create('umi_v2_withdrawal_allocations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('withdrawal_id')->constrained('umi_v2_withdrawals')->restrictOnDelete();
            $t->foreignId('release_event_id')->constrained('umi_v2_release_events')->restrictOnDelete();
            $t->decimal('amount_umi', 54, 24);
            $t->dateTimeTz('created_at');
            $t->unique(['withdrawal_id', 'release_event_id'], 'umi_v2_withdraw_release_uq');
        });

        Schema::create('umi_v2_market_fills', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('withdrawal_id')->constrained('umi_v2_withdrawals')->restrictOnDelete();
            $t->string('venue', 80);
            $t->string('external_fill_ref', 160);
            $t->string('quote_asset', 24);
            $t->decimal('bought_umi', 54, 24);
            $t->decimal('quote_cost', 54, 24);
            $t->decimal('execution_price', 54, 24);
            $t->dateTimeTz('filled_at');
            $t->dateTimeTz('created_at');
            $t->unique(['venue', 'external_fill_ref'], 'umi_v2_market_fill_ref_uq');
            $t->index('withdrawal_id', 'umi_v2_market_fill_withdraw_idx');
        });

        Schema::create('umi_v2_burn_evidence', function (Blueprint $t): void {
            $t->id();
            $t->string('purpose', 16); // activation | withdrawal
            $t->foreignId('cycle_id')->nullable()->unique()->constrained('umi_v2_cycles')->restrictOnDelete();
            $t->foreignId('withdrawal_id')->nullable()->constrained('umi_v2_withdrawals')->restrictOnDelete();
            $t->unsignedBigInteger('chain_id');
            $t->string('token_contract', 100);
            $t->string('tx_hash', 100);
            $t->unsignedInteger('log_index');
            $t->decimal('burned_umi', 54, 24);
            $t->unsignedBigInteger('block_number');
            $t->string('block_hash', 100);
            $t->string('finality_status', 24)->default('pending');
            $t->dateTimeTz('finalized_at')->nullable();
            $t->char('receipt_sha256', 64);
            $t->dateTimeTz('created_at');
            $t->unique(['chain_id', 'tx_hash', 'log_index'], 'umi_v2_burn_chain_log_uq');
            $t->index('withdrawal_id', 'umi_v2_burn_withdraw_idx');
        });

        Schema::create('umi_v2_stock_point_entries', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('member_id')->constrained('umi_v2_members')->restrictOnDelete();
            $t->foreignId('withdrawal_id')->nullable()->constrained('umi_v2_withdrawals')->restrictOnDelete();
            $t->foreignId('burn_evidence_id')->nullable()->unique()->constrained('umi_v2_burn_evidence')->restrictOnDelete();
            $t->foreignId('policy_version_id')->constrained('umi_v2_policy_versions')->restrictOnDelete();
            $t->foreignId('reverses_entry_id')->nullable()->constrained('umi_v2_stock_point_entries')->restrictOnDelete();
            $t->string('request_key', 160)->unique();
            $t->string('kind', 24); // burn_credit | correction | reversal
            $t->string('program_id', 80)->nullable();
            $t->decimal('points_per_burned_umi', 54, 24)->nullable();
            $t->string('burn_confirmation_ref', 160)->nullable();
            $t->decimal('delta_points', 54, 24);
            $t->decimal('balance_after_points', 54, 24);
            $t->decimal('source_burned_umi', 54, 24)->nullable();
            $t->char('source_sha256', 64);
            $t->unsignedBigInteger('created_by')->nullable();
            $t->text('reason')->nullable();
            $t->dateTimeTz('created_at');
            $t->index(['member_id', 'created_at'], 'umi_v2_points_member_time_idx');
        });

        Schema::create('umi_v2_audit_events', function (Blueprint $t): void {
            $t->id();
            $t->string('request_key', 160)->unique();
            $t->string('object_type', 48);
            $t->string('object_id', 120);
            $t->string('action', 80);
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->text('reason')->nullable();
            $t->char('before_sha256', 64)->nullable();
            $t->char('after_sha256', 64)->nullable();
            $t->json('context_json')->nullable();
            $t->dateTimeTz('created_at');
            $t->index(['object_type', 'object_id', 'created_at'], 'umi_v2_audit_object_time_idx');
        });

        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE umi_v2_release_events ADD CONSTRAINT umi_v2_release_kind_ck CHECK (kind IN ('static','team','referral'))");
            DB::statement("ALTER TABLE umi_v2_income_transfers ADD CONSTRAINT umi_v2_transfer_pocket_ck CHECK (pocket IN ('static','team','referral'))");
            DB::statement("ALTER TABLE umi_v2_burn_evidence ADD CONSTRAINT umi_v2_burn_purpose_ck CHECK ((purpose = 'activation' AND cycle_id IS NOT NULL AND withdrawal_id IS NULL) OR (purpose = 'withdrawal' AND withdrawal_id IS NOT NULL AND cycle_id IS NULL))");
            DB::statement("ALTER TABLE umi_v2_burn_evidence ADD CONSTRAINT umi_v2_burn_finality_ck CHECK ((finality_status = 'pending' AND finalized_at IS NULL) OR (finality_status = 'final' AND finalized_at IS NOT NULL))");
            DB::statement('ALTER TABLE umi_v2_cycles ADD CONSTRAINT umi_v2_cycle_quota_ck CHECK (cap_umi >= 0 AND released_umi >= 0 AND released_umi <= cap_umi)');
            DB::statement('ALTER TABLE umi_v2_release_events ADD CONSTRAINT umi_v2_release_amount_ck CHECK (released_umi >= 0 AND cap_after_umi <= cap_before_umi)');
            DB::statement('ALTER TABLE umi_v2_income_transfers ADD CONSTRAINT umi_v2_transfer_amount_ck CHECK (amount_umi > 0 AND pending_after_umi <= pending_before_umi AND available_after_umi >= available_before_umi)');
            DB::statement('ALTER TABLE umi_v2_income_transfer_allocations ADD CONSTRAINT umi_v2_transfer_allocation_amount_ck CHECK (amount_umi > 0)');
            DB::statement('ALTER TABLE umi_v2_withdrawal_allocations ADD CONSTRAINT umi_v2_withdraw_allocation_amount_ck CHECK (amount_umi > 0)');
            DB::statement('ALTER TABLE umi_v2_market_fills ADD CONSTRAINT umi_v2_market_fill_amount_ck CHECK (bought_umi > 0 AND quote_cost >= 0 AND execution_price > 0)');
            DB::statement('ALTER TABLE umi_v2_burn_evidence ADD CONSTRAINT umi_v2_burn_amount_ck CHECK (burned_umi > 0)');
            DB::statement('ALTER TABLE umi_v2_stock_point_entries ADD CONSTRAINT umi_v2_stock_points_kind_ck CHECK (kind IN (\'burn_credit\',\'correction\',\'reversal\'))');
            DB::statement("ALTER TABLE umi_v2_stock_point_entries ADD CONSTRAINT umi_v2_stock_burn_credit_ck CHECK (kind <> 'burn_credit' OR (burn_evidence_id IS NOT NULL AND withdrawal_id IS NOT NULL AND program_id IS NOT NULL AND points_per_burned_umi > 0 AND burn_confirmation_ref IS NOT NULL AND source_burned_umi > 0))");
            // A pending receipt may become final once. All chain identity, amount,
            // source and audit fields remain fixed; reorgs require new evidence.
            DB::unprepared(<<<'SQL'
CREATE FUNCTION umi_v2_burn_transition_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'umi_v2_burn_evidence is immutable';
    END IF;
    IF OLD.finality_status <> 'pending' OR NEW.finality_status <> 'final'
       OR NEW.finalized_at IS NULL
       OR (to_jsonb(NEW) - 'finality_status' - 'finalized_at')
          IS DISTINCT FROM (to_jsonb(OLD) - 'finality_status' - 'finalized_at') THEN
        RAISE EXCEPTION 'umi_v2_burn_evidence permits only pending to final';
    END IF;
    RETURN NEW;
END;
$$
SQL);
            DB::unprepared('CREATE TRIGGER umi_v2_burn_transition BEFORE UPDATE OR DELETE ON umi_v2_burn_evidence FOR EACH ROW EXECUTE FUNCTION umi_v2_burn_transition_guard()');
            DB::unprepared(<<<'SQL'
CREATE FUNCTION umi_v2_stock_credit_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.kind = 'burn_credit' AND NOT EXISTS (
        SELECT 1 FROM umi_v2_burn_evidence AS b
        JOIN umi_v2_withdrawals AS w ON w.id = b.withdrawal_id
        WHERE b.id = NEW.burn_evidence_id
          AND b.purpose = 'withdrawal'
          AND b.finality_status = 'final'
          AND b.withdrawal_id = NEW.withdrawal_id
          AND w.member_id = NEW.member_id
          AND b.burned_umi = NEW.source_burned_umi
    ) THEN
        RAISE EXCEPTION 'burn_credit requires final withdrawal burn evidence';
    END IF;
    RETURN NEW;
END;
$$
SQL);
            DB::unprepared('CREATE TRIGGER umi_v2_stock_credit_insert BEFORE INSERT ON umi_v2_stock_point_entries FOR EACH ROW EXECUTE FUNCTION umi_v2_stock_credit_guard()');
            DB::unprepared("CREATE FUNCTION umi_v2_append_only() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'umi_v2_append_only'; END; $$");
            foreach ([
                'umi_v2_release_events',
                'umi_v2_income_transfers',
                'umi_v2_income_transfer_allocations',
                'umi_v2_withdrawal_allocations',
                'umi_v2_market_fills',
                'umi_v2_stock_point_entries',
                'umi_v2_audit_events',
            ] as $table) {
                DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION umi_v2_append_only()");
                DB::unprepared("CREATE TRIGGER {$table}_no_truncate BEFORE TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION umi_v2_append_only()");
            }
            DB::unprepared('CREATE TRIGGER umi_v2_burn_evidence_no_truncate BEFORE TRUNCATE ON umi_v2_burn_evidence FOR EACH STATEMENT EXECUTE FUNCTION umi_v2_append_only()');
        } elseif ($driver === 'mysql') {
            // MySQL deployments may predate enforced CHECK constraints. Triggers
            // reject invalid rows on the configured production driver itself.
            DB::unprepared(<<<'SQL'
CREATE TRIGGER umi_v2_burn_insert_guard BEFORE INSERT ON umi_v2_burn_evidence FOR EACH ROW
BEGIN
    IF NOT (((NEW.purpose = 'activation' AND NEW.cycle_id IS NOT NULL AND NEW.withdrawal_id IS NULL)
         OR (NEW.purpose = 'withdrawal' AND NEW.withdrawal_id IS NOT NULL AND NEW.cycle_id IS NULL))
        AND ((NEW.finality_status = 'pending' AND NEW.finalized_at IS NULL)
         OR (NEW.finality_status = 'final' AND NEW.finalized_at IS NOT NULL))
        AND NEW.burned_umi > 0) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'invalid burn purpose or finality';
    END IF;
END
SQL);
            DB::unprepared(<<<'SQL'
CREATE TRIGGER umi_v2_burn_update_guard BEFORE UPDATE ON umi_v2_burn_evidence FOR EACH ROW
BEGIN
    IF NOT (OLD.finality_status = 'pending' AND NEW.finality_status = 'final'
        AND NEW.finalized_at IS NOT NULL
        AND (OLD.id <=> NEW.id) AND (OLD.purpose <=> NEW.purpose)
        AND (OLD.cycle_id <=> NEW.cycle_id) AND (OLD.withdrawal_id <=> NEW.withdrawal_id)
        AND (OLD.chain_id <=> NEW.chain_id) AND (OLD.token_contract <=> NEW.token_contract)
        AND (OLD.tx_hash <=> NEW.tx_hash) AND (OLD.log_index <=> NEW.log_index)
        AND (OLD.burned_umi <=> NEW.burned_umi) AND (OLD.block_number <=> NEW.block_number)
        AND (OLD.block_hash <=> NEW.block_hash) AND (OLD.receipt_sha256 <=> NEW.receipt_sha256)
        AND (OLD.created_at <=> NEW.created_at)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'burn evidence permits only pending to final';
    END IF;
END
SQL);
            DB::unprepared("CREATE TRIGGER umi_v2_burn_delete_guard BEFORE DELETE ON umi_v2_burn_evidence FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'burn evidence is immutable'");
            DB::unprepared(<<<'SQL'
CREATE TRIGGER umi_v2_stock_credit_insert_guard BEFORE INSERT ON umi_v2_stock_point_entries FOR EACH ROW
BEGIN
    IF NEW.kind NOT IN ('burn_credit', 'correction', 'reversal') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'invalid stock point kind';
    END IF;
    IF NEW.kind = 'burn_credit' AND (NEW.burn_evidence_id IS NULL OR NEW.withdrawal_id IS NULL
        OR NEW.program_id IS NULL OR NEW.points_per_burned_umi IS NULL
        OR NEW.points_per_burned_umi <= 0 OR NEW.burn_confirmation_ref IS NULL
        OR NEW.source_burned_umi IS NULL OR NEW.source_burned_umi <= 0
        OR NOT EXISTS (
            SELECT 1 FROM umi_v2_burn_evidence AS b
            JOIN umi_v2_withdrawals AS w ON w.id = b.withdrawal_id
            WHERE b.id = NEW.burn_evidence_id AND b.purpose = 'withdrawal'
              AND b.finality_status = 'final' AND b.withdrawal_id = NEW.withdrawal_id
              AND w.member_id = NEW.member_id
              AND b.burned_umi = NEW.source_burned_umi
        )) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'burn_credit requires final withdrawal burn evidence';
    END IF;
END
SQL);
            DB::unprepared(<<<'SQL'
CREATE TRIGGER umi_v2_cycle_insert_guard BEFORE INSERT ON umi_v2_cycles FOR EACH ROW
BEGIN
    IF NOT (NEW.cap_umi >= 0 AND NEW.released_umi >= 0 AND NEW.released_umi <= NEW.cap_umi) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'invalid cycle quota';
    END IF;
END
SQL);
            DB::unprepared(<<<'SQL'
CREATE TRIGGER umi_v2_cycle_update_guard BEFORE UPDATE ON umi_v2_cycles FOR EACH ROW
BEGIN
    IF NOT (NEW.cap_umi >= 0 AND NEW.released_umi >= 0 AND NEW.released_umi <= NEW.cap_umi) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'invalid cycle quota';
    END IF;
END
SQL);
            DB::unprepared(<<<'SQL'
CREATE TRIGGER umi_v2_release_insert_guard BEFORE INSERT ON umi_v2_release_events FOR EACH ROW
BEGIN
    IF NOT (NEW.kind IN ('static', 'team', 'referral') AND NEW.released_umi >= 0
        AND NEW.cap_after_umi <= NEW.cap_before_umi) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'invalid release event';
    END IF;
END
SQL);
            DB::unprepared(<<<'SQL'
CREATE TRIGGER umi_v2_transfer_insert_guard BEFORE INSERT ON umi_v2_income_transfers FOR EACH ROW
BEGIN
    IF NOT (NEW.pocket IN ('static', 'team', 'referral') AND NEW.amount_umi > 0
        AND NEW.pending_after_umi <= NEW.pending_before_umi
        AND NEW.available_after_umi >= NEW.available_before_umi) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'invalid income transfer';
    END IF;
END
SQL);
            DB::unprepared(<<<'SQL'
CREATE TRIGGER umi_v2_transfer_allocation_insert_guard BEFORE INSERT ON umi_v2_income_transfer_allocations FOR EACH ROW
BEGIN
    IF NOT (NEW.amount_umi > 0) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'invalid transfer allocation amount';
    END IF;
END
SQL);
            DB::unprepared(<<<'SQL'
CREATE TRIGGER umi_v2_withdraw_allocation_insert_guard BEFORE INSERT ON umi_v2_withdrawal_allocations FOR EACH ROW
BEGIN
    IF NOT (NEW.amount_umi > 0) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'invalid withdrawal allocation amount';
    END IF;
END
SQL);
            DB::unprepared(<<<'SQL'
CREATE TRIGGER umi_v2_market_fill_insert_guard BEFORE INSERT ON umi_v2_market_fills FOR EACH ROW
BEGIN
    IF NOT (NEW.bought_umi > 0 AND NEW.quote_cost >= 0 AND NEW.execution_price > 0) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'invalid market fill amount';
    END IF;
END
SQL);
            foreach ([
                'umi_v2_release_events',
                'umi_v2_income_transfers',
                'umi_v2_income_transfer_allocations',
                'umi_v2_withdrawal_allocations',
                'umi_v2_market_fills',
                'umi_v2_stock_point_entries',
                'umi_v2_audit_events',
            ] as $table) {
                DB::unprepared("CREATE TRIGGER {$table}_immutable_update BEFORE UPDATE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'umi_v2_append_only'");
                DB::unprepared("CREATE TRIGGER {$table}_immutable_delete BEFORE DELETE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'umi_v2_append_only'");
            }
        } elseif ($driver === 'sqlite') {
            // The isolated SQLite schema test executes equivalent guards rather
            // than merely searching migration text for production SQL.
            DB::unprepared(<<<'SQL'
CREATE TRIGGER umi_v2_burn_insert_guard BEFORE INSERT ON umi_v2_burn_evidence FOR EACH ROW BEGIN
    SELECT CASE WHEN NOT (
        ((NEW.purpose = 'activation' AND NEW.cycle_id IS NOT NULL AND NEW.withdrawal_id IS NULL)
         OR (NEW.purpose = 'withdrawal' AND NEW.withdrawal_id IS NOT NULL AND NEW.cycle_id IS NULL))
        AND ((NEW.finality_status = 'pending' AND NEW.finalized_at IS NULL)
         OR (NEW.finality_status = 'final' AND NEW.finalized_at IS NOT NULL))
    ) THEN RAISE(ABORT, 'invalid burn purpose or finality') END;
END
SQL);
            DB::unprepared(<<<'SQL'
CREATE TRIGGER umi_v2_burn_update_guard BEFORE UPDATE ON umi_v2_burn_evidence FOR EACH ROW BEGIN
    SELECT CASE WHEN NOT (
        OLD.finality_status = 'pending' AND NEW.finality_status = 'final'
        AND NEW.finalized_at IS NOT NULL
        AND OLD.id IS NEW.id AND OLD.purpose IS NEW.purpose
        AND OLD.cycle_id IS NEW.cycle_id AND OLD.withdrawal_id IS NEW.withdrawal_id
        AND OLD.chain_id IS NEW.chain_id AND OLD.token_contract IS NEW.token_contract
        AND OLD.tx_hash IS NEW.tx_hash AND OLD.log_index IS NEW.log_index
        AND OLD.burned_umi IS NEW.burned_umi AND OLD.block_number IS NEW.block_number
        AND OLD.block_hash IS NEW.block_hash AND OLD.receipt_sha256 IS NEW.receipt_sha256
        AND OLD.created_at IS NEW.created_at
    ) THEN RAISE(ABORT, 'burn evidence permits only pending to final') END;
END
SQL);
            DB::unprepared("CREATE TRIGGER umi_v2_burn_delete_guard BEFORE DELETE ON umi_v2_burn_evidence FOR EACH ROW BEGIN SELECT RAISE(ABORT, 'burn evidence is immutable'); END");
            DB::unprepared(<<<'SQL'
CREATE TRIGGER umi_v2_stock_credit_insert_guard BEFORE INSERT ON umi_v2_stock_point_entries FOR EACH ROW BEGIN
    SELECT CASE WHEN NEW.kind NOT IN ('burn_credit', 'correction', 'reversal')
        THEN RAISE(ABORT, 'invalid stock point kind') END;
    SELECT CASE WHEN NEW.kind = 'burn_credit' AND (
        NEW.burn_evidence_id IS NULL OR NEW.withdrawal_id IS NULL
        OR NEW.program_id IS NULL OR NEW.points_per_burned_umi IS NULL
        OR NEW.points_per_burned_umi <= 0 OR NEW.burn_confirmation_ref IS NULL
        OR NEW.source_burned_umi IS NULL OR NEW.source_burned_umi <= 0
        OR NOT EXISTS (
            SELECT 1 FROM umi_v2_burn_evidence AS b
            JOIN umi_v2_withdrawals AS w ON w.id = b.withdrawal_id
            WHERE b.id = NEW.burn_evidence_id AND b.purpose = 'withdrawal'
              AND b.finality_status = 'final' AND b.withdrawal_id = NEW.withdrawal_id
              AND w.member_id = NEW.member_id
              AND b.burned_umi = NEW.source_burned_umi
        )) THEN RAISE(ABORT, 'burn_credit requires final withdrawal burn evidence') END;
END
SQL);
            DB::unprepared("CREATE TRIGGER umi_v2_stock_credit_update_guard BEFORE UPDATE ON umi_v2_stock_point_entries FOR EACH ROW BEGIN SELECT RAISE(ABORT, 'stock point entries are immutable'); END");
            DB::unprepared("CREATE TRIGGER umi_v2_stock_credit_delete_guard BEFORE DELETE ON umi_v2_stock_point_entries FOR EACH ROW BEGIN SELECT RAISE(ABORT, 'stock point entries are immutable'); END");
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            if (Schema::hasTable($name) && DB::table($name)->exists()) {
                throw new RuntimeException('UMI v2 has business data; retain schema and use a compatible code rollback.');
            }
        }
        foreach (self::TABLES as $name) {
            Schema::dropIfExists($name);
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS umi_v2_stock_credit_guard()');
            DB::unprepared('DROP FUNCTION IF EXISTS umi_v2_burn_transition_guard()');
            DB::unprepared('DROP FUNCTION IF EXISTS umi_v2_append_only()');
        }
    }
};
