<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Publishing code must not authorize previously blocked transfers.
        Schema::table('custody_networks', fn (Blueprint $t) => $t->string('auto_sweep_scope', 24)->default('new_live'));
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve custody policy and audit history on code rollback.');
    }
};
