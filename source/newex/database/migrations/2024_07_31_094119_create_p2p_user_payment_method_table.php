<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('peer_user_payment_methods', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('payment_method')->unsigned();
            $table->integer('user_id');
            $table->boolean('is_archived')->nullable()->default(false);
            $table->longText('content')->nullable();
            $table->timestamps();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('peer_user_payment_methods');
    }
};
