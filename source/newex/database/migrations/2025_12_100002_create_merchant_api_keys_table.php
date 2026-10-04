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
        Schema::create('merchant_api_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('merchant_id')->index();
            
            // Key details
            $table->string('name', 100); // Human-readable name
            $table->string('key_prefix', 20)->index(); // e.g., pk_live_, pk_test_
            $table->string('public_key', 64)->unique(); // The visible API key
            $table->string('secret_key_hash', 255); // Hashed secret key
            $table->string('secret_key_last4', 4); // Last 4 chars for identification
            
            // Environment and permissions
            $table->string('environment', 10)->default('live'); // live, sandbox
            $table->json('permissions')->nullable(); // Array of allowed actions
            $table->json('ip_whitelist')->nullable(); // IP restrictions for this key
            $table->json('allowed_origins')->nullable(); // CORS origins for widget
            
            // Rate limiting
            $table->integer('rate_limit_per_minute')->unsigned()->default(60);
            $table->integer('rate_limit_per_hour')->unsigned()->default(1000);
            
            // Status
            $table->boolean('is_active')->default(true);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            
            // Audit
            $table->bigInteger('created_by')->unsigned()->nullable();
            $table->bigInteger('revoked_by')->unsigned()->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revocation_reason', 255)->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index(['merchant_id', 'is_active']);
            $table->index(['environment', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merchant_api_keys');
    }
};
