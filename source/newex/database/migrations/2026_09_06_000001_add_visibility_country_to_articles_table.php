<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('articles', 'visibility_country')) {
            Schema::table('articles', function (Blueprint $table) {
                $table->string('visibility_country', 2)
                    ->nullable()
                    ->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('articles', 'visibility_country')) {
            Schema::table('articles', function (Blueprint $table) {
                $table->dropColumn('visibility_country');
            });
        }
    }
};
