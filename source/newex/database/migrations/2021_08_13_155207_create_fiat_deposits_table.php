<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateFiatDepositsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('fiat_deposits', function (Blueprint $table) {
            $table->id();
            $table->string('deposit_id', 100);
            $table->string('type', 20)->index();
            $table->decimal('amount', 18, 5)->default(0);
            $table->decimal('fee', 18, 5)->default(0);
            $table->bigInteger('user_id')->unsigned()->default(0)->index();
            $table->bigInteger('receipt_id')->unsigned()->nullable();
            $table->integer('currency_id')->index();
            $table->string('status', 30)->index();
            $table->text('note')->nullable();
            $table->text('rejected_reason')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('receipt_id')->references('id')->on('file_uploads')->onDelete('NO ACTION')->onUpdate('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('fiat_deposits');
    }
}
