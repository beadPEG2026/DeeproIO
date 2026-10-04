<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ClearUnconfirmedTwoFactorSetups extends Migration
{
    public function up()
    {
        if (! Schema::hasColumn('users', 'two_factor_confirmed_at')) {
            return;
        }

        DB::table('users')
            ->whereNotNull('two_factor_secret')
            ->whereNull('two_factor_confirmed_at')
            ->update([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
            ]);
    }

    public function down()
    {
        //
    }
}
