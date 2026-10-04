<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'ip_location')) {
            Schema::table('users', fn (Blueprint $table) => $table->string('ip_location', 255)->nullable());
        }
    }

    public function down(): void
    {
        // Keep additive metadata on code rollback; older readers safely ignore it.
    }
};
