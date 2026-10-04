<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OptimizeFuturesOpenPerformanceCommand extends Command
{
    protected $signature = 'futures:optimize-open-indexes {--dry-run : Show planned index changes without executing them}';

    protected $description = 'Add optional performance indexes for futures opening and auto-invest margin locking';

    private $indexes = [
        'wallets' => [
            'wallets_user_currency_fast_idx' => ['user_id', 'currency_id'],
        ],
        'futures_contract' => [
            'fc_user_market_side_lev_status_idx' => ['user_id', 'market_id', 'is_long', 'leverage', 'status'],
            'fc_user_status_created_idx' => ['user_id', 'status', 'created_at'],
            'fc_user_status_market_created_idx' => ['user_id', 'status', 'market_id', 'created_at'],
            'fc_user_status_type_created_idx' => ['user_id', 'status', 'type', 'created_at'],
            'fc_user_status_type_market_created_idx' => ['user_id', 'status', 'type', 'market_id', 'created_at'],
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

    private $postgresPartialIndexes = [
        'futures_contract' => [
            'fc_active_merge_partial_idx' => [
                'columns' => ['user_id', 'market_id', 'is_long', 'leverage'],
                'required' => ['status'],
                'where' => "status = 'active'",
            ],
            'fc_open_user_created_partial_idx' => [
                'columns' => ['user_id', 'created_at'],
                'required' => ['status'],
                'where' => "status IN ('active', 'scheduled')",
            ],
            'fc_open_user_market_created_partial_idx' => [
                'columns' => ['user_id', 'market_id', 'created_at'],
                'required' => ['status'],
                'where' => "status IN ('active', 'scheduled')",
            ],
            'fc_pending_limit_user_created_partial_idx' => [
                'columns' => ['user_id', 'created_at'],
                'required' => ['status', 'type'],
                'where' => "status = 'pending' AND type = 'limit'",
            ],
            'fc_pending_limit_user_market_created_partial_idx' => [
                'columns' => ['user_id', 'market_id', 'created_at'],
                'required' => ['status', 'type'],
                'where' => "status = 'pending' AND type = 'limit'",
            ],
        ],
        'auto_invest_orders' => [
            'aio_active_available_margin_idx' => [
                'columns' => ['user_id', 'started_at', 'id'],
                'required' => ['status', 'amount', 'used_margin'],
                'where' => "status = 'active' AND (COALESCE(amount, 0) - COALESCE(used_margin, 0)) > 0",
            ],
        ],
        'auto_invest_margin_locks' => [
            'aiml_active_source_partial_idx' => [
                'columns' => ['source_type', 'source_id'],
                'required' => ['status'],
                'where' => "status = 'active'",
            ],
            'aiml_active_order_partial_idx' => [
                'columns' => ['auto_invest_order_id'],
                'required' => ['status'],
                'where' => "status = 'active'",
            ],
        ],
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $added = 0;
        $skipped = 0;
        $failed = 0;

        $driver = DB::getDriverName();

        foreach ($this->indexes as $table => $indexes) {
            if (!Schema::hasTable($table)) {
                $this->line("skip {$table}: table not found");
                $skipped++;
                continue;
            }

            foreach ($indexes as $indexName => $columns) {
                if (!$this->tableHasColumns($table, $columns)) {
                    $this->line("skip {$table}.{$indexName}: columns not found");
                    $skipped++;
                    continue;
                }

                if ($this->indexExists($table, $indexName)) {
                    $this->line("skip {$table}.{$indexName}: already exists");
                    $skipped++;
                    continue;
                }

                if ($dryRun) {
                    $this->info("dry-run add {$table}.{$indexName} (" . implode(', ', $columns) . ')');
                    $skipped++;
                    continue;
                }

                try {
                    if ($driver === 'pgsql') {
                        $this->createPostgresIndex($table, $indexName, $columns);
                    } else {
                        Schema::table($table, function (Blueprint $blueprint) use ($columns, $indexName) {
                            $blueprint->index($columns, $indexName);
                        });
                    }

                    $this->info("added {$table}.{$indexName}");
                    $added++;
                } catch (\Throwable $e) {
                    $this->error("failed {$table}.{$indexName}: " . $e->getMessage());
                    $failed++;
                }
            }
        }

        if ($driver === 'pgsql') {
            foreach ($this->postgresPartialIndexes as $table => $indexes) {
                if (!Schema::hasTable($table)) {
                    $this->line("skip {$table}: table not found");
                    $skipped++;
                    continue;
                }

                foreach ($indexes as $indexName => $definition) {
                    $columns = $definition['columns'];
                    $requiredColumns = array_values(array_unique(array_merge(
                        $columns,
                        $definition['required'] ?? []
                    )));

                    if (!$this->tableHasColumns($table, $requiredColumns)) {
                        $this->line("skip {$table}.{$indexName}: columns not found");
                        $skipped++;
                        continue;
                    }

                    if ($this->indexExists($table, $indexName)) {
                        $this->line("skip {$table}.{$indexName}: already exists");
                        $skipped++;
                        continue;
                    }

                    if ($dryRun) {
                        $this->info("dry-run add {$table}.{$indexName} (" . implode(', ', $columns) . ') WHERE ' . $definition['where']);
                        $skipped++;
                        continue;
                    }

                    try {
                        $this->createPostgresIndex($table, $indexName, $columns, $definition['where']);
                        $this->info("added {$table}.{$indexName}");
                        $added++;
                    } catch (\Throwable $e) {
                        $this->error("failed {$table}.{$indexName}: " . $e->getMessage());
                        $failed++;
                    }
                }
            }
        }

        $this->line("done: added={$added}, skipped={$skipped}, failed={$failed}");

        return $failed > 0 ? 1 : 0;
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
            $safeTable = str_replace('`', '``', $table);

            return !empty(DB::select(
                "SHOW INDEX FROM `{$safeTable}` WHERE Key_name = ?",
                [$indexName]
            ));
        }

        if ($driver === 'pgsql') {
            return DB::table('pg_indexes')
                ->where('tablename', $table)
                ->where('indexname', $indexName)
                ->exists();
        }

        if ($driver === 'sqlite') {
            $indexes = DB::select("PRAGMA index_list('{$table}')");

            foreach ($indexes as $index) {
                if (($index->name ?? null) === $indexName) {
                    return true;
                }
            }
        }

        return false;
    }

    private function createPostgresIndex(string $table, string $indexName, array $columns, ?string $where = null): void
    {
        $columnSql = implode(', ', array_map([$this, 'quotePgIdentifier'], $columns));

        $sql = 'CREATE INDEX CONCURRENTLY IF NOT EXISTS '
            . $this->quotePgIdentifier($indexName)
            . ' ON '
            . $this->quotePgIdentifier($table)
            . ' ('
            . $columnSql
            . ')';

        if ($where) {
            $sql .= ' WHERE ' . $where;
        }

        DB::statement($sql);
    }

    private function quotePgIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}
