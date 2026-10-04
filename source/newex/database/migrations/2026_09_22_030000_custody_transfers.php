<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Schema,DB};

return new class extends Migration {
    public function up(): void {
        Schema::create('custody_networks', function(Blueprint $t) {
            $t->string('chain')->primary(); $t->boolean('enabled')->default(false);
            $t->boolean('auto_sweep')->default(false); $t->decimal('max_fee',36,18)->default(0);
            $t->decimal('daily_gas_limit',36,18)->default(0); $t->unsignedInteger('confirmations')->default(1);
            $t->unsignedBigInteger('updated_by')->nullable(); $t->timestamps();
        });
        foreach (['ethereum'=>12,'bnb'=>15,'polygon'=>128,'tron'=>20,'solana'=>1,'ton'=>1] as $chain=>$n) DB::table('custody_networks')->insert(['chain'=>$chain,'confirmations'=>$n,'created_at'=>now(),'updated_at'=>now()]);
        Schema::table('cold_storage', function(Blueprint $t) {
            $t->decimal('hot_reserve',36,18)->default(0); $t->decimal('daily_limit',36,18)->default(0);
            $t->unsignedBigInteger('updated_by')->nullable(); $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();
        });
        Schema::create('custody_transfers', function(Blueprint $t) {
            $t->bigIncrements('id'); $t->string('key',128)->unique(); $t->string('purpose',24);
            $t->string('chain',24)->index(); $t->unsignedBigInteger('currency_id'); $t->unsignedBigInteger('network_id');
            $t->unsignedBigInteger('withdrawal_id')->nullable()->unique(); $t->unsignedBigInteger('deposit_id')->nullable()->unique();
            $t->unsignedBigInteger('rule_id')->nullable(); $t->unsignedBigInteger('wallet_address_id')->nullable();
            $t->unsignedBigInteger('parent_id')->nullable();
            $t->text('sender'); $t->text('destination'); $t->string('contract',128)->nullable();
            $t->decimal('amount',36,18); $t->decimal('sent_amount',36,18)->nullable();
            $t->decimal('max_fee',36,18); $t->decimal('fee',36,18)->nullable();
            $t->unsignedInteger('confirmations'); $t->string('status',24)->default('awaiting_approval')->index();
            $t->unsignedBigInteger('requested_by')->nullable(); $t->unsignedBigInteger('approved_by')->nullable();
            $t->text('signed_payload')->nullable(); $t->string('txn',160)->nullable(); $t->json('prepared')->nullable();
            $t->json('receipt')->nullable(); $t->string('last_error',120)->nullable();
            $t->timestamp('broadcast_at')->nullable(); $t->timestamp('completed_at')->nullable(); $t->timestamps();
            $t->index(['chain','status']);
        });
        Schema::create('custody_transfer_attempts', function(Blueprint $t) {
            $t->bigIncrements('id');$t->unsignedBigInteger('transfer_id')->index();$t->text('signed_payload');$t->string('txn',160);$t->json('prepared');$t->json('receipt');$t->timestamp('created_at');
        });
        Schema::create('custody_audits', function(Blueprint $t) {
            $t->bigIncrements('id'); $t->unsignedBigInteger('actor_id')->nullable(); $t->string('action',64);
            $t->unsignedBigInteger('transfer_id')->nullable(); $t->json('detail'); $t->timestamp('created_at');
        });
        Schema::create('fiat_deposit_instructions', function(Blueprint $t) { $t->unsignedBigInteger('id')->primary();$t->text('address')->default('');$t->boolean('status')->default(false);$t->unsignedBigInteger('updated_by')->nullable();$t->timestamps(); });
        $bank=DB::table('cold_storage')->where('id',1)->where('currency_id',0)->where('network_id',0)->first();
        DB::table('fiat_deposit_instructions')->insert(['id'=>1,'address'=>$bank->address??'','status'=>(bool)($bank->status??false),'created_at'=>now(),'updated_at'=>now()]);
        Schema::table('withdrawals', function(Blueprint $t) { $t->string('fund_origin',24)->default('user'); });
        DB::table('withdrawals')->where('source_id','system')->update(['fund_origin'=>'treasury']);
        DB::table('withdrawals')->whereIn('id',DB::table('cold_storage')->whereNotNull('cold_storage_transaction_id')->select('cold_storage_transaction_id'))->update(['fund_origin'=>'treasury']);
    }
    public function down(): void {
        throw new RuntimeException('Financial history is retained. Roll back application code, not custody records.');
    }
};
