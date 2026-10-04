<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUsersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('file_uploads', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('type')->nullable();
            $table->string('path')->nullable();
            $table->timestamps();
        });

        Schema::create('languages', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('slug', 10)->unique();
            $table->boolean('status')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->tinyInteger('active')->default(0);
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('kyc_verified_at')->nullable();
            $table->timestamp('merchant_verified_at')->nullable();
            $table->string('password');
            $table->boolean('deactivated')->default(false)->index();
            $table->boolean('deleted')->default(false)->index();
            $table->boolean('token_holder')->default(false)->index();
            $table->rememberToken();
            $table->bigInteger('language_id')->unsigned()->index()->nullable();
            $table->bigInteger('referral_id')->nullable()->index();
            $table->string('referral_code', 50)->nullable()->index();
            $table->string('default_fiat_currency')->nullable();
            $table->timestamp('demo_enabled_at')->nullable();
            $table->foreignId('current_team_id')->nullable();

            $table->timestamps();

            $table->foreign('language_id')->references('id')->on('languages')->onDelete('SET NULL')->onUpdate('NO ACTION');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('file_uploads');
        Schema::dropIfExists('languages');
        Schema::dropIfExists('users');
    }
}
