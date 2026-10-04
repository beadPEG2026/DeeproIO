<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
return new class extends Migration {
    public function up(): void {
        Schema::create('umi_custody_transfers', function(Blueprint $t) {
            $t->uuid('operation_id')->primary();
            $t->unsignedBigInteger('wallet_id')->index();
            $t->unsignedBigInteger('account_id')->nullable();
            $t->string('asset',20);
            $t->decimal('wallet_delta',36,18);
            $t->string('destination',80);
            $t->timestampTz('created_at');
        });
        DB::unprepared("CREATE FUNCTION umi_custody_immutable() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Custody transfer receipts are immutable'; END; $$; CREATE TRIGGER umi_custody_immutable BEFORE UPDATE OR DELETE ON umi_custody_transfers FOR EACH ROW EXECUTE FUNCTION umi_custody_immutable();");
    }
    public function down(): void {Schema::dropIfExists('umi_custody_transfers');DB::unprepared('DROP FUNCTION IF EXISTS umi_custody_immutable()');}
};
