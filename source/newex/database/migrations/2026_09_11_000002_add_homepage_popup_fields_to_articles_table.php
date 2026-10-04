<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('articles')) {
            return;
        }

        Schema::table('articles', function (Blueprint $table) {
            if (!Schema::hasColumn('articles', 'homepage_popup_enabled')) {
                $table->boolean('homepage_popup_enabled')
                    ->default(false)
                    ->index();
            }

            if (!Schema::hasColumn('articles', 'homepage_popup_excluded_countries')) {
                $table->text('homepage_popup_excluded_countries')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('articles')) {
            return;
        }

        Schema::table('articles', function (Blueprint $table) {
            if (Schema::hasColumn('articles', 'homepage_popup_excluded_countries')) {
                $table->dropColumn('homepage_popup_excluded_countries');
            }

            if (Schema::hasColumn('articles', 'homepage_popup_enabled')) {
                if (Schema::hasIndex('articles', ['homepage_popup_enabled'])) {
                    $table->dropIndex(['homepage_popup_enabled']);
                }

                $table->dropColumn('homepage_popup_enabled');
            }
        });
    }
};
