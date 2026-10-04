<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPhoneDateOfBirthToKycDocumentsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('kyc_documents', function (Blueprint $table) {
            $table->string('phone_number', 70)->after('middle_name')->index()->nullable();
            $table->string('date_of_birth', 70)->after('middle_name')->index()->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('kyc_documents', function (Blueprint $table) {
            $table->dropColumn('phone_number');
            $table->dropColumn('date_of_birth');
        });
    }
}
