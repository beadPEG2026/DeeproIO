<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('copy_trading_follows', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('copy_trading_trader_id');
            $table->unsignedBigInteger('trader_user_id')->index();
            $table->unsignedBigInteger('follower_user_id')->index();
            $table->boolean('is_enabled')->default(true)->index();
            $table->timestamps();

            $table->unique(['copy_trading_trader_id', 'follower_user_id'], 'ctf_trader_follower_unique');
            $table->foreign('copy_trading_trader_id', 'ctf_trader_fk')
                ->references('id')
                ->on('copy_trading_traders')
                ->cascadeOnDelete();
            $table->foreign('trader_user_id', 'ctf_trader_user_fk')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
            $table->foreign('follower_user_id', 'ctf_follower_user_fk')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('copy_trading_follows');
    }
};
