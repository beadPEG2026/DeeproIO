<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('futures_contract', function (Blueprint $table) {
            $table->string('scheduled_status', 20)->nullable()->index()->after('status');
            $table->unsignedInteger('timeframe_seconds')->default(0)->after('scheduled_status');
            $table->timestamp('start_at')->nullable()->after('timeframe_seconds');
            $table->timestamp('activated_at')->nullable()->after('start_at');
        });
    }

    public function down(): void
    {
        Schema::table('futures_contract', function (Blueprint $table) {
            $table->dropColumn(['scheduled_status', 'timeframe_seconds', 'start_at', 'activated_at']);
        });
    }
};
