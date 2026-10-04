<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  foreach(['orders','order_histories'] as $table) if(Schema::hasTable($table)&&!Schema::hasColumn($table,'settlement_domain')) Schema::table($table,fn(Blueprint $t)=>$t->string('settlement_domain',12)->nullable());
  Schema::create('market_execution_policies',function(Blueprint $t){$t->unsignedBigInteger('market_id')->primary();$t->string('mode',24)->default('internal');$t->unsignedBigInteger('maker_user_id')->nullable();$t->decimal('max_quote_per_fill',36,18)->default(1000);$t->unsignedBigInteger('updated_by')->nullable();$t->timestamps();});
  Schema::create('funded_liquidity_fills',function(Blueprint $t){$t->id();$t->unsignedBigInteger('market_id');$t->string('taker_order_id',64);$t->string('maker_order_id',64)->unique();$t->unsignedBigInteger('maker_user_id');$t->string('domain',12);$t->string('source',40);$t->decimal('price',36,18);$t->decimal('quantity',36,18);$t->decimal('reserved',36,18);$t->timestamp('created_at');});
  Schema::create('market_execution_audits',function(Blueprint $t){$t->id();$t->unsignedBigInteger('market_id');$t->unsignedBigInteger('actor_id');$t->json('before')->nullable();$t->json('after');$t->timestamp('created_at');});
 }
 public function down():void {throw new RuntimeException('Preserve execution records; use a forward migration.');}
};
