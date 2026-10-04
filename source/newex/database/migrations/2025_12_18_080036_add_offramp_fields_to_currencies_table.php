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
        Schema::table('currencies', function (Blueprint $table) {
            // Off-ramp (sell) settings for crypto currencies
            $table->boolean('allowed_sell_crypto')->default(false)->after('allowed_buy_fiat');
            
            // Off-ramp (sell) settings for fiat currencies
            $table->boolean('allowed_sell_fiat')->default(false)->after('allowed_sell_crypto');
            
            // Allowed fiat currencies for selling crypto (array of fiat symbols)
            $table->json('allowed_sell_fiats')->nullable()->after('allowed_sell_fiat');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('currencies', function (Blueprint $table) {
            $table->dropColumn(['allowed_sell_crypto', 'allowed_sell_fiat', 'allowed_sell_fiats']);
        });
    }
};
