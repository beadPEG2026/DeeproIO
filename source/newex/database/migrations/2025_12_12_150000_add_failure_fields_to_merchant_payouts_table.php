<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('merchant_payouts', function (Blueprint $table) {
            $table->text('error_message')->nullable()->after('admin_notes');
            $table->string('error_code', 100)->nullable()->after('error_message');
            $table->integer('retry_count')->default(0)->after('error_code');
            $table->timestamp('last_retry_at')->nullable()->after('retry_count');
            $table->timestamp('failed_at')->nullable()->after('last_retry_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('merchant_payouts', function (Blueprint $table) {
            $table->dropColumn(['error_message', 'error_code', 'retry_count', 'last_retry_at', 'failed_at']);
        });
    }
};
