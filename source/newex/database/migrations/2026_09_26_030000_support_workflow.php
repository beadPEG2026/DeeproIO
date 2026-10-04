<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('support_messages',function(Blueprint $t){
            $t->unsignedBigInteger('assigned_to')->nullable()->index();
            $t->string('priority',16)->default('normal')->index();
            $t->timestamp('due_at')->nullable()->index();
            $t->unsignedInteger('revision')->default(0);
        });
        Schema::create('support_ticket_entries',function(Blueprint $t){
            $t->bigIncrements('id');$t->unsignedBigInteger('support_message_id')->index();
            $t->unsignedBigInteger('actor_id')->nullable();$t->uuid('request_key')->unique();
            $t->string('kind',16);$t->text('body')->nullable();$t->json('changes')->nullable();
            $t->string('mail_status',24)->default('not_requested');$t->timestamp('mailed_at')->nullable();
            $t->timestamps();
        });
    }
    public function down(): void {
        throw new RuntimeException('Keep support history when rolling back application code.');
    }
};
