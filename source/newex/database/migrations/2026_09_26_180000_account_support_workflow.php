<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        foreach (['launchpads', 'copy_trading_traders'] as $table) Schema::table($table, function (Blueprint $t) {
            $t->unsignedInteger('publication_revision')->default(0);
        });
        Schema::table('file_uploads', function (Blueprint $t) {
            $t->unsignedBigInteger('owner_id')->nullable()->index();
            $t->string('purpose', 32)->nullable();
            $t->boolean('is_private')->default(false);
            $t->timestamp('bound_at')->nullable();
        });
        Schema::table('support_messages', function (Blueprint $t) {
            $t->uuid('request_key')->nullable()->unique();
        });
        Schema::table('support_ticket_entries', function (Blueprint $t) {
            $t->unsignedBigInteger('file_id')->nullable()->index();
        });
    }
    public function down(): void {
        throw new RuntimeException('Retain attachment ownership and ticket history during code rollback.');
    }
};
