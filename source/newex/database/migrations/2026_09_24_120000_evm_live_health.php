<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('chain_deposit_scan_states', function(Blueprint $t) { $t->timestamp('realtime_last_success_at')->nullable(); $t->string('realtime_last_error', 100)->nullable(); }); }
    public function down(): void { Schema::table('chain_deposit_scan_states', fn(Blueprint $t) => $t->dropColumn(['realtime_last_success_at','realtime_last_error'])); }
};
