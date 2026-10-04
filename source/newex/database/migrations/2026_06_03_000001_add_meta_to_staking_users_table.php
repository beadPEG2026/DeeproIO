<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('staking_users', 'meta')) {
            Schema::table('staking_users', function (Blueprint $table) {
                $table->json('meta')->nullable();
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('staking_users', 'meta')) {
            Schema::table('staking_users', function (Blueprint $table) {
                $table->dropColumn('meta');
            });
        }
    }
};
