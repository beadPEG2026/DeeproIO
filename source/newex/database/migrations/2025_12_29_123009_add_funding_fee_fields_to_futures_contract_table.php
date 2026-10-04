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
            $table->decimal('total_funding_fee_paid', $currencyLength, $currencyDecimals)->default(0)->after('released_amount');
            $table->timestamp('last_funding_fee_at')->nullable()->after('total_funding_fee_paid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('futures_contract', function (Blueprint $table) {
            $table->dropColumn(['total_funding_fee_paid', 'last_funding_fee_at']);
        });
    }
};
