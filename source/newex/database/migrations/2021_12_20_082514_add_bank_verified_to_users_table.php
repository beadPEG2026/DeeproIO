<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddBankVerifiedToUsersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->tinyInteger('bank_verified')->default(0)->after('kyc_verified_at')->index();
            $table->string('bank_verified_uuid', 150)->nullable()->after('kyc_verified_at')->index();
            $table->text('bank_verified_kyc', 150)->nullable()->after('current_team_id');
            $table->text('bank_verified_private_key', 150)->nullable()->after('current_team_id');
            $table->text('bank_verified_public_key', 150)->nullable()->after('current_team_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('bank_verified');
            $table->dropColumn('bank_verified_uuid');
            $table->dropColumn('bank_verified_kyc');
            $table->dropColumn('bank_verified_private_key');
            $table->dropColumn('bank_verified_public_key');
        });
    }
}
