<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Schema,DB};

return new class extends Migration {
    public function up():void {
        Schema::table('umi_business_accounts',function(Blueprint $t){
            $t->unsignedBigInteger('legacy_id')->nullable()->unique();
            $t->unsignedBigInteger('legacy_parent_id')->nullable();
        });
        DB::statement('ALTER TABLE umi_business_accounts ALTER COLUMN user_id DROP NOT NULL');
        Schema::create('umi_continuity_batches',function(Blueprint $t){
            $t->uuid('id')->primary();$t->string('fingerprint',64)->unique();$t->date('cutoff');
            $t->uuid('operation_id')->unique();$t->json('summary');$t->timestamp('created_at');
        });
        Schema::create('umi_continuity_openings',function(Blueprint $t){
            $t->unsignedBigInteger('account_id')->primary();$t->unsignedBigInteger('legacy_id')->unique();
            $t->uuid('batch_id');$t->date('cutoff');$t->string('status');$t->json('snapshot');
            $t->decimal('quota_total',54,24);$t->decimal('quota_used',54,24);$t->decimal('daily_release',54,24);
            $t->timestamp('created_at');
        });
        Schema::create('umi_pending_rewards',function(Blueprint $t){
            $t->id();$t->string('source_key',64)->unique();$t->uuid('operation_id');$t->unsignedBigInteger('account_id')->index();
            $t->unsignedBigInteger('source_account_id')->nullable();$t->unsignedBigInteger('plan_id')->nullable();
            $t->unsignedBigInteger('rule_id');$t->date('business_date');$t->string('kind');
            $t->decimal('amount',54,24);$t->json('context');$t->string('reason');$t->timestamp('created_at');
        });
        Schema::create('umi_reward_corrections',function(Blueprint $t){
            $t->id();$t->unsignedBigInteger('pending_id')->unique();$t->uuid('operation_id');
            $t->decimal('paid',54,24);$t->text('reason');$t->timestamp('created_at');
        });
        Schema::create('umi_binding_challenges',function(Blueprint $t){
            $t->uuid('id')->primary();$t->unsignedBigInteger('legacy_id');$t->unsignedBigInteger('user_id');
            $t->string('email_lookup',64);$t->string('code_hash');$t->integer('attempts')->default(0);
            $t->timestamp('expires_at');$t->timestamp('used_at')->nullable();$t->timestamp('created_at');
        });
        if(DB::getDriverName()==='pgsql'){
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION umi_business_identity_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF ROW(NEW.parent_id,NEW.code,NEW.fixture,NEW.legacy_id,NEW.legacy_parent_id)
 IS DISTINCT FROM ROW(OLD.parent_id,OLD.code,OLD.fixture,OLD.legacy_id,OLD.legacy_parent_id)
 THEN RAISE EXCEPTION 'umi_business_identity_immutable'; END IF;
 IF NEW.user_id IS DISTINCT FROM OLD.user_id AND NOT (
   OLD.user_id IS NULL AND NEW.user_id IS NOT NULL AND OLD.legacy_id IS NOT NULL AND
   EXISTS (SELECT 1 FROM umi_legacy_accounts WHERE legacy_id=OLD.legacy_id AND user_id=NEW.user_id AND activation_status='activated')
 ) THEN RAISE EXCEPTION 'umi_business_identity_immutable'; END IF;
 RETURN NEW;
END; $$;
SQL);
            foreach(['umi_continuity_batches','umi_continuity_openings','umi_pending_rewards','umi_reward_corrections'] as $table){
                DB::unprepared("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION umi_business_append_only(); CREATE TRIGGER {$table}_no_truncate BEFORE TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION umi_business_append_only();");
            }
        }
    }
    public function down():void {throw new RuntimeException('Continuity contains financial opening records; restore a verified backup instead of dropping history.');}
};
