<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use App\Models\Umi\LegacyAccount;
use App\Services\Umi\LegacyIntegrity;

return new class extends Migration {
    public function up():void {
        foreach(LegacyAccount::cursor() as $account)if(app(LegacyIntegrity::class)->account($account))throw new RuntimeException('UMI snapshot mismatch; review before protecting history.');
        DB::unprepared(<<<'SQL'
CREATE FUNCTION umi_preserve_account_snapshot() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'umi_original_account_immutable'; END IF;
 IF ROW(NEW.legacy_id,NEW.legacy_uuid,NEW.parent_legacy_id,NEW.level,NEW.legacy_status,NEW.batch_id,NEW.source_hash,NEW.identity,NEW.profile,NEW.email_lookup)
 IS DISTINCT FROM ROW(OLD.legacy_id,OLD.legacy_uuid,OLD.parent_legacy_id,OLD.level,OLD.legacy_status,OLD.batch_id,OLD.source_hash,OLD.identity,OLD.profile,OLD.email_lookup)
 THEN RAISE EXCEPTION 'umi_original_account_immutable'; END IF;
 RETURN NEW;
END; $$;
CREATE TRIGGER umi_original_account_immutable BEFORE UPDATE OR DELETE ON umi_legacy_accounts FOR EACH ROW EXECUTE FUNCTION umi_preserve_account_snapshot();
SQL);
        foreach(['umi_legacy_records','umi_legacy_summaries','umi_source_files','umi_import_batches','umi_recovery_actions'] as $table)
            DB::statement("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION umi_snapshot_append_only()");
    }
    public function down():void {throw new RuntimeException('Original UMI history protection requires an explicitly reviewed migration.');}
};
