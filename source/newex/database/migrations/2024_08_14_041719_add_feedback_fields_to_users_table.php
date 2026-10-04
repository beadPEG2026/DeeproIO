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
            $table->integer('feedback_positive')->default(0);
            $table->integer('feedback_negative')->default(0);
            $table->float('feedback_percentage')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('feedback_positive');
            $table->dropColumn('feedback_negative');
            $table->dropColumn('feedback_percentage');
        });
    }
};
