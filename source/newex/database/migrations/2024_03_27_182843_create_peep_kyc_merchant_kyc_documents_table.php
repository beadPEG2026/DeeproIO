<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('peer_merchant_documents', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id')->unsigned()->default(0)->index();
            $table->string('address', 255)->index();
            $table->string('postal_code', 70)->index();
            $table->string('city', 70)->nullable();
            $table->string('state', 70)->nullable();
            $table->integer('country_id')->nullable();
            $table->string('document_type', 25)->index();
            $table->bigInteger('file_id')->unsigned();
            $table->string('status', 15)->index()->nullable();
            $table->text('rejected_reason')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('country_id')->references('id')->on('countries')->onDelete('NO ACTION')->onUpdate('NO ACTION');
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
        Schema::dropIfExists('peer_merchant_documents');
    }
};
