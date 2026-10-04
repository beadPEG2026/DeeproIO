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

        Schema::create('merchant_refunds', function (Blueprint $table) use ($currencyLength, $currencyDecimals) {
            $table->uuid('id')->primary();
            $table->uuid('merchant_id')->index();
            $table->uuid('invoice_id')->index();
            $table->uuid('payment_id')->nullable()->index(); // Original payment being refunded
            $table->uuid('orphan_payment_id')->nullable()->index(); // If refunding orphan payment
            
            // Refund type
            $table->string('refund_type', 20); // full, partial, overpayment, late_payment
            $table->string('reason', 50); // merchant_request, overpayment, late_payment, duplicate, system_error
            $table->text('reason_details')->nullable();
            
            // Amounts
            $table->decimal('amount_crypto', $currencyLength, $currencyDecimals);
            $table->decimal('amount_usd', 18, 2);
            $table->decimal('rate_usd', 24, 12)->nullable();
            
            // Destination
            $table->string('destination_address', 255);
            $table->string('destination_memo', 100)->nullable();
            $table->bigInteger('destination_network_id')->unsigned()->nullable();
            
            // Status
            $table->string('status', 20)->default('pending')->index(); // pending, processing, broadcast, confirming, completed, failed, cancelled
            
            // Transaction
            $table->string('txn_hash', 128)->nullable()->index();
            $table->integer('confirmations')->unsigned()->default(0);
            $table->decimal('network_fee_crypto', $currencyLength, $currencyDecimals)->nullable();
            $table->decimal('network_fee_usd', 18, 2)->nullable();
            
            // Timestamps
            $table->timestamp('requested_at');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('broadcast_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            
            // Approval workflow
            $table->bigInteger('requested_by')->unsigned()->nullable();
            $table->bigInteger('approved_by')->unsigned()->nullable();
            $table->boolean('auto_approved')->default(false);
            
            // Failure handling
            $table->string('failure_reason', 255)->nullable();
            $table->smallInteger('retry_count')->unsigned()->default(0);
            
            // Audit
            $table->text('admin_notes')->nullable();
            $table->json('metadata')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index(['merchant_id', 'status']);
            $table->index(['status', 'created_at']);
            $table->index(['invoice_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merchant_refunds');
    }
};
