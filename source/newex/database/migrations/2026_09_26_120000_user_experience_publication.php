<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        foreach (['launchpads', 'copy_trading_traders'] as $table) Schema::table($table, function (Blueprint $t) {
            $t->string('publication_status', 16)->default('draft');
            $t->text('publication_reference')->nullable();
            $t->unsignedBigInteger('publication_reviewed_by')->nullable();
            $t->timestamp('publication_reviewed_at')->nullable();
        });
        Schema::create('product_publication_events', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->string('product_type', 32); $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('actor_id'); $t->string('status', 16); $t->text('reference')->nullable();
            $t->string('content_digest', 64); $t->timestamp('created_at');
        });
        Schema::table('pages', function (Blueprint $t) {
            $t->string('title_zh-cn')->nullable(); $t->text('content_zh-cn')->nullable(); $t->text('html_content_zh-cn')->nullable();
        });
    }
    public function down(): void
    {
        // Keep editorial history and translations during code rollback.
    }
};
