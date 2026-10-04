<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('umi_v2_members', function (Blueprint $table): void {
            $table->unsignedTinyInteger('test_level_override')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('umi_v2_members', function (Blueprint $table): void {
            $table->dropColumn('test_level_override');
        });
    }
};
