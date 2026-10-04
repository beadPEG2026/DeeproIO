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
        Schema::create('merchants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->bigInteger('user_id')->unsigned()->index();

            // Business information
            $table->string('business_name', 255);
            $table->string('business_type', 50)->nullable(); // individual, company, etc.
            $table->string('business_email', 255)->index('mcnts_business_email_index');
            $table->string('business_website', 255)->nullable();
            $table->text('business_description')->nullable();

            // Contact information
            $table->string('contact_name', 255)->nullable();
            $table->string('contact_phone', 50)->nullable();
            $table->string('country_code', 3)->nullable();

            // Status and verification
            $table->string('status', 20)->default('pending')->index(); // pending, active, suspended, terminated
            $table->string('verification_status', 20)->default('unverified'); // unverified, pending, verified, rejected
            $table->timestamp('verified_at')->nullable();
            $table->bigInteger('verified_by')->unsigned()->nullable();

            // Settings
            $table->string('default_webhook_url', 500)->nullable();
            $table->string('webhook_secret', 64)->nullable();
            $table->json('webhook_events')->nullable(); // Which events to send
            $table->json('ip_whitelist')->nullable(); // Allowed IPs for API access
            $table->string('settlement_currency', 10)->default('USDT'); // Default settlement currency
            $table->string('settlement_address', 255)->nullable();
            $table->integer('settlement_network_id')->unsigned()->nullable();

            // Limits
            $table->decimal('daily_volume_limit_usd', 18, 2)->default(10000.00);
            $table->decimal('monthly_volume_limit_usd', 18, 2)->default(100000.00);
            $table->decimal('single_invoice_limit_usd', 18, 2)->default(10000.00);
            $table->decimal('min_invoice_amount_usd', 18, 2)->default(1.00);

            // Fees
            $table->decimal('fee_percent', 5, 4)->default(1.0000); // Processing fee percentage
            $table->decimal('fee_fixed_usd', 18, 2)->default(0.00); // Fixed fee per transaction

            // Statistics (denormalized for performance)
            $table->bigInteger('total_invoices')->unsigned()->default(0);
            $table->bigInteger('paid_invoices')->unsigned()->default(0);
            $table->decimal('total_volume_usd', 24, 2)->default(0.00);
            $table->decimal('total_fees_usd', 24, 2)->default(0.00);
            $table->timestamp('last_invoice_at')->nullable();

            // Risk management
            $table->integer('risk_score')->unsigned()->default(0);
            $table->text('risk_notes')->nullable();
            $table->boolean('manual_review_required')->default(false);

            // Metadata
            $table->json('metadata')->nullable();
            $table->text('admin_notes')->nullable();

            $table->softDeletes();
            $table->timestamps();

            // Indexes
            $table->index(['status', 'verification_status']);
            $table->index('business_email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merchants');
    }
};
