<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};

return new class extends Migration {
    public function up(): void
    {
        // A treasury account, never a users/wallets balance or a token issuance.
        Schema::create('platform_credit_pools', function (Blueprint $t) {
            $t->unsignedBigInteger('id')->primary();
            $t->string('name');
            $t->decimal('credit_limit',36,18)->default(5000000);
            $t->decimal('quote_position',36,18)->default(0);
            $t->decimal('realized_pnl',36,18)->default(0);
            $t->boolean('enabled')->default(false);
            $t->boolean('default_for_usdt')->default(false);
            $t->timestamps();
        });
        DB::table('platform_credit_pools')->insert(['id'=>1,'name'=>'DEEPRO Platform Credit Pool','credit_limit'=>'5000000','created_at'=>now(),'updated_at'=>now()]);
        Schema::create('platform_credit_positions', function (Blueprint $t) {
            $t->id();$t->unsignedBigInteger('pool_id');$t->unsignedBigInteger('currency_id');
            $t->unsignedBigInteger('market_id');
            $t->decimal('quantity',36,18)->default(0);
            $t->decimal('average_price',36,18)->default(0);
            $t->decimal('risk_price',36,18)->default(0);
            $t->timestamps();$t->unique(['pool_id','currency_id']);
            $t->foreign('pool_id')->references('id')->on('platform_credit_pools');
        });
        foreach (['orders','order_histories','transactions'] as $table) {
            Schema::table($table,function (Blueprint $t) {
                $t->unsignedBigInteger('platform_pool_id')->nullable();
                $t->foreign('platform_pool_id')->references('id')->on('platform_credit_pools');
            });
            if ($table!=='transactions') DB::statement("ALTER TABLE {$table} ALTER COLUMN user_id DROP NOT NULL");
            // Historical anonymous transactions remain readable. New pool orders have no user identity.
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_platform_identity CHECK (platform_pool_id IS NULL OR user_id IS NULL)");
        }
        Schema::create('platform_credit_fills',function (Blueprint $t) {
            $t->id();$t->unsignedBigInteger('pool_id');$t->unsignedBigInteger('market_id');
            $t->string('taker_order_id',64);$t->string('maker_order_id',64)->unique();
            $t->unsignedBigInteger('user_id');$t->string('maker_side',8);
            $t->string('status',16)->default('reserved');
            $t->decimal('price',36,18);$t->decimal('quantity',36,18);
            $t->decimal('credit_after',36,18)->nullable();$t->decimal('realized_pnl',36,18)->default(0);
            $t->timestamps();
            $t->foreign('pool_id')->references('id')->on('platform_credit_pools');
        });
        Schema::create('platform_credit_entries',function (Blueprint $t) {
            $t->id();$t->unsignedBigInteger('fill_id');$t->unsignedBigInteger('currency_id');$t->unsignedBigInteger('user_wallet_id');
            $t->decimal('platform_delta',36,18);$t->decimal('user_delta',36,18);$t->decimal('fee_delta',36,18);
            $t->timestamp('created_at');$t->unique(['fill_id','currency_id']);
            $t->foreign('fill_id')->references('id')->on('platform_credit_fills');
        });
        DB::statement('ALTER TABLE platform_credit_entries ADD CONSTRAINT platform_credit_balanced CHECK (platform_delta + user_delta + fee_delta = 0)');
        DB::statement('ALTER TABLE platform_credit_pools ADD CONSTRAINT platform_credit_positive_limit CHECK (credit_limit > 0)');
        Schema::create('platform_credit_audits',function (Blueprint $t) {
            $t->id();$t->unsignedBigInteger('actor_id');$t->string('reason',500);$t->json('before');$t->json('after');$t->timestamp('created_at');
        });
        // Ordinary coin snapshots need durable consumption, just like stock-token snapshots.
        Schema::create('platform_reference_consumption',function (Blueprint $t) {
            $t->id();$t->unsignedBigInteger('market_id');$t->string('snapshot',64);$t->string('side',8);$t->decimal('price',36,18);$t->decimal('quantity',36,18);$t->timestamp('updated_at');
            $t->unique(['market_id','snapshot','side','price'],'platform_reference_unique');
        });
    }
    public function down(): void {throw new RuntimeException('Preserve credit obligations and journals; use a forward migration.');}
};
