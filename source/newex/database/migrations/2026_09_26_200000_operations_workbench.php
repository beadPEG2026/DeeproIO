<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('operations_incidents', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('check_key', 80)->unique();
            $t->string('severity', 16);
            $t->string('status', 24)->default('open')->index();
            $t->unsignedBigInteger('assigned_to')->nullable()->index();
            $t->timestampTz('due_at');
            $t->timestampTz('opened_at');
            $t->timestampTz('last_unhealthy_at');
            $t->timestampTz('sample_at')->nullable();
            $t->timestampTz('recovery_at')->nullable();
            $t->timestampTz('resolved_at')->nullable();
            $t->unsignedInteger('revision')->default(0);
            $t->jsonb('evidence');
            $t->timestampsTz();
        });
        Schema::create('operations_service_cases', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('user_id')->unique();
            $t->unsignedBigInteger('assigned_to')->nullable()->index();
            $t->string('status', 24)->default('open');
            $t->string('priority', 16)->default('normal');
            $t->timestampTz('due_at')->nullable();
            $t->unsignedInteger('revision')->default(0);
            $t->timestampsTz();
        });
        // An append-only operational history, not a second financial ledger.
        Schema::create('operations_events', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->uuid('request_key')->unique();
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('object_type', 32);
            $t->string('object_id', 100);
            $t->string('action', 64);
            $t->text('reason')->nullable();
            $t->jsonb('changes');
            $t->timestampTz('created_at')->useCurrent();
            $t->index(['object_type','object_id','id']);
        });
    }
    public function down(): void {
        throw new RuntimeException('Keep operational history on code rollback.');
    }
};
