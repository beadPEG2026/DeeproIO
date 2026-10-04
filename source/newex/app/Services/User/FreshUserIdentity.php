<?php

namespace App\Services\User;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Keep new accounts beyond both live users and retained legacy references. */
class FreshUserIdentity
{
    public function references(): array
    {
        return DB::select("SELECT DISTINCT table_name, column_name FROM information_schema.columns
            WHERE table_schema='public' AND data_type IN ('integer','bigint','smallint')
            AND (column_name LIKE '%user%' OR column_name IN
                ('owner_id','referral_id','model_id','tokenable_id','created_by','updated_by','causer_id','subject_id','admin_id'))
            UNION
            SELECT r.relname, a.attname FROM pg_constraint c
            JOIN pg_class r ON r.oid=c.conrelid
            JOIN pg_attribute a ON a.attrelid=c.conrelid AND a.attnum=ANY(c.conkey)
            WHERE c.confrelid='users'::regclass AND c.contype='f'");
    }

    public function highWaterMark(): int
    {
        $max = (int) DB::table('users')->max('id');
        foreach ($this->references() as $column) {
            $max = max($max, (int) DB::table($column->table_name)->max($column->column_name));
        }
        return $max;
    }

    public function allocate(?int $explicitId = null): int
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Fresh user identity allocation requires PostgreSQL.');
        }

        return DB::transaction(function () use ($explicitId) {
            // Same lock as the sequence repair command; no backward sequence reset.
            DB::statement('LOCK TABLE users IN SHARE ROW EXCLUSIVE MODE');
            $max = $this->highWaterMark();
            if ($explicitId !== null && $explicitId <= $max) {
                throw new RuntimeException('User ID overlaps the retained identity range.');
            }
            $seq = DB::selectOne("SELECT pg_get_serial_sequence('users','id') AS name")->name;
            if (!$seq) {
                throw new RuntimeException('User ID sequence is not configured.');
            }
            $identifier = DB::connection()->getQueryGrammar()->wrapTable($seq);
            $state = DB::selectOne('SELECT last_value,is_called FROM '.$identifier);
            $floor = max($max, $explicitId ?? 0);
            $next = (int) $state->last_value + ($state->is_called ? 1 : 0);
            if ($next <= $floor) {
                DB::select('SELECT setval(?::regclass, ?, true)', [$seq, $floor]);
            }
            if ($explicitId !== null) {
                // Explicit creation must not reuse an ID already handed out by the sequence.
                if ($explicitId < $next) {
                    throw new RuntimeException('User ID is below the allocation sequence.');
                }
                return $explicitId;
            }
            return (int) DB::selectOne('SELECT nextval(?::regclass) AS id', [$seq])->id;
        });
    }
}
