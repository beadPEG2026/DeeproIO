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
        // Invoice lifecycle timeline for audit and debugging
        Schema::create('merchant_invoice_timeline', function (Blueprint $table) {
            $table->id();
            $table->uuid('invoice_id')->index();
            $table->uuid('merchant_id')->index();

            // Event details
            $table->string('event_type', 50); // created, currency_selected, payment_detected, payment_confirmed, paid, expired, etc.
            $table->string('event_source', 30)->nullable(); // api, widget, system, admin, blockchain

            // State change
            $table->string('old_status', 30)->nullable();
            $table->string('new_status', 30)->nullable();

            // Event data
            $table->json('event_data')->nullable();

            // Actor
            $table->string('actor_type', 20)->nullable(); // system, merchant, buyer, admin
            $table->bigInteger('actor_id')->unsigned()->nullable();
            $table->string('actor_ip', 45)->nullable();

            // Timing
            $table->timestamp('occurred_at');
            $table->integer('duration_from_previous_ms')->unsigned()->nullable();

            $table->index(['invoice_id', 'occurred_at']);
            $table->index(['event_type', 'occurred_at']);
        });

        // Rate limiting tracking
        Schema::create('merchant_rate_limits', function (Blueprint $table) {
            $table->id();
            $table->uuid('merchant_id')->index();
            $table->string('api_key_id', 36)->nullable()->index();

            $table->string('limit_type', 30); // invoice_creation, api_request, webhook_test
            $table->string('period', 10); // minute, hour, day
            $table->integer('count')->unsigned()->default(0);
            $table->integer('limit')->unsigned();

            $table->timestamp('period_start');
            $table->timestamp('period_end');

            $table->index(['merchant_id', 'limit_type', 'period_end']);
        });

        // Nonce tracking for replay protection
        Schema::create('merchant_api_nonces', function (Blueprint $table) {
            $table->string('nonce', 64)->primary();
            $table->uuid('merchant_id')->index();
            $table->string('api_key_id', 36)->index();

            $table->timestamp('used_at');
            $table->timestamp('expires_at')->index('mrch_api_nonces_expires_at');

            // Cleanup old nonces automatically
            $table->index(['expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merchant_api_nonces');
        Schema::dropIfExists('merchant_rate_limits');
        Schema::dropIfExists('merchant_invoice_timeline');
    }
};
