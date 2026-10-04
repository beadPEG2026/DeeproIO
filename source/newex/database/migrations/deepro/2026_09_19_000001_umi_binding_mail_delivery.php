<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('umi_binding_challenges', function (Blueprint $table) {
            $table->string('delivery_state', 20)->default('pending');
            $table->timestamp('sent_at')->nullable();
        });
    }
    public function down(): void {
        Schema::table('umi_binding_challenges', fn (Blueprint $table) => $table->dropColumn(['delivery_state', 'sent_at']));
    }
};
