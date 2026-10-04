<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chain_deposit_scan_states', function (Blueprint $t) {
            $t->unsignedBigInteger('realtime_from')->nullable();
            $t->unsignedBigInteger('realtime_through')->nullable();
            $t->timestamp('backfill_attempted_at')->nullable();
            $t->unsignedInteger('backfill_range_cap')->nullable();
        });
    }
    public function down(): void
    {
        throw new RuntimeException('Keep scan gaps and financial progress; rollback code without dropping cursor columns.');
    }
};
