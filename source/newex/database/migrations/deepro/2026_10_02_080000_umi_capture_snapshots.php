<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration {
    public function up(): void
    {
        Schema::create('umi_v2_capture_batches', function (Blueprint $t): void {
            $t->id(); $t->char('manifest_sha256', 64)->unique(); $t->char('payload_sha256', 64);
            $t->string('source', 200); $t->dateTimeTz('started_at'); $t->dateTimeTz('finished_at');
            $t->unsignedInteger('member_count'); $t->decimal('principal_umi', 54, 24);
            $t->json('payload_json'); $t->unsignedBigInteger('staged_by'); $t->dateTimeTz('created_at');
        });
        Schema::create('umi_v2_capture_accounts', function (Blueprint $t): void {
            $t->foreignId('batch_id')->constrained('umi_v2_capture_batches')->restrictOnDelete();
            $t->unsignedBigInteger('legacy_id'); $t->decimal('principal_umi', 54, 24);
            $t->json('balances_json'); $t->json('source_json'); $t->primary(['batch_id', 'legacy_id']);
        });
        Schema::create('umi_v2_capture_approvals', function (Blueprint $t): void {
            $t->id(); $t->foreignId('batch_id')->constrained('umi_v2_capture_batches')->restrictOnDelete();
            $t->char('source_sha256', 64)->unique(); $t->dateTimeTz('cutoff_at');
            $t->text('reason'); $t->unsignedBigInteger('actor_id'); $t->dateTimeTz('created_at');
        });
        Schema::table('umi_v2_account_merge_batches', fn(Blueprint $t) => $t->unsignedBigInteger('capture_batch_id')->nullable());
        if (DB::getDriverName() === 'pgsql') {
            foreach (['umi_v2_capture_batches','umi_v2_capture_accounts','umi_v2_capture_approvals'] as $table) {
                DB::unprepared("CREATE TRIGGER {$table}_guard BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION umi_v2_append_only();
                    CREATE TRIGGER {$table}_truncate_guard BEFORE TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION umi_v2_append_only();");
            }
        }
    }
    public function down(): void { throw new RuntimeException('Capture evidence and approval journals must survive code rollback.'); }
};
