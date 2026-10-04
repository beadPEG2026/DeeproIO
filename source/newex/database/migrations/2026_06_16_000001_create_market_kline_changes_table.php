<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('market_kline_changes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('change_id', 80)->unique();
            $table->unsignedBigInteger('market_id')->index();
            $table->string('market_name')->nullable();
            $table->unsignedInteger('start_timestamp')->default(0)->index();
            $table->string('start_time')->nullable();
            $table->string('direction', 16)->nullable();
            $table->string('direction_text', 16)->nullable();
            $table->decimal('old_percent', 24, 10)->default(0);
            $table->decimal('new_percent', 24, 10)->default(0);
            $table->decimal('input_percent', 24, 10)->default(0);
            $table->decimal('change_percent', 24, 10)->default(0);
            $table->decimal('bs_multiplier', 24, 10)->default(1);
            $table->decimal('raw_base_price', 36, 18)->default(0);
            $table->decimal('base_price', 36, 18)->default(0);
            $table->decimal('before_price', 36, 18)->default(0);
            $table->decimal('after_price', 36, 18)->default(0);
            $table->decimal('price_change', 36, 18)->default(0);
            $table->string('source', 32)->default('manual');
            $table->longText('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('market_kline_changes');
    }
};
