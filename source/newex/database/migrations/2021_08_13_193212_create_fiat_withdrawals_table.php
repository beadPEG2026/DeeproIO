<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateFiatWithdrawalsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('fiat_withdrawals', function (Blueprint $table) {

            $table->id();
            $table->string('withdrawal_id', 100);

            $table->decimal('amount', 18, 5)->default(0);
            $table->decimal('fee', 18, 5)->default(0);
            $table->bigInteger('user_id')->unsigned()->default(0)->index();
            $table->integer('currency_id')->index();
            $table->string('status', 30)->index();
            $table->text('note', 500)->nullable();
            $table->text('rejected_reason')->nullable();

            // Bank Fields
            $table->string('name', 255)->nullable();
            $table->string('iban', 255)->nullable();
            $table->string('swift', 255)->nullable();
            $table->string('ifsc', 255)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('account_holder_name', 255)->nullable();
            $table->string('account_holder_address', 255)->nullable();
            $table->integer('country_id')->unsigned()->index()->nullable();

            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();

            $table->foreign('country_id')->references('id')->on('countries')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('NO ACTION')->onUpdate('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('fiat_withdrawals');
    }
}
