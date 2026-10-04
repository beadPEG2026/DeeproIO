<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('articles', 'visibility_referral_user_id')) {
            Schema::table('articles', function (Blueprint $table) {
                $table->unsignedBigInteger('visibility_referral_user_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('articles', 'visibility_referral_user_id')) {
            Schema::table('articles', function (Blueprint $table) {
                $table->dropColumn('visibility_referral_user_id');
            });
        }
    }
};
