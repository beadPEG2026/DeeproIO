<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up():void {
        // Row triggers do not cover TRUNCATE, including cascades from related tables.
        foreach(['umi_legacy_accounts','umi_legacy_records','umi_legacy_summaries','umi_source_files','umi_import_batches','umi_recovery_actions'] as $table)
            DB::statement("CREATE TRIGGER {$table}_no_truncate BEFORE TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION umi_snapshot_append_only()");
    }
    public function down():void {throw new RuntimeException('Original UMI history protection requires an explicitly reviewed migration.');}
};
