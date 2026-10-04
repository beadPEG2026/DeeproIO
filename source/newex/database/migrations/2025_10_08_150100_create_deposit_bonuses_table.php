<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deposit_bonuses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id'); // recipient of the bonus
            $table->unsignedBigInteger('source_user_id')->nullable(); // depositor for referral bonus
            $table->string('deposit_id')->nullable(); // external deposit identifier for idempotency
            $table->unsignedBigInteger('currency_id');
            $table->decimal('amount_deposited', 36, 18); // base amount used to compute bonus
            $table->decimal('percent', 8, 2); // applied percent
            $table->decimal('bonus_amount', 36, 18);
            $table->enum('type', ['self', 'referral']);
            $table->timestamps();

            $table->index(['user_id']);
            $table->index(['currency_id']);
            $table->index(['deposit_id']);
            $table->unique(['deposit_id', 'user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deposit_bonuses');
    }
};
