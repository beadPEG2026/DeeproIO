<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private $indexes = [
        'wallets' => [
            'wallets_user_currency_fast_idx' => ['user_id', 'currency_id'],
        ],
        'futures_contract' => [
            'fc_user_market_side_lev_status_idx' => ['user_id', 'market_id', 'is_long', 'leverage', 'status'],
            'fc_user_status_created_idx' => ['user_id', 'status', 'created_at'],
            'fc_user_status_type_created_idx' => ['user_id', 'status', 'type', 'created_at'],
        ],
        'auto_invest_orders' => [
            'aio_user_status_started_id_idx' => ['user_id', 'status', 'started_at', 'id'],
            'aio_user_status_currency_idx' => ['user_id', 'status', 'currency_id'],
        ],
        'auto_invest_margin_locks' => [
            'aiml_source_status_idx' => ['source_type', 'source_id', 'status'],
            'aiml_order_status_idx' => ['auto_invest_order_id', 'status'],
            'aiml_user_status_idx' => ['user_id', 'status'],
        ],
        'futures_fee_refund_records' => [
            'ffrr_contract_type_idx' => ['future_contract_id', 'fee_type'],
            'ffrr_user_created_idx' => ['user_id', 'created_at'],
        ],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $table => $indexes) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            foreach ($indexes as $indexName => $columns) {
                if (!$this->tableHasColumns($table, $columns) || $this->indexExists($table, $indexName)) {
                    continue;
                }

                Schema::table($table, function (Blueprint $blueprint) use ($columns, $indexName) {
                    $blueprint->index($columns, $indexName);
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $table => $indexes) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            foreach ($indexes as $indexName => $columns) {
                if (!$this->indexExists($table, $indexName)) {
                    continue;
                }

                Schema::table($table, function (Blueprint $blueprint) use ($indexName) {
                    $blueprint->dropIndex($indexName);
                });
            }
        }
    }

    private function tableHasColumns(string $table, array $columns): bool
    {
        foreach ($columns as $column) {
            if (!Schema::hasColumn($table, $column)) {
                return false;
            }
        }

        return true;
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            return !empty(DB::select(
                'SHOW INDEX FROM `' . str_replace('`', '``', $table) . '` WHERE Key_name = ?',
                [$indexName]
            ));
        }

        if ($driver === 'pgsql') {
            return (bool) DB::table('pg_indexes')
                ->where('tablename', $table)
                ->where('indexname', $indexName)
                ->exists();
        }

        return false;
    }
};
