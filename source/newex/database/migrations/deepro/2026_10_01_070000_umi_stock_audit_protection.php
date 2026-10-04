<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('umi_v2_live_point_terms', function (Blueprint $t): void {
            $t->index(['status', 'eligible_at'], 'umi_v2_point_due_idx');
            $t->index(['status', 'unlock_at'], 'umi_v2_share_unlock_idx');
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE FUNCTION umi_v2_stock_move_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
                BEGIN RAISE EXCEPTION 'UMI stock receipts are append-only'; END; $$;
                CREATE TRIGGER umi_v2_stock_move_guard BEFORE UPDATE OR DELETE ON umi_v2_stock_share_moves
                FOR EACH ROW EXECUTE FUNCTION umi_v2_stock_move_immutable();
                CREATE TRIGGER umi_v2_stock_move_truncate_guard BEFORE TRUNCATE ON umi_v2_stock_share_moves
                FOR EACH STATEMENT EXECUTE FUNCTION umi_v2_stock_move_immutable();");
        }
    }

    public function down(): void
    {
        if (DB::table('umi_v2_stock_share_moves')->exists()) {
            throw new RuntimeException('Stock receipts must remain protected across code rollback.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS umi_v2_stock_move_guard ON umi_v2_stock_share_moves;
                DROP TRIGGER IF EXISTS umi_v2_stock_move_truncate_guard ON umi_v2_stock_share_moves;
                DROP FUNCTION IF EXISTS umi_v2_stock_move_immutable();');
        }
        Schema::table('umi_v2_live_point_terms', function (Blueprint $t): void {
            $t->dropIndex('umi_v2_point_due_idx');
            $t->dropIndex('umi_v2_share_unlock_idx');
        });
    }
};
