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
        $currencyLength = 36;
        $currencyDecimals = 18;

        Schema::table('futures_contract', function (Blueprint $table) use ($currencyLength, $currencyDecimals) {
            $table->decimal('take_profit_price', $currencyLength, $currencyDecimals)->nullable()->after('liquidation_price')->index();
            $table->decimal('stop_loss_price', $currencyLength, $currencyDecimals)->nullable()->after('take_profit_price')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('futures_contract', function (Blueprint $table) {
            $table->dropColumn(['take_profit_price', 'stop_loss_price']);
        });
    }
};
