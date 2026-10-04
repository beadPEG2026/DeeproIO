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
        Schema::table('merchant_invoice_payments', function (Blueprint $table) {
            $table->timestamp('collected_at')->nullable()->after('confirmed_at');
            $table->string('collection_txn_hash', 128)->nullable()->after('collected_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('merchant_invoice_payments', function (Blueprint $table) {
            $table->dropColumn(['collected_at', 'collection_txn_hash']);
        });
    }
};
