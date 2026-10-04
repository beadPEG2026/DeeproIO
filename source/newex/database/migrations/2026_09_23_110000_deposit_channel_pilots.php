<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('deposit_channels', function (Blueprint $t) {
            $t->json('pilot_user_ids')->nullable();
            $t->decimal('pilot_minimum', 36, 18)->nullable();
            $t->decimal('pilot_limit', 36, 18)->nullable();
            $t->timestamp('pilot_started_at')->nullable();
            $t->timestamp('pilot_expires_at')->nullable();
            $t->unsignedBigInteger('pilot_start_block')->nullable();
            $t->string('pilot_digest', 64)->nullable();
        });
        // CLI initialization is a system action, never an impersonated administrator.
        Schema::table('deposit_channel_audits', fn (Blueprint $t) => $t->unsignedBigInteger('actor_id')->nullable()->change());
    }

    public function down(): void
    {
        throw new RuntimeException('Retain pilot limits, receipts and audit evidence; use a reviewed forward migration.');
    }
};
