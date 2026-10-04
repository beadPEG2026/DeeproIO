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
        // Main webhook queue table
        Schema::create('merchant_webhooks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('merchant_id')->index();
            $table->uuid('invoice_id')->index();

            // Event details
            $table->string('event_type', 50)->index(); // invoice.created, invoice.paid, etc.
            $table->string('priority', 10)->default('normal'); // critical, high, normal, low

            // Idempotency
            $table->string('idempotency_key', 100)->index();

            // Payload
            $table->json('payload');
            $table->string('payload_hash', 64)->nullable(); // SHA256 for deduplication

            // Delivery details
            $table->string('webhook_url', 500);
            $table->string('webhook_secret_version', 20)->nullable(); // Track which secret was used

            // Status
            $table->string('status', 20)->default('pending')->index(); // pending, processing, delivered, pending_retry, failed, skipped_duplicate

            // Retry tracking
            $table->smallInteger('attempt_count')->unsigned()->default(0);
            $table->smallInteger('max_attempts')->unsigned()->default(5);
            $table->timestamp('next_retry_at')->nullable()->index();
            $table->timestamp('last_attempt_at')->nullable();

            // Last attempt result
            $table->smallInteger('last_response_code')->unsigned()->nullable();
            $table->text('last_response_body')->nullable();
            $table->string('last_failure_reason', 500)->nullable();
            $table->integer('last_response_time_ms')->unsigned()->nullable();

            // Final timestamps
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            // Circuit breaker
            $table->boolean('circuit_breaker_active')->default(false);

            $table->timestamps();

            // Indexes
            $table->index(['merchant_id', 'status']);
            $table->index(['status', 'next_retry_at']);
            $table->index(['merchant_id', 'idempotency_key']);
            $table->index(['event_type', 'status']);
        });

        // Individual webhook delivery attempts
        Schema::create('merchant_webhook_attempts', function (Blueprint $table) {
            $table->id();
            $table->uuid('webhook_id')->index();

            $table->smallInteger('attempt_number')->unsigned();
            $table->string('webhook_url', 500);

            // Request details
            $table->json('request_headers')->nullable();
            $table->string('request_signature', 128)->nullable();

            // Response
            $table->smallInteger('response_code')->unsigned()->nullable();
            $table->text('response_body')->nullable();
            $table->json('response_headers')->nullable();
            $table->integer('response_time_ms')->unsigned()->nullable();

            // Result
            $table->string('status', 20); // success, failed, timeout, connection_error
            $table->string('error_message', 500)->nullable();
            $table->string('error_type', 50)->nullable(); // timeout, connection_refused, ssl_error, http_error

            $table->timestamp('attempted_at');

            $table->index(['webhook_id', 'attempt_number']);
        });

        // Track successful deliveries for idempotency
        Schema::create('merchant_webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->uuid('merchant_id')->index();
            $table->uuid('webhook_id')->index();
            $table->string('idempotency_key', 100);

            $table->string('status', 20); // delivered, failed
            $table->timestamp('delivered_at')->nullable();

            $table->timestamp('created_at');

            $table->unique(['merchant_id', 'idempotency_key'], 'mrch_unique_merchant_idempotency');
        });

        // Dead letter queue for failed webhooks requiring manual intervention
        Schema::create('merchant_webhook_dead_letters', function (Blueprint $table) {
            $table->id();
            $table->uuid('webhook_id')->index();
            $table->uuid('merchant_id')->index();
            $table->uuid('invoice_id')->index();

            // Original webhook data
            $table->string('event_type', 50);
            $table->string('priority', 10);
            $table->json('payload');

            // Failure details
            $table->smallInteger('total_attempts')->unsigned();
            $table->timestamp('first_attempt_at');
            $table->timestamp('last_attempt_at');
            $table->smallInteger('last_response_code')->unsigned()->nullable();
            $table->text('last_error')->nullable();

            // Resolution
            $table->string('status', 20)->default('pending_resolution'); // pending_resolution, resolved, ignored
            $table->string('resolution_action', 50)->nullable(); // manual_retry, merchant_notified, ignored
            $table->text('resolution_notes')->nullable();
            $table->bigInteger('resolved_by')->unsigned()->nullable();
            $table->timestamp('resolved_at')->nullable();

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
        Schema::dropIfExists('merchant_webhook_dead_letters');
        Schema::dropIfExists('merchant_webhook_deliveries');
        Schema::dropIfExists('merchant_webhook_attempts');
        Schema::dropIfExists('merchant_webhooks');
    }
};
