<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        if(!Schema::hasTable('staking_settlement_tasks')) Schema::create('staking_settlement_tasks',function(Blueprint $t){
            $t->unsignedBigInteger('stake_id')->primary();$t->string('status',24)->index();$t->unsignedInteger('attempts')->default(0);
            $t->string('error_code',120)->nullable();$t->timestamp('last_attempt_at');$t->timestamp('resolved_at')->nullable();$t->timestamps();
        });
    }
    public function down(): void { /* Retain settlement audit evidence on code rollback. */ }
};
