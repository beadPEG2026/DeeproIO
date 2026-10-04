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

        Schema::create('merchant_invoices', function (Blueprint $table) use ($currencyLength, $currencyDecimals) {
            $table->uuid('id')->primary();
            $table->uuid('merchant_id')->index();
            $table->bigInteger('currency_id')->unsigned()->nullable()->index();

            // External reference
            $table->string('external_id', 255)->nullable()->index();
            $table->string('idempotency_key', 100)->nullable();

            // Status
            $table->string('status', 30)->default('awaiting_selection')->index();
            $table->string('previous_status', 30)->nullable();
            $table->string('status_reason', 255)->nullable();

            // Amount in USD (fixed)
            $table->decimal('amount_usd', 18, 2);
            $table->string('currency', 10)->default('USD');

            // Amount in crypto (calculated after currency selection)
            $table->decimal('amount_crypto', $currencyLength, $currencyDecimals)->nullable();
            $table->decimal('amount_crypto_min', $currencyLength, $currencyDecimals)->nullable(); // With tolerance
            $table->decimal('amount_crypto_max', $currencyLength, $currencyDecimals)->nullable(); // With tolerance

            // Received amounts
            $table->decimal('amount_received_crypto', $currencyLength, $currencyDecimals)->default(0);
            $table->decimal('amount_received_usd', 18, 2)->default(0);

            // Rate information
            $table->decimal('rate_usd', 24, 12)->nullable();
            $table->decimal('rate_bid', 24, 12)->nullable();
            $table->decimal('rate_ask', 24, 12)->nullable();
            $table->string('rate_source', 20)->nullable();
            $table->decimal('rate_spread_applied', 5, 4)->nullable();
            $table->timestamp('rate_locked_at')->nullable();
            $table->timestamp('rate_expires_at')->nullable();
            $table->tinyInteger('rate_extended_count')->unsigned()->default(0);
            $table->timestamp('rate_extended_at')->nullable();

            // Deposit address
            $table->string('deposit_address', 255)->nullable()->index();
            $table->string('deposit_memo', 100)->nullable();
            $table->uuid('deposit_address_id')->nullable()->index();

            // Customer information
            $table->string('customer_email', 255)->nullable();
            $table->string('customer_name', 255)->nullable();
            $table->json('customer_metadata')->nullable();

            // Invoice details
            $table->string('description', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->json('line_items')->nullable();

            // URLs
            $table->string('redirect_url', 500)->nullable();
            $table->string('cancel_url', 500)->nullable();
            $table->string('webhook_url', 500)->nullable(); // Override merchant default

            // Fees
            $table->decimal('fee_percent', 5, 4)->nullable();
            $table->decimal('fee_amount_crypto', $currencyLength, $currencyDecimals)->nullable();
            $table->decimal('fee_amount_usd', 18, 2)->nullable();
            $table->decimal('net_amount_crypto', $currencyLength, $currencyDecimals)->nullable();
            $table->decimal('net_amount_usd', 18, 2)->nullable();

            // Payment classification
            $table->string('payment_classification', 20)->nullable(); // exact, underpaid, overpaid
            $table->decimal('payment_variance_percent', 8, 4)->nullable();

            // Timestamps for lifecycle
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('selection_expires_at')->nullable();
            $table->timestamp('payment_expires_at')->nullable();
            $table->timestamp('currency_selected_at')->nullable();
            $table->timestamp('first_payment_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            // Cancellation details
            $table->string('cancellation_reason', 255)->nullable();
            $table->bigInteger('cancelled_by')->unsigned()->nullable();

            // Widget/API source
            $table->string('source', 20)->default('api'); // api, widget, dashboard
            $table->string('source_ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();

            // Environment
            $table->string('environment', 10)->default('live'); // live, sandbox

            $table->softDeletes();
            $table->timestamps();

            // Indexes
            $table->index(['merchant_id', 'status']);
            $table->index(['merchant_id', 'external_id']);
            $table->index(['merchant_id', 'created_at']);
            $table->index(['status', 'expires_at']);
            $table->index(['status', 'payment_expires_at']);
            $table->unique(['merchant_id', 'idempotency_key'], 'unique_merchant_idempotency');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merchant_invoices');
    }
};
