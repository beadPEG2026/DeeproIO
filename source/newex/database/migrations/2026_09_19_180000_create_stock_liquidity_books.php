<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
return new class extends Migration {
    public function up(): void {
        Schema::create('stock_token_catalog', function (Blueprint $t) {
            $t->string('symbol')->primary(); $t->unsignedInteger('chain_id');
            $t->string('contract'); $t->json('asset'); $t->timestamps();
            $t->unique(['chain_id','contract']);
        });
        foreach (config('stock-tokens.assets', []) as $a) DB::table('stock_token_catalog')->insert([
            'symbol'=>$a['symbol'], 'chain_id'=>$a['chainId'], 'contract'=>strtolower($a['contract']),
            'asset'=>json_encode($a), 'created_at'=>now(), 'updated_at'=>now(),
        ]);
        Schema::create('stock_liquidity_books', function (Blueprint $t) {
            $t->unsignedBigInteger('market_id')->primary();
            $t->string('snapshot_id'); $t->json('book'); $t->json('remaining');
            $t->timestampTz('expires_at'); $t->timestamps();
        });
        Schema::create('stock_liquidity_fills', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->unsignedBigInteger('market_id');
            $t->uuid('order_id')->index(); $t->string('snapshot_id'); $t->string('side');
            $t->decimal('price',36,18); $t->decimal('quantity',36,18);
            $t->string('execution_mode')->default('platform_internal'); $t->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('stock_liquidity_fills'); Schema::dropIfExists('stock_liquidity_books'); Schema::dropIfExists('stock_token_catalog');
    }
};
