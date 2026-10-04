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
        Schema::table('kyc_documents', function (Blueprint $table) {
            $table->bigInteger('front_id')->unsigned()->nullable();
            $table->bigInteger('back_id')->unsigned()->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('kyc_documents', function (Blueprint $table) {
            $table->dropColumn('back_id');
            $table->dropColumn('front_id');
        });
    }
};
