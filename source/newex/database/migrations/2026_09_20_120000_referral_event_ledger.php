<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration {
    public function up(): void {
        Schema::create('exchange_referral_events', function (Blueprint $t) {
            $t->string('id', 128)->primary();
            $t->string('business', 24); $t->string('source_id', 64);
            $t->unsignedBigInteger('source_user_id'); $t->unsignedInteger('currency_id');
            $t->decimal('fee', 36, 18); $t->decimal('reward_total', 36, 18)->default(0);
            $t->string('balance_domain', 12); $t->string('rule_version', 40);
            $t->json('rules'); $t->json('ancestry'); $t->timestamp('created_at');
        });
        Schema::table('referral_transactions', function (Blueprint $t) {
            $t->string('event_key', 128)->nullable(); $t->unsignedSmallInteger('reward_level')->nullable();
            $t->string('balance_domain', 12)->nullable();
            $t->string('credit_status', 24)->default('review')->index();
            $t->timestamp('credited_at')->nullable(); $t->string('last_error', 100)->nullable();
            $t->unique(['event_key','user_id','reward_level'], 'referral_event_recipient_level_unique');
        });
        DB::table('referral_transactions')->where('is_credited', true)->update(['credit_status'=>'legacy_credited']);
        DB::table('referral_transactions')->where('is_credited', false)->update(['last_error'=>'legacy_source_review']);
        Schema::create('exchange_referral_receipts', function (Blueprint $t) {
            $t->unsignedBigInteger('referral_id')->primary(); $t->unsignedBigInteger('wallet_id');
            $t->string('balance_field', 40); $t->decimal('amount', 36, 18);
            $t->decimal('balance_before', 36, 18); $t->decimal('balance_after', 36, 18);
            $t->timestamp('created_at');
        });
    }
    public function down(): void {
        throw new RuntimeException('Referral ledger contains financial evidence. Roll back application code without deleting the ledger.');
    }
};
