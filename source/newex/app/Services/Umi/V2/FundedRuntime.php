<?php

namespace App\Services\Umi\V2;

use DomainException;
use Illuminate\Support\Facades\Schema;

/** Deployment readiness is independent of the operator's financial switches. */
final class FundedRuntime
{
    public static function schemaReady(): bool
    {
        foreach (['umi_v2_members', 'umi_v2_cycles', 'umi_v2_income_accounts',
            'umi_v2_live_settings', 'umi_v2_live_intents', 'umi_v2_live_point_terms',
            'umi_v2_stock_share_accounts', 'umi_v2_stock_share_moves',
            'umi_v2_pending_principals','umi_v2_account_merges','umi_v2_account_merge_batches',
            'umi_v2_rate_schedule','umi_v2_rank_audit','umi_v2_recovery_tasks','umi_v2_recovery_actions'] as $table) {
            if (!Schema::hasTable($table)) { return false; }
        }
        return Schema::hasColumn('umi_v2_live_wallet_moves','from_bucket')
            && Schema::hasColumn('umi_v2_live_wallet_moves','to_bucket')
            && Schema::hasColumn('umi_v2_live_settings','account_cutover_enabled')
            && Schema::hasColumn('umi_v2_live_point_terms','acquired_at')
            && Schema::hasColumn('umi_v2_live_settings', 'stock_transfer_enabled')
            && Schema::hasColumn('umi_v2_stock_share_moves', 'exchange_wallet_id');
    }

    public static function requireEnabled(): void
    {
        if (!config('umi-v2.funded_enabled') || !self::schemaReady()) {
            throw new DomainException('UMI 服务暂未开放，请稍后再试。');
        }
    }
}
