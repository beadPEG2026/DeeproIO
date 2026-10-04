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
            // Default chart resolution (1, 5, 15, 30, 60, 240, 720, 1D, 3D, 1W, 1M)
            $table->string('chart_default_resolution', 10)->default('1D')->after('chart_symbol');
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
            $table->dropColumn('chart_default_resolution');
        });
    }
};
