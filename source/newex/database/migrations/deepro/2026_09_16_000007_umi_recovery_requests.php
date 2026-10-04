<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {Schema::create('umi_recovery_requests',function(Blueprint $t){
        $t->uuid('id')->primary();$t->text('identifier');$t->text('reply_email');$t->text('evidence');
        $t->string('state')->default('pending');$t->timestamps();
    });}
    public function down(): void {Schema::dropIfExists('umi_recovery_requests');}
};
