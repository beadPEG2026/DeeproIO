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
        Schema::create('merchant_payouts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('merchant_id')->index();
            
            // Payout details
            $table->decimal('amount_usd', 18, 2);
            $table->decimal('fee_usd', 18, 2)->default(0);
            $table->decimal('net_amount_usd', 18, 2);
            
            // Crypto payout details (if paying in crypto)
            $table->bigInteger('currency_id')->unsigned()->nullable();
            $table->bigInteger('network_id')->unsigned()->nullable();
            $table->decimal('amount_crypto', 36, 18)->nullable();
            $table->decimal('rate_usd', 24, 12)->nullable();
            
            // Destination
            $table->string('payout_address', 255);
            $table->string('payout_memo', 100)->nullable();
            
            // Status
            $table->string('status', 20)->default('pending')->index(); // pending, approved, processing, completed, rejected, cancelled
            $table->text('rejection_reason')->nullable();
            
            // Transaction
            $table->string('txn_hash', 128)->nullable();
            $table->string('explorer_url', 500)->nullable();
            
            // Processing info
            $table->bigInteger('requested_by')->unsigned()->nullable();
            $table->bigInteger('processed_by')->unsigned()->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            
            // Notes
            $table->text('merchant_notes')->nullable();
            $table->text('admin_notes')->nullable();
            
            // Reference
            $table->string('reference', 50)->unique();
            
            $table->timestamps();
            
            // Indexes
            $table->index(['merchant_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merchant_payouts');
    }
};
