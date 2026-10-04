<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};

return new class extends Migration {
    public function up(): void
    {
        Schema::table('umi_v2_live_settings', fn(Blueprint $t) => $t->boolean('account_cutover_enabled')->default(false));
        Schema::table('umi_v2_live_settings', fn(Blueprint $t) => $t->decimal('settlement_rate',8,6)->default('0.01'));
        Schema::create('umi_v2_account_merge_batches', function(Blueprint $t): void {
            $t->char('source_sha256',64)->primary(); $t->unsignedBigInteger('quote_id');
            $t->date('starts_on'); $t->unsignedBigInteger('actor_id'); $t->json('result_json'); $t->dateTimeTz('created_at');
        });
        Schema::create('umi_v2_account_merges', function(Blueprint $t): void {
            $t->id(); $t->unsignedBigInteger('business_account_id')->unique();
            $t->foreignId('member_id')->unique()->constrained('umi_v2_members')->restrictOnDelete();
            $t->unsignedBigInteger('user_id'); $t->unsignedBigInteger('legacy_id')->nullable();
            $t->decimal('principal_umi',54,24); $t->unsignedBigInteger('quote_id');
            $t->foreignId('cycle_id')->nullable()->unique()->constrained('umi_v2_cycles')->restrictOnDelete();
            $t->string('status',24); $t->json('balances_json'); $t->char('source_sha256',64);
            $t->uuid('operation_id')->unique(); $t->unsignedBigInteger('actor_id'); $t->dateTimeTz('created_at');
        });
        Schema::create('umi_v2_pending_principals',function(Blueprint $t): void {
            $t->foreignId('member_id')->primary()->constrained('umi_v2_members')->restrictOnDelete();
            $t->decimal('amount_umi',54,24)->default(0); $t->timestampsTz();
        });
        if (DB::getDriverName()==='pgsql') {
            DB::unprepared("CREATE TRIGGER umi_v2_account_merge_batch_guard BEFORE UPDATE OR DELETE ON umi_v2_account_merge_batches FOR EACH ROW EXECUTE FUNCTION umi_v2_append_only();
                CREATE TRIGGER umi_v2_account_merge_batch_truncate_guard BEFORE TRUNCATE ON umi_v2_account_merge_batches FOR EACH STATEMENT EXECUTE FUNCTION umi_v2_append_only();
                CREATE TRIGGER umi_v2_account_merge_guard BEFORE UPDATE OR DELETE ON umi_v2_account_merges FOR EACH ROW EXECUTE FUNCTION umi_v2_append_only();
                CREATE TRIGGER umi_v2_account_merge_truncate_guard BEFORE TRUNCATE ON umi_v2_account_merges FOR EACH STATEMENT EXECUTE FUNCTION umi_v2_append_only();");
        }
    }
    public function down(): void { throw new RuntimeException('Account conversion journals must survive code rollback.'); }
};
