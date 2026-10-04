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
        Schema::table('users', function (Blueprint $table) {
            $table->string('sumsub_applicant_id')->nullable();
            $table->string('sumsub_colleration_id')->nullable();
            $table->string('sumsub_applicant_status')->nullable();
            $table->string('sumsub_review_status')->nullable();
            $table->string('sumsub_account_type')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('sumsub_applicant_id');
            $table->dropColumn('sumsub_colleration_id');
            $table->dropColumn('sumsub_applicant_status');
            $table->dropColumn('sumsub_review_status');
            $table->dropColumn('sumsub_account_type');
        });
    }
};
