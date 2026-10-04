<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('wallet_request_receipts', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('user_id'); $t->string('operation', 80);
            $t->string('request_key', 128); $t->string('payload_hash', 64);
            $t->unsignedSmallInteger('http_status'); $t->text('response'); $t->timestamp('created_at');
            $t->unique(['user_id','operation','request_key'], 'wallet_request_identity');
        });
        Schema::table('deposits', function (Blueprint $t) {
            $t->timestamp('sweep_attempted_at')->nullable()->index();
            $t->timestamp('sweep_retry_at')->nullable(); $t->string('sweep_error', 100)->nullable();
        });
        Schema::table('custody_transfers', fn (Blueprint $t) => $t->timestamp('last_attempt_at')->nullable()->index());
        Schema::table('chain_deposit_receipts', fn (Blueprint $t) => $t->timestamp('rechecked_at')->nullable()->index());
        Schema::create('deposit_review_events', function (Blueprint $t) {
            $t->id(); $t->string('event_key', 180)->unique(); $t->unsignedBigInteger('channel_id')->nullable();
            $t->unsignedBigInteger('wallet_address_id')->nullable(); $t->unsignedBigInteger('deposit_id')->nullable();
            $t->string('chain', 20); $t->string('txn', 160); $t->string('event_index', 50);
            $t->string('reason', 100); $t->string('status', 30)->default('open')->index();
            $t->json('evidence'); $t->unsignedInteger('attempts')->default(1); $t->timestamps();
            $t->timestamp('resolved_at')->nullable();
        });
    }
    public function down(): void {
        // Operational receipts must survive a code rollback after customer activity.
        throw new RuntimeException('Forward-only financial evidence migration; roll back code without dropping receipts.');
    }
};
