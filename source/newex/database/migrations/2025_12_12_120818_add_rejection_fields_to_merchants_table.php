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
        Schema::table('merchants', function (Blueprint $table) {
            $table->text('rejection_reason')->nullable()->after('verified_by');
            $table->timestamp('rejected_at')->nullable()->after('rejection_reason');
            $table->integer('submission_count')->unsigned()->default(1)->after('rejected_at');
            $table->timestamp('last_submitted_at')->nullable()->after('submission_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn(['rejection_reason', 'rejected_at', 'submission_count', 'last_submitted_at']);
        });
    }
};
