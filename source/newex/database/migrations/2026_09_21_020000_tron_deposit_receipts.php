<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('tron_deposit_receipts', function (Blueprint $t) {
            $t->string('txn', 64)->primary();
            $t->unsignedBigInteger('deposit_id')->unique();
            $t->unsignedBigInteger('wallet_id');
            $t->decimal('credited_amount', 36, 18);
            $t->json('evidence');
            $t->timestamp('created_at');
        });
        Schema::create('tron_deposit_scan_states', function (Blueprint $t) {
            $t->string('address', 34)->primary();
            $t->unsignedBigInteger('window_start')->nullable();
            $t->unsignedBigInteger('window_end')->nullable();
            $t->text('fingerprint')->nullable();
            $t->unsignedBigInteger('scanned_through')->nullable();
            $t->timestamp('last_success_at')->nullable();
            $t->string('last_error', 100)->nullable();
            $t->timestamp('updated_at')->nullable();
        });
    }
    public function down(): void {
        throw new RuntimeException('Retain deposit evidence and checkpoints on code rollback. Use a reviewed forward migration.');
    }
};
