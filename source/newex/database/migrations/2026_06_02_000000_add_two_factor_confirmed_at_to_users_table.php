<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddTwoFactorConfirmedAtToUsersTable extends Migration
{
    public function up()
    {
        if (! Schema::hasColumn('users', 'two_factor_confirmed_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('two_factor_confirmed_at')
                    ->nullable()
                    ->after('two_factor_recovery_codes');
            });

            DB::table('users')
                ->whereNotNull('two_factor_secret')
                ->whereNull('two_factor_confirmed_at')
                ->update(['two_factor_confirmed_at' => now()]);
        }
    }

    public function down()
    {
        if (Schema::hasColumn('users', 'two_factor_confirmed_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('two_factor_confirmed_at');
            });
        }
    }
}
