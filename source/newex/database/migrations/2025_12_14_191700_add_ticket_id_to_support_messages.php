<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('support_messages', function (Blueprint $table) {
            // Add ticket_id as nullable initially to allow backfilling existing rows
            $table->string('ticket_id', 15)->nullable()->after('id');
        });

        // Backfill existing records with 15-digit zero-padded IDs
        $rows = DB::table('support_messages')->select('id')->get();
        foreach ($rows as $row) {
            $ticketId = str_pad((string)$row->id, 15, '0', STR_PAD_LEFT);
            DB::table('support_messages')
                ->where('id', $row->id)
                ->update(['ticket_id' => $ticketId]);
        }

        // Add a unique index to ensure uniqueness
        Schema::table('support_messages', function (Blueprint $table) {
            $table->unique('ticket_id', 'support_messages_ticket_id_unique');
            $table->index('ticket_id', 'support_messages_ticket_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('support_messages', function (Blueprint $table) {
            // Drop indexes if they exist, then drop the column
            $table->dropUnique('support_messages_ticket_id_unique');
            $table->dropIndex('support_messages_ticket_id_index');
            $table->dropColumn('ticket_id');
        });
    }
};
