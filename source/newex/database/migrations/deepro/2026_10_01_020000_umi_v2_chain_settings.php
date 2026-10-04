<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('umi_v2_chain_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedInteger('chain_id')->default(56);
            $table->string('token_contract', 42)->nullable();
            $table->string('burn_address', 42)->nullable();
            $table->string('dedicated_address', 42)->nullable();
            $table->string('payout_source', 40)->default('deepro_pool');
            $table->string('reward_source', 40)->default('deepro_pool');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestampsTz();
        });
        Schema::create('umi_v2_chain_settings_audit', function (Blueprint $table): void {
            $table->id();
            $table->string('request_key', 120)->unique();
            $table->json('before_json');
            $table->json('after_json');
            $table->unsignedBigInteger('actor_id');
            $table->dateTimeTz('created_at');
        });
        DB::table('umi_v2_chain_settings')->insert([
            'id' => 1, 'chain_id' => 56, 'payout_source' => 'deepro_pool',
            'reward_source' => 'deepro_pool', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (Schema::hasTable('umi_v2_chain_settings_audit') &&
            DB::table('umi_v2_chain_settings_audit')->exists()) {
            throw new RuntimeException('UMI v2 chain settings audit history must not be dropped');
        }
        Schema::dropIfExists('umi_v2_chain_settings_audit');
        Schema::dropIfExists('umi_v2_chain_settings');
    }
};
