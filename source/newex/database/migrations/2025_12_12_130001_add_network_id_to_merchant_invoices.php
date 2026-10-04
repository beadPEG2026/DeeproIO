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
        Schema::table('merchant_invoices', function (Blueprint $table) {
            $table->integer('network_id')->unsigned()->nullable()->after('currency_id');
            $table->index('network_id');
        });
        
        // Also add network_id to merchant_deposit_addresses if not exists
        Schema::table('merchant_deposit_addresses', function (Blueprint $table) {
            $table->integer('network_id')->unsigned()->nullable()->after('currency_id');
            $table->index('network_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('merchant_invoices', function (Blueprint $table) {
            $table->dropIndex(['network_id']);
            $table->dropColumn('network_id');
        });
        
        Schema::table('merchant_deposit_addresses', function (Blueprint $table) {
            $table->dropIndex(['network_id']);
            $table->dropColumn('network_id');
        });
    }
};
