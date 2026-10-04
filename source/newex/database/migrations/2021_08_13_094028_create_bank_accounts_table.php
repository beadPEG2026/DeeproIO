<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBankAccountsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('reference_number', 255)->nullable();
            $table->string('name', 255)->nullable();
            $table->string('iban', 255)->nullable();
            $table->string('swift', 255)->nullable();
            $table->string('ifsc', 255)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('account_holder_name', 255)->nullable();
            $table->string('account_holder_address', 255)->nullable();
            $table->text('note')->nullable();
            $table->boolean('status')->default(true);
            $table->integer('country_id')->unsigned()->index();


            $table->foreign('country_id')->references('id')->on('countries')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('bank_accounts');
    }
}
