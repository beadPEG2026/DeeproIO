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
        Schema::table('peer_orders', function (Blueprint $table) {
            $table->boolean('cancelled_by_system')->default(false);
            $table->integer('cancelled_by')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->string('cancellation_reason_message')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('peer_orders', function (Blueprint $table) {
            $table->dropColumn('cancelled_by_system');
            $table->dropColumn('cancelled_by');
            $table->dropColumn('cancellation_reason');
            $table->dropColumn('cancellation_reason_message');
        });
    }
};
