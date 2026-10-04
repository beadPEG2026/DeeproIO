<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('wallet_adjustments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('currency_id')->nullable();
            $table->unsignedBigInteger('wallet_id')->nullable();
            $table->decimal('amount', 36, 18); // store negative values for deductions
            $table->text('note');
            $table->timestamps();

            $table->index('user_id');
            $table->index('currency_id');
            $table->index('wallet_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_adjustments');
    }
};
