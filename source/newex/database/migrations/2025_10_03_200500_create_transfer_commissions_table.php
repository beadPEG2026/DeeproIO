<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $currencyLength = 36;
        $currencyDecimals = 18;

        Schema::create('transfer_commissions', function (Blueprint $table) use ($currencyLength, $currencyDecimals) {
            $table->id();
            $table->bigInteger('user_id')->unsigned()->index();
            $table->bigInteger('wallet_id')->unsigned()->index();
            $table->integer('currency_id')->unsigned()->index();
            $table->enum('direction', ['to_trade', 'to_funding'])->index();
            $table->decimal('amount', $currencyLength, $currencyDecimals)->default(0); // original transfer amount
            $table->decimal('percent', 8, 4)->default(0);
            $table->decimal('commission_amount', $currencyLength, $currencyDecimals)->default(0);
            $table->decimal('credited_amount', $currencyLength, $currencyDecimals)->default(0);
            $table->timestamps();

            $table->foreign('currency_id')->references('id')->on('currencies')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('wallet_id')->references('id')->on('wallets')->onDelete('NO ACTION')->onUpdate('NO ACTION');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_commissions');
    }
};
