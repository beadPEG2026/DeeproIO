<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('copy_trading_copied_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('copy_trading_follow_id')->nullable()->index();
            $table->unsignedBigInteger('source_user_id')->index();
            $table->unsignedBigInteger('follower_user_id')->index();
            $table->string('source_contract_id', 80)->index();
            $table->string('follower_contract_id', 80)->nullable()->index();
            $table->string('status', 24)->default('success')->index();
            $table->text('error_message')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->foreign('copy_trading_follow_id', 'ctco_follow_fk')
                ->references('id')
                ->on('copy_trading_follows')
                ->nullOnDelete();
            $table->foreign('source_user_id', 'ctco_source_user_fk')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
            $table->foreign('follower_user_id', 'ctco_follower_user_fk')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('copy_trading_copied_orders');
    }
};
