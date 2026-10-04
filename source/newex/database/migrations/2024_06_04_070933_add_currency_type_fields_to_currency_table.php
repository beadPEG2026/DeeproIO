<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('markets', function (Blueprint $table) {
            $table->boolean('is_meme')->default(false);
            $table->boolean('is_layer_one')->default(false);
            $table->boolean('is_layer_two')->default(false);
            $table->boolean('is_innovation')->default(false);
            $table->boolean('is_ai')->default(false);
            $table->boolean('is_defi')->default(false);
            $table->boolean('is_gamefi')->default(false);
            $table->boolean('is_pow')->default(false);
            $table->boolean('is_fan_tokens')->default(false);
            $table->boolean('is_nft')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('markets', function (Blueprint $table) {
            $table->dropColumn('is_meme');
            $table->dropColumn('is_layer_one');
            $table->dropColumn('is_layer_two');
            $table->dropColumn('is_innovation');
            $table->dropColumn('is_ai');
            $table->dropColumn('is_defi');
            $table->dropColumn('is_gamefi');
            $table->dropColumn('is_pow');
            $table->dropColumn('is_fan_tokens');
            $table->dropColumn('is_nft');
        });
    }
};
