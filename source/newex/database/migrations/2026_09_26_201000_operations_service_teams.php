<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void {
        Schema::create('operations_service_teams',function(Blueprint $t){$t->bigIncrements('id');$t->string('name',80)->unique();$t->boolean('enabled')->default(true);$t->jsonb('member_ids');$t->unsignedInteger('revision')->default(0);$t->timestampsTz();});
        Schema::table('operations_service_cases',function(Blueprint $t){$t->unsignedBigInteger('team_id')->nullable()->index();});
    }
    public function down():void {throw new RuntimeException('Preserve service assignment history during code rollback.');}
};
