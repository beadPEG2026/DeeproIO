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
        $currencyLength = 36;
        $currencyDecimals = 18;

        Schema::create('funding_fee_distributions', function (Blueprint $table) use ($currencyLength, $currencyDecimals) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->uuid('futures_contract_id'); // UUID
            $table->unsignedBigInteger('market_id');
            $table->decimal('funding_fee_amount', $currencyLength, $currencyDecimals); // Can be positive or negative
            $table->decimal('position_size', $currencyLength, $currencyDecimals); // Balance at time of distribution
            $table->decimal('funding_rate', 10, 8); // Rate used (e.g., 0.01 for 1%)
            $table->boolean('is_long')->default(true);
            $table->timestamp('distributed_at');
            $table->timestamps();

            $table->index('user_id');
            $table->index('futures_contract_id');
            $table->index('market_id');
            $table->index('distributed_at');
            $table->index(['user_id', 'distributed_at']);

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('futures_contract_id')->references('id')->on('futures_contract')->onDelete('cascade');
            $table->foreign('market_id')->references('id')->on('markets')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('funding_fee_distributions');
    }
};
