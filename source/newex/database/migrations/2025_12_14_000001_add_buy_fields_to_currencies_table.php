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
            // For coins: list of fiat symbols allowed to buy this crypto
            $table->json('allowed_buy_fiats')->nullable()->after('is_merchant');
            // For fiats: marks this fiat as allowed on buy onramp
            $table->boolean('allowed_buy_fiat')->default(false)->after('allowed_buy_fiats');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('currencies', function (Blueprint $table) {
            if (Schema::hasColumn('currencies', 'allowed_buy_fiats')) {
                $table->dropColumn('allowed_buy_fiats');
            }
            if (Schema::hasColumn('currencies', 'allowed_buy_fiat')) {
                $table->dropColumn('allowed_buy_fiat');
            }
        });
    }
};
