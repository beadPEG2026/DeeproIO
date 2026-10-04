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

        // Payments that cannot be matched to an invoice
        Schema::create('merchant_orphan_payments', function (Blueprint $table) use ($currencyLength, $currencyDecimals) {
            $table->uuid('id')->primary();
            $table->uuid('merchant_id')->nullable()->index();
            $table->uuid('address_id')->nullable()->index();
            $table->bigInteger('currency_id')->unsigned()->index();

            // Transaction details
            $table->string('txn_hash', 128)->index();
            $table->bigInteger('block_number')->unsigned()->nullable();

            // Addresses
            $table->string('from_address', 255)->nullable();
            $table->string('to_address', 255)->index();
            $table->string('memo', 100)->nullable();

            // Amounts
            $table->decimal('amount_crypto', $currencyLength, $currencyDecimals);
            $table->decimal('amount_usd', 18, 2)->nullable();
            $table->decimal('rate_usd_at_detection', 24, 12)->nullable();

            // Why it's orphaned
            $table->string('orphan_reason', 50); // no_matching_invoice, invoice_expired, address_not_assigned, duplicate_payment
            $table->uuid('related_invoice_id')->nullable()->index(); // If we can identify related expired invoice

            // Status
            $table->string('status', 20)->default('pending')->index(); // pending, claimed, refunded, swept

            // Resolution
            $table->string('resolution_type', 20)->nullable(); // refund, credit, sweep
            $table->uuid('resolution_invoice_id')->nullable(); // If credited to new invoice
            $table->string('resolution_txn_hash', 128)->nullable(); // If refunded
            $table->text('resolution_notes')->nullable();
            $table->bigInteger('resolved_by')->unsigned()->nullable();
            $table->timestamp('resolved_at')->nullable();

            // Timing
            $table->timestamp('detected_at');
            $table->integer('confirmations')->unsigned()->default(0);
            $table->timestamp('confirmed_at')->nullable();

            // Explorer
            $table->string('explorer_url', 500)->nullable();

            $table->timestamps();

            // Indexes
            $table->index(['status', 'detected_at']);
            $table->index(['merchant_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merchant_orphan_payments');
    }
};
