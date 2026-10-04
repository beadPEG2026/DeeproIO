<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};

return new class extends Migration {
    public function up():void {
        Schema::create('umi_release_revisions',function(Blueprint $t){
            $t->id();$t->string('target_type',20);$t->unsignedBigInteger('target_id');
            $t->unsignedBigInteger('account_id');$t->uuid('operation_id')->unique();
            $t->date('effective_on');$t->string('status',20);$t->decimal('daily_rate',54,24)->nullable();
            $t->decimal('daily_amount',54,24);$t->text('reason');$t->timestamp('created_at');
            $t->index(['target_type','target_id','effective_on']);
        });
        Schema::create('umi_level_history',function(Blueprint $t){
            $t->id();$t->unsignedBigInteger('account_id')->index();$t->uuid('operation_id');
            $t->unsignedBigInteger('rule_id');$t->date('business_date');
            $t->integer('before_level');$t->integer('after_level');$t->integer('manual_level');
            $t->decimal('personal',54,24);$t->decimal('team',54,24);$t->decimal('small_area',54,24);
            $t->timestamp('created_at');$t->unique(['account_id','operation_id']);
        });
        if(DB::getDriverName()==='pgsql')foreach(['umi_release_revisions','umi_level_history'] as $name){
            DB::unprepared("CREATE TRIGGER {$name}_immutable BEFORE UPDATE OR DELETE ON {$name} FOR EACH ROW EXECUTE FUNCTION umi_business_append_only(); CREATE TRIGGER {$name}_no_truncate BEFORE TRUNCATE ON {$name} FOR EACH STATEMENT EXECUTE FUNCTION umi_business_append_only();");
        }
    }
    public function down():void {Schema::dropIfExists('umi_level_history');Schema::dropIfExists('umi_release_revisions');}
};
