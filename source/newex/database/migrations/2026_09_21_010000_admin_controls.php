<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('options', function (Blueprint $t) {
            $t->string('funding_domain', 16)->nullable();
            $t->unsignedBigInteger('funding_wallet_id')->nullable();
            $t->string('settlement_source', 100)->nullable();
            $t->string('opening_source', 100)->nullable();
        });
        Schema::create('admin_fund_transfers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('idempotency_key')->unique();
            $t->string('funding_domain', 16)->default('real');
            $t->unsignedBigInteger('proposed_by');
            $t->unsignedBigInteger('reviewed_by')->nullable();
            $t->unsignedBigInteger('source_wallet_id');
            $t->unsignedBigInteger('target_wallet_id');
            $t->unsignedBigInteger('currency_id');
            $t->string('source_field', 40);
            $t->string('target_field', 40);
            $t->decimal('amount', 36, 18);
            $t->string('status', 20)->default('pending');
            $t->string('reference', 200);
            $t->text('reason');
            $t->text('review_reason')->nullable();
            $t->json('ledger')->nullable();
            $t->timestamps();
        });
        Schema::create('admin_chain_receipts', function (Blueprint $t) {
            $t->string('claim_key', 64)->primary();
            $t->unsignedBigInteger('withdrawal_id')->unique();
            $t->unsignedBigInteger('verified_by');
            $t->json('receipt');
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        // Financial evidence is retained on a code rollback. Never drop populated journals.
        throw new RuntimeException('Financial evidence migrations require a reviewed forward migration.');
    }
};
