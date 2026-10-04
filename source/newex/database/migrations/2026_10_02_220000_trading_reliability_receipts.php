<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        if (!Schema::hasTable('trading_request_receipts')) Schema::create('trading_request_receipts', function(Blueprint $t) {
            $t->bigIncrements('id'); $t->unsignedBigInteger('user_id'); $t->string('product',16); $t->string('client_key',128); $t->char('request_hash',64); $t->string('result_id',80)->nullable(); $t->timestamps();
            $t->unique(['user_id','product','client_key'],'trading_request_identity');
        });
        if (!Schema::hasTable('copy_trading_events')) Schema::create('copy_trading_events', function(Blueprint $t) {
            $t->bigIncrements('id'); $t->unsignedBigInteger('follow_id'); $t->string('source_contract_id',80); $t->string('phase',16); $t->string('result_id',80)->nullable(); $t->string('status',24); $t->timestamps();
            $t->unique(['follow_id','source_contract_id','phase'],'copy_trading_event_identity');
        });
        if (Schema::hasTable('funding_fee_distributions') && !Schema::hasColumn('funding_fee_distributions','period_key')) Schema::table('funding_fee_distributions', function(Blueprint $t) {
            $t->string('period_key',64)->nullable(); $t->decimal('theoretical_fee',36,18)->nullable(); $t->decimal('balance_delta',36,18)->nullable(); $t->decimal('shortfall',36,18)->nullable(); $t->string('price_source',160)->nullable();
            $t->unique(['futures_contract_id','period_key'],'funding_contract_period_identity');
        });
        if (Schema::hasTable('futures_contract') && !Schema::hasColumn('futures_contract','opening_price_source')) Schema::table('futures_contract',function(Blueprint $t) {
            $t->string('opening_price_source',160)->nullable(); $t->string('closing_price_source',160)->nullable();
        });
        if (!Schema::hasTable('option_settlement_reviews')) Schema::create('option_settlement_reviews', function(Blueprint $t) {
            $t->bigIncrements('id'); $t->unsignedBigInteger('option_id')->unique(); $t->string('status',24)->default('pending'); $t->string('reason',80); $t->timestamp('expires_at'); $t->json('evidence')->nullable(); $t->unsignedBigInteger('requested_by')->nullable(); $t->unsignedBigInteger('resolved_by')->nullable(); $t->text('resolution_note')->nullable(); $t->timestamp('resolved_at')->nullable(); $t->timestamps();
            $t->index(['status','created_at']);
        });
    }
    public function down(): void { throw new RuntimeException('Financial receipts are retained. Use a reviewed forward migration; old code can ignore the added columns/tables.'); }
};
