<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateKycDocumentsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('kyc_documents', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id')->unsigned()->default(0)->index();
            $table->string('first_name', 70)->index();
            $table->string('last_name', 70)->index();
            $table->string('middle_name', 70)->nullable();
            $table->integer('country_id')->unsigned()->index();
            $table->string('document_type', 15)->index();
            $table->bigInteger('selfie_id')->unsigned();
            $table->string('status', 15)->index()->nullable();
            $table->text('rejected_reason')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('country_id')->references('id')->on('countries')->onDelete('NO ACTION')->onUpdate('NO ACTION');
            $table->foreign('selfie_id')->references('id')->on('file_uploads')->onDelete('NO ACTION')->onUpdate('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('kyc_documents');
    }
}
