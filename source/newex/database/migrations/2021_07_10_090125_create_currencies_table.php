<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCurrenciesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $currencyLength = 36;
        $currencyDecimals = 18;

        Schema::create('currencies', function (Blueprint $table) use ($currencyDecimals, $currencyLength) {
            // Fields
            $table->increments('id');
            $table->string('name', 100)->index();
            $table->string('symbol', 100)->index()->unique();
            $table->string('alt_symbol', 100)->index()->unique()->nullable();
            $table->string('type', 20)->index()->nullable();
            $table->integer('decimals')->default(8);
            $table->bigInteger('file_id')->unsigned()->nullable();
            $table->string('contract', 50)->nullable()->index();

            // Currency status
            $table->boolean('status')->default(true);
            $table->boolean('deposit_status')->default(true);
            $table->boolean('withdraw_status')->default(true);
            $table->boolean('is_stable')->default(false)->nullable();

            // Fee & Rules
            $table->decimal('deposit_fee', $currencyLength, $currencyDecimals)->default(0);
            $table->decimal('withdraw_fee', $currencyLength, $currencyDecimals)->default(0);
            $table->decimal('min_deposit', $currencyLength, $currencyDecimals)->default(0);
            $table->decimal('max_deposit', $currencyLength, $currencyDecimals)->default(0);
            $table->decimal('min_withdraw', $currencyLength, $currencyDecimals)->default(0);
            $table->decimal('max_withdraw', $currencyLength, $currencyDecimals)->default(0);
            $table->integer('min_deposit_confirmation')->default(0)->index();

            $table->boolean('has_payment_id')->default(false);
            $table->decimal('wallet_balance', $currencyLength, $currencyDecimals)->default(0);
            $table->timestamp('wallet_balance_updated_at')->nullable();
            $table->text('txn_explorer')->nullable();

            // Soft deletes and timestamps
            $table->softDeletes();
            $table->timestamps();

            $table->foreign('file_id')->references('id')->on('file_uploads')->onDelete('NO ACTION')->onUpdate('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('currencies');
    }
}
