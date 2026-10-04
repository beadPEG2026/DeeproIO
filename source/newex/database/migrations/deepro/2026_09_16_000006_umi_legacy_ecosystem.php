<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('umi_import_batches', function(Blueprint $t) {
            $t->id(); $t->string('fingerprint',64)->unique(); $t->json('summary'); $t->timestamp('created_at');
        });
        Schema::create('umi_legacy_accounts', function(Blueprint $t) {
            $t->unsignedBigInteger('legacy_id')->primary(); $t->string('legacy_uuid')->unique();
            $t->unsignedBigInteger('parent_legacy_id')->nullable()->index();
            $t->unsignedBigInteger('user_id')->nullable()->unique();
            $t->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            $t->unsignedBigInteger('batch_id'); $t->string('source_hash',64);
            $t->string('level')->nullable(); $t->integer('legacy_status');
            $t->text('identity'); $t->text('profile'); $t->string('email_lookup',64)->nullable()->index();
            $t->string('activation_status')->default('pending');
            $t->text('approved_email')->nullable(); $t->string('approved_email_lookup',64)->nullable()->unique();
            $t->timestamp('activated_at')->nullable(); $t->timestamps();
        });
        Schema::create('umi_legacy_records', function(Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('batch_id'); $t->string('source'); $t->string('source_id');
            $t->unsignedBigInteger('legacy_id')->index(); $t->text('payload'); $t->string('source_hash',64);
            $t->unique(['source','source_id']);
        });
        Schema::create('umi_source_files', function(Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('batch_id'); $t->text('path'); $t->string('sha256',64);
            $t->text('contents');
        });
        Schema::create('umi_identity_reviews', function(Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('legacy_id')->index(); $t->unsignedBigInteger('proposed_by');
            $t->unsignedBigInteger('reviewed_by')->nullable(); $t->text('email'); $t->text('evidence');
            $t->string('state')->default('pending'); $t->timestamp('reviewed_at')->nullable(); $t->timestamps();
        });
        Schema::create('umi_activation_challenges', function(Blueprint $t) {
            $t->uuid('id')->primary(); $t->unsignedBigInteger('legacy_id')->index();
            $t->string('email_lookup',64); $t->string('code_hash'); $t->integer('attempts')->default(0);
            $t->timestamp('expires_at'); $t->timestamp('used_at')->nullable(); $t->timestamp('created_at');
        });
    }
    public function down(): void {
        foreach(['umi_activation_challenges','umi_identity_reviews','umi_source_files','umi_legacy_records','umi_legacy_accounts','umi_import_batches'] as $table) Schema::dropIfExists($table);
    }
};
