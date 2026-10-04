<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('chain_deposit_receipts', fn(Blueprint $t) => $t->json('recheck_anchor')->nullable()); }
    public function down(): void { /* Preserve accepted recovery evidence during code rollback. */ }
};
