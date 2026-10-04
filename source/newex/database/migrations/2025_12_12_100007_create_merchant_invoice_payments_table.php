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

        Schema::create('merchant_invoice_payments', function (Blueprint $table) use ($currencyLength, $currencyDecimals) {
            $table->uuid('id')->primary();
            $table->uuid('invoice_id')->index();
            $table->uuid('merchant_id')->index();
            $table->bigInteger('currency_id')->unsigned()->index();

            // Transaction details
            $table->string('txn_hash', 128)->index();
            $table->bigInteger('block_number')->unsigned()->nullable();
            $table->integer('block_index')->unsigned()->nullable();

            // Addresses
            $table->string('from_address', 255)->nullable();
            $table->string('to_address', 255);
            $table->string('memo', 100)->nullable();

            // Amounts
            $table->decimal('amount_crypto', $currencyLength, $currencyDecimals);
            $table->decimal('amount_usd', 18, 2);
            $table->decimal('rate_usd_at_detection', 24, 12)->nullable();

            // Confirmations
            $table->integer('confirmations')->unsigned()->default(0);
            $table->integer('required_confirmations')->unsigned();

            // Status
            $table->string('status', 20)->default('detecting')->index(); // detecting, confirming, confirmed, failed, orphaned
            $table->string('status_reason', 255)->nullable();

            // Classification
            $table->string('classification', 20)->nullable(); // exact, partial, overpayment, late
            $table->boolean('is_late_payment')->default(false);
            $table->boolean('counted_in_total')->default(true);

            // Timing
            $table->timestamp('detected_at')->nullable();
            $table->timestamp('first_confirmation_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            // Detection source
            $table->string('detection_source', 20)->default('mempool'); // mempool, block, manual
            $table->string('detection_node', 50)->nullable();

            // Blockchain data (for verification)
            $table->json('raw_transaction')->nullable();
            $table->json('block_data')->nullable();

            // Explorer URL
            $table->string('explorer_url', 500)->nullable();

            $table->timestamps();

            // Indexes
            $table->index(['invoice_id', 'status']);
            $table->index(['status', 'confirmations']);
            $table->unique(['txn_hash', 'to_address'], 'unique_txn_address');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merchant_invoice_payments');
    }
};
