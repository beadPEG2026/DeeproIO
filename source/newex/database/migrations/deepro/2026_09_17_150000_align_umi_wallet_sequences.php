<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    public function up(): void {
        // Restored legacy rows may have IDs above the sequence head. Only advance
        // allocation; never renumber an account, wallet, or historical record.
        foreach (['users','wallets'] as $table) {
            DB::statement('LOCK TABLE "'.$table.'" IN SHARE ROW EXCLUSIVE MODE');
            $seq=DB::selectOne("SELECT pg_get_serial_sequence(?, 'id') AS name",[$table])->name;
            if(!$seq)$seq=DB::selectOne('SELECT to_regclass(?) AS name',[$table.'_id_seq'])->name;
            if(!$seq)continue;
            $last=DB::selectOne('SELECT last_value FROM '.$seq)->last_value;
            $max=DB::table($table)->max('id');
            if($max && $max>$last)DB::select('SELECT setval(?, ?, true)',[$seq,$max]);
        }
    }
    public function down(): void {}
};
