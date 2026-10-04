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
        Schema::table('markets', function (Blueprint $table) {
            // Flexible cycle interval range (in milliseconds for precision)
            // Min interval: minimum time between cycles
            // Max interval: maximum time between cycles
            // The bot will randomly pick a value between min and max each cycle
            $table->integer('bot_cycle_interval_min')->default(1000)->after('bot_cycle_interval'); // 1 second default
            $table->integer('bot_cycle_interval_max')->default(5000)->after('bot_cycle_interval_min'); // 5 seconds default
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('markets', function (Blueprint $table) {
            $table->dropColumn(['bot_cycle_interval_min', 'bot_cycle_interval_max']);
        });
    }
};
