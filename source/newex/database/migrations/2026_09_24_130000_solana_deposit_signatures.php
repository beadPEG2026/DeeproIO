<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    public function up(): void {
        // Solana signatures are up to 88 Base58 characters. Retain all existing receipts.
        DB::statement('ALTER TABLE chain_deposit_receipts ALTER COLUMN txn TYPE varchar(100)');
        DB::statement('ALTER TABLE unrecognized_deposit_events ALTER COLUMN txn TYPE varchar(100)');
    }
    public function down(): void { throw new RuntimeException('Retain Solana financial receipts; do not truncate signatures on rollback.'); }
};
