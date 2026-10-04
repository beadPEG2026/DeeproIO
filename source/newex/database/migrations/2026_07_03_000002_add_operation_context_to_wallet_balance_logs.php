<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('wallet_balance_logs')) {
            return;
        }

        Schema::table('wallet_balance_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('wallet_balance_logs', 'operation')) {
                $table->string('operation')->nullable()->index()->after('balance_after');
            }

            if (!Schema::hasColumn('wallet_balance_logs', 'actor_user_id')) {
                $table->unsignedBigInteger('actor_user_id')->nullable()->index()->after('operation');
            }

            if (!Schema::hasColumn('wallet_balance_logs', 'actor_email')) {
                $table->string('actor_email')->nullable()->after('actor_user_id');
            }

            if (!Schema::hasColumn('wallet_balance_logs', 'route_name')) {
                $table->string('route_name')->nullable()->index()->after('actor_email');
            }

            if (!Schema::hasColumn('wallet_balance_logs', 'request_method')) {
                $table->string('request_method', 16)->nullable()->after('route_name');
            }

            if (!Schema::hasColumn('wallet_balance_logs', 'request_path')) {
                $table->string('request_path', 500)->nullable()->after('request_method');
            }

            if (!Schema::hasColumn('wallet_balance_logs', 'ip_address')) {
                $table->string('ip_address', 64)->nullable()->after('request_path');
            }
        });

        $this->recreatePgsqlTrigger();
    }

    public function down(): void
    {
        if (!Schema::hasTable('wallet_balance_logs')) {
            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS wallet_balance_logs_after_update ON wallets;
DROP FUNCTION IF EXISTS wallet_balance_logs_capture();
SQL);
        }

        Schema::table('wallet_balance_logs', function (Blueprint $table) {
            foreach ([
                'operation',
                'actor_user_id',
                'actor_email',
                'route_name',
                'request_method',
                'request_path',
                'ip_address',
            ] as $column) {
                if (Schema::hasColumn('wallet_balance_logs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function recreatePgsqlTrigger(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $walletBalanceFields = [
            ['field' => 'balance_in_wallet', 'label' => '资金账户'],
            ['field' => 'balance_in_trade', 'label' => '交易账户'],
            ['field' => 'balance_in_order', 'label' => '订单冻结'],
            ['field' => 'balance_in_withdraw', 'label' => '提现冻结'],
            ['field' => 'balance_in_lc', 'label' => '理财账户'],
            ['field' => 'balance_in_virtual_wallet', 'label' => '虚拟资金账户'],
            ['field' => 'balance_in_virtual_trade', 'label' => '虚拟交易账户'],
            ['field' => 'balance_in_virtual_order', 'label' => '虚拟订单冻结'],
            ['field' => 'balance_in_virtual_withdraw', 'label' => '虚拟提现冻结'],
        ];

        $balanceValues = collect($walletBalanceFields)
            ->filter(function ($item) {
                return Schema::hasColumn('wallets', $item['field']);
            })
            ->map(function ($item) {
                return sprintf(
                    "('%s', '%s', COALESCE(OLD.%s, 0), COALESCE(NEW.%s, 0))",
                    $item['field'],
                    $item['label'],
                    $item['field'],
                    $item['field']
                );
            })
            ->implode(",\n            ");

        if ($balanceValues === '') {
            return;
        }

        DB::unprepared(<<<SQL
CREATE OR REPLACE FUNCTION wallet_balance_logs_capture()
RETURNS trigger AS $$
DECLARE
    balance_row record;
    delta_value numeric;
BEGIN
    FOR balance_row IN
        SELECT * FROM (VALUES
            {$balanceValues}
        ) AS wallet_balances(account_field, account_label, old_value, new_value)
    LOOP
        delta_value := balance_row.new_value - balance_row.old_value;

        IF delta_value <> 0 THEN
            INSERT INTO wallet_balance_logs (
                wallet_id,
                user_id,
                currency_id,
                account_field,
                account_label,
                change_type,
                amount,
                balance_before,
                balance_after,
                operation,
                actor_user_id,
                actor_email,
                route_name,
                request_method,
                request_path,
                ip_address,
                source,
                source_id,
                remark,
                created_at,
                updated_at
            ) VALUES (
                NEW.id,
                NEW.user_id,
                NEW.currency_id,
                balance_row.account_field,
                balance_row.account_label,
                CASE WHEN delta_value > 0 THEN 'increase' ELSE 'decrease' END,
                delta_value,
                balance_row.old_value,
                balance_row.new_value,
                COALESCE(NULLIF(current_setting('app.wallet_balance_operation', true), ''), NULLIF(current_setting('app.wallet_balance_route', true), ''), '未设置操作上下文'),
                NULLIF(current_setting('app.wallet_balance_actor_id', true), '')::bigint,
                NULLIF(current_setting('app.wallet_balance_actor_email', true), ''),
                NULLIF(current_setting('app.wallet_balance_route', true), ''),
                NULLIF(current_setting('app.wallet_balance_request_method', true), ''),
                NULLIF(current_setting('app.wallet_balance_request_path', true), ''),
                NULLIF(current_setting('app.wallet_balance_ip', true), ''),
                COALESCE(NULLIF(current_setting('app.wallet_balance_source', true), ''), 'wallets_trigger'),
                NULLIF(current_setting('app.wallet_balance_source_id', true), ''),
                NULLIF(current_setting('app.wallet_balance_remark', true), ''),
                NOW(),
                NOW()
            );
        END IF;
    END LOOP;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS wallet_balance_logs_after_update ON wallets;

CREATE TRIGGER wallet_balance_logs_after_update
AFTER UPDATE ON wallets
FOR EACH ROW
EXECUTE FUNCTION wallet_balance_logs_capture();
SQL);
    }
};
