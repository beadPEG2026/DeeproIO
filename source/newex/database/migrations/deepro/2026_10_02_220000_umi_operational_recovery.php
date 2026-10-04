<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
return new class extends Migration {
    public function up(): void {
        if (!Schema::hasTable('umi_v2_rate_schedule')) Schema::create('umi_v2_rate_schedule',function(Blueprint $t){
            $t->id();$t->date('effective_on')->unique();$t->decimal('static_rate',30,24);$t->unsignedBigInteger('actor_id')->nullable();$t->timestamps();
        });
        if (!DB::table('umi_v2_rate_schedule')->exists() && Schema::hasTable('umi_v2_live_settings')) {
            $rate=DB::table('umi_v2_live_settings')->where('id',1)->value('settlement_rate');
            if ($rate!==null) DB::table('umi_v2_rate_schedule')->insert(['effective_on'=>now(config('umi-v2.timezone','Asia/Shanghai'))->toDateString(),'static_rate'=>$rate,'created_at'=>now(),'updated_at'=>now()]);
        }
        if (!Schema::hasTable('umi_v2_rank_audit')) Schema::create('umi_v2_rank_audit',function(Blueprint $t){
            $t->id();$t->unsignedBigInteger('member_id')->index();$t->date('business_date')->index();$t->unsignedTinyInteger('before_level');$t->unsignedTinyInteger('after_level');
            $t->decimal('personal_usdt',48,24);$t->decimal('small_area_usdt',48,24);$t->string('basis_sha256',64);$t->unsignedBigInteger('actor_id')->nullable();$t->timestamp('created_at');
        });
        if (!Schema::hasTable('umi_v2_recovery_tasks')) Schema::create('umi_v2_recovery_tasks',function(Blueprint $t){
            $t->id();$t->string('task_key',120)->unique();$t->string('kind',40)->index();$t->unsignedBigInteger('reference_id');$t->unsignedBigInteger('member_id')->nullable()->index();
            $t->string('status',24)->default('pending')->index();$t->unsignedInteger('attempts')->default(0);$t->string('error_code',120)->nullable();$t->text('details_json')->nullable();
            $t->timestamp('last_attempt_at')->nullable();$t->timestamp('next_attempt_at')->nullable()->index();$t->timestamp('resolved_at')->nullable();$t->timestamps();
        });
        if (!Schema::hasTable('umi_v2_recovery_actions')) Schema::create('umi_v2_recovery_actions',function(Blueprint $t){
            $t->id();$t->unsignedBigInteger('task_id')->nullable()->index();$t->string('action',40);$t->unsignedBigInteger('actor_id');$t->string('reason',240);$t->text('result_json');$t->timestamp('created_at');
        });
    }
    public function down(): void { /* Financial evidence is intentionally retained on code rollback. */ }
};
