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
        Schema::create('peer_orders_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('message')->nullable();
            $table->string('type')->nullable();
            $table->uuid('order_id')->index();
            $table->bigInteger('user_id')->index()->nullable();
            $table->boolean('is_author')->default(false);
            $table->boolean('is_system')->default(false);
            $table->boolean('is_visible_owner')->default(false);
            $table->boolean('is_visible_counterparty')->default(false);
            $table->bigInteger('order_owner_id')->index()->nullable();
            $table->bigInteger('order_counterparty_id')->index()->nullable();
            $table->boolean('seen')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('peer_orders_messages');
    }
};
