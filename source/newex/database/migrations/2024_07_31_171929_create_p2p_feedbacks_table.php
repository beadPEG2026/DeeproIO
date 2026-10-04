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
        Schema::create('peer_ads_feedbacks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->integer('method_id')->nullable();
            $table->longText('content')->nullable();
            $table->longText('reply_content')->nullable();
            $table->integer('author_id')->index();
            $table->uuid('order_id')->index();
            $table->integer('post_user_id')->index();
            $table->boolean('is_negative')->default(false);
            $table->boolean('is_anonymous')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('peer_ads_feedbacks');
    }
};
