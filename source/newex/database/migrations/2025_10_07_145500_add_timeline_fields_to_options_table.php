<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('options', function (Blueprint $table) {
            $table->integer('timeframe_seconds')->nullable()->after('period');
            $table->dateTime('start_at')->nullable()->after('timeframe_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('options', function (Blueprint $table) {
            if (Schema::hasColumn('options', 'start_at')) {
                $table->dropColumn('start_at');
            }
            if (Schema::hasColumn('options', 'timeframe_seconds')) {
                $table->dropColumn('timeframe_seconds');
            }
        });
    }
};
