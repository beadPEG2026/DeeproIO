<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    public function up(): void {
        Schema::create('umi_business_rules',function(Blueprint $t){$t->id();$t->json('rules');$t->string('digest',64);$t->text('reason');$t->unsignedBigInteger('actor_id')->nullable();$t->timestamp('created_at');});
        Schema::create('umi_business_state',function(Blueprint $t){$t->unsignedInteger('id')->primary();$t->date('business_date');$t->unsignedBigInteger('rule_id')->nullable();$t->boolean('paused')->default(false);});
        DB::table('umi_business_state')->insert(['id'=>1,'business_date'=>now()->toDateString()]);
        Schema::create('umi_business_accounts',function(Blueprint $t){
            $t->id();$t->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();$t->string('code',24)->unique();
            $t->unsignedBigInteger('parent_id')->nullable()->index();$t->foreign('parent_id')->references('id')->on('umi_business_accounts')->restrictOnDelete();
            $t->boolean('fixture')->default(false);$t->boolean('reward_excluded')->default(false);$t->integer('manual_level')->default(0);$t->integer('level')->default(0);
            foreach(['personal','team','small_area','quota_total','quota_used'] as $c)$t->decimal($c,54,24)->default(0);
            $t->timestamps();
        });
        Schema::create('umi_business_operations',function(Blueprint $t){
            $t->uuid('id')->primary();$t->string('scope');$t->string('request_key',120);$t->string('request_hash',64);$t->string('type',40)->index();$t->unsignedBigInteger('actor_id')->nullable();
            $t->unsignedBigInteger('account_id')->nullable()->index();$t->unsignedBigInteger('rule_id');$t->date('business_date')->index();$t->json('details');$t->timestamp('created_at');$t->unique(['scope','request_key']);
        });
        Schema::create('umi_business_balances',function(Blueprint $t){$t->string('bucket',100);$t->string('asset',16);$t->decimal('amount',54,24)->default(0);$t->primary(['bucket','asset']);});
        Schema::create('umi_business_entries',function(Blueprint $t){$t->id();$t->uuid('operation_id');$t->foreign('operation_id')->references('id')->on('umi_business_operations')->restrictOnDelete();$t->string('bucket',100)->index();$t->string('asset',16);$t->decimal('delta',54,24);$t->decimal('balance_after',54,24);$t->string('description',180);$t->timestamp('created_at');});
        Schema::create('umi_business_plans',function(Blueprint $t){
            $t->id();$t->foreignId('account_id')->constrained('umi_business_accounts')->restrictOnDelete();$t->uuid('operation_id')->unique();$t->unsignedBigInteger('rule_id');
            foreach(['amount','price','value_usdt','multiplier','quota','daily_rate','daily_amount','released'] as $c)$t->decimal($c,54,24)->default(0);
            $t->integer('source');$t->integer('burn_type');$t->string('status')->default('active');$t->date('starts_on');$t->date('last_released_on')->nullable();$t->text('note')->nullable();$t->timestamp('created_at');
        });
        Schema::create('umi_business_rewards',function(Blueprint $t){
            $t->id();$t->uuid('operation_id');$t->unsignedBigInteger('account_id')->index();$t->unsignedBigInteger('source_account_id')->nullable();$t->unsignedBigInteger('plan_id')->nullable();$t->string('kind',24);$t->date('business_date')->index();$t->unsignedBigInteger('rule_id');
            foreach(['base','rate','expected','paid','quota_before','quota_after'] as $c)$t->decimal($c,54,24);$t->string('reason',40)->nullable();$t->json('context');$t->timestamp('created_at');
        });
        Schema::create('umi_business_unstakes',function(Blueprint $t){$t->id();$t->unsignedBigInteger('account_id')->index();$t->uuid('operation_id')->unique();$t->unsignedBigInteger('rule_id');$t->decimal('amount',54,24);$t->decimal('fee_rate',54,24);$t->timestamp('unlock_at')->index();$t->timestamp('completed_at')->nullable();$t->timestamp('created_at');});
        Schema::create('umi_business_days',function(Blueprint $t){$t->date('day')->primary();$t->uuid('operation_id')->unique();$t->unsignedBigInteger('rule_id');$t->json('summary');$t->timestamp('created_at');});
        if(DB::getDriverName()==='pgsql'){
            DB::unprepared("CREATE FUNCTION umi_business_append_only() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'umi_business_append_only'; END; $$");
            foreach(['rules','operations','entries','rewards','days'] as $table){$name='umi_business_'.$table;DB::unprepared("CREATE TRIGGER {$name}_immutable BEFORE UPDATE OR DELETE ON {$name} FOR EACH ROW EXECUTE FUNCTION umi_business_append_only(); CREATE TRIGGER {$name}_no_truncate BEFORE TRUNCATE ON {$name} FOR EACH STATEMENT EXECUTE FUNCTION umi_business_append_only();");}
            DB::unprepared("CREATE FUNCTION umi_business_identity_immutable() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.user_id IS DISTINCT FROM OLD.user_id OR NEW.parent_id IS DISTINCT FROM OLD.parent_id OR NEW.code IS DISTINCT FROM OLD.code OR NEW.fixture IS DISTINCT FROM OLD.fixture THEN RAISE EXCEPTION 'umi_business_identity_immutable'; END IF; RETURN NEW; END; $$; CREATE TRIGGER umi_business_identity_guard BEFORE UPDATE ON umi_business_accounts FOR EACH ROW EXECUTE FUNCTION umi_business_identity_immutable();");
            DB::statement('ALTER TABLE umi_business_accounts ADD CHECK (quota_total >= 0 AND quota_used >= 0 AND quota_used <= quota_total)');
        }
    }
    public function down(): void {foreach(['days','unstakes','rewards','plans','entries','balances','operations','accounts','state','rules'] as $t)Schema::dropIfExists('umi_business_'.$t);if(DB::getDriverName()==='pgsql')DB::unprepared('DROP FUNCTION IF EXISTS umi_business_append_only(); DROP FUNCTION IF EXISTS umi_business_identity_immutable();');}
};
