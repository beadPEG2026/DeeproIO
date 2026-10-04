<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddBankAccountStatusFieldInCurrenciesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('currencies', function (Blueprint $table) {
            $table->boolean('bank_status')->nullable();
            $table->boolean('cc_status')->nullable();
            $table->decimal('cc_exchange_rate', 15, 3)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('currencies', function (Blueprint $table) {
            $table->dropColumn('bank_status');
            $table->dropColumn('cc_status');
            $table->dropColumn('cc_exchange_rate');
        });
    }
}
