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
        Schema::create('peer_orders_appeals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('order_id')->index();
            $table->integer('appeal_author_id')->index()->nullable();
            $table->integer('appealed_user')->index()->nullable();
            $table->integer('appeal_judge_user_id')->index()->nullable();
            $table->text('content')->nullable();
            $table->string('status')->index();
            $table->string('timeframe')->nullable();
            $table->string('type')->nullable();
            $table->boolean('is_order_owner')->default(false);
            $table->boolean('is_cs')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('peer_orders_appeals');
    }
};
