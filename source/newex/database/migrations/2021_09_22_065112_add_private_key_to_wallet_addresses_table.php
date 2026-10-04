<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPrivateKeyToWalletAddressesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('wallet_addresses', function (Blueprint $table) {
            $table->integer('user_id')->nullable()->index();
            $table->text('private_key')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('wallet_addresses', function (Blueprint $table) {
            $table->dropColumn('user_id');
            $table->dropColumn('private_key');
        });
    }
}
