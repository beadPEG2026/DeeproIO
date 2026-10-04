<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('umi_recovery_requests',function(Blueprint $t){
            $t->unsignedBigInteger('assigned_to')->nullable();
            $t->unsignedBigInteger('legacy_id')->nullable();
            $t->unsignedInteger('version')->default(0);
            $t->timestampTz('closed_at')->nullable();
        });
        Schema::create('umi_recovery_actions',function(Blueprint $t){
            $t->bigIncrements('id');$t->uuid('request_id')->index();$t->unsignedBigInteger('actor_id');
            $t->string('from_state',32);$t->string('to_state',32);$t->text('note');
            $t->unsignedInteger('version');$t->timestampTz('created_at');
            $t->unique(['request_id','version']);
        });
        Schema::table('umi_activation_challenges',function(Blueprint $t){
            $t->string('delivery_state',20)->default('pending');$t->timestampTz('sent_at')->nullable();
        });
    }
    public function down(): void { throw new RuntimeException('UMI identity audit history must be retained.'); }
};
