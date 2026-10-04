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
        Schema::create('merchant_deposit_addresses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('merchant_id')->index();
            $table->uuid('invoice_id')->nullable()->unique(); // One address per invoice
            $table->bigInteger('currency_id')->unsigned()->index();

            // Address details
            $table->string('address', 255)->index();
            $table->string('memo', 100)->nullable();
            $table->string('derivation_path', 100)->nullable();
            $table->integer('address_index')->unsigned()->nullable();

            // Status
            $table->string('status', 20)->default('available')->index(); // available, assigned, used, expired, compromised

            // Usage tracking
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('first_payment_at')->nullable();
            $table->timestamp('last_payment_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->text('token_account')->nullable();

            // Security
            $table->boolean('is_sweep_required')->default(false);
            $table->timestamp('swept_at')->nullable();
            $table->string('sweep_txn_hash', 128)->nullable();

            // Audit
            $table->text('notes')->nullable();

            $table->timestamps();

            // Indexes
            $table->index(['currency_id', 'status']);
            $table->index(['merchant_id', 'status']);
            $table->unique(['currency_id', 'address'], 'unique_asset_address');
        });

        // Address audit log for security tracking
        Schema::create('merchant_address_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('address_id')->index();
            $table->uuid('merchant_id')->index();
            $table->uuid('invoice_id')->nullable()->index();

            $table->string('event_type', 50); // created, assigned, payment_received, released, swept, compromised
            $table->string('old_status', 20)->nullable();
            $table->string('new_status', 20)->nullable();

            $table->json('event_data')->nullable();
            $table->string('triggered_by', 50)->nullable(); // system, admin, sweep_job, etc.
            $table->bigInteger('triggered_by_user_id')->unsigned()->nullable();

            $table->timestamp('created_at');

            $table->index(['address_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merchant_address_audit_logs');
        Schema::dropIfExists('merchant_deposit_addresses');
    }
};
