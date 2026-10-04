<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { Schema::create('stock_display_settings',function(Blueprint $t){$t->string('symbol',20)->primary();$t->boolean('display_enabled')->default(true);$t->string('default_interval',5)->default('1h');$t->unsignedBigInteger('updated_by')->nullable();$t->timestamps();}); }
 public function down(): void { Schema::dropIfExists('stock_display_settings'); }
};
