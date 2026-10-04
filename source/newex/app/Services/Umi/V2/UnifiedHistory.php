<?php

namespace App\Services\Umi\V2;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Read the original ledger in place; never reinterpret it as new funded income. */
final class UnifiedHistory
{
    private function snapshot(object $account, int $page = 1): array
    {
        $prefix = 'account:' . $account->id . ':';
        $balances = DB::table('umi_business_balances')->where('bucket', 'like', $prefix . '%')
            ->orderBy('asset')->orderBy('bucket')->get()->map(fn ($row) => [
                'pocket' => substr($row->bucket, strlen($prefix)), 'asset' => $row->asset,
                'amount' => (string) $row->amount,
            ])->all();
        $entries = DB::table('umi_business_entries')->where('bucket', 'like', $prefix . '%')
            ->orderByDesc('id')->paginate(40,
                ['id', 'asset', 'delta', 'balance_after', 'description', 'created_at'],
                'history_entries_page', $page)->withQueryString();
        return ['code' => $account->code, 'level' => $account->level,
            'quota_total' => (string) $account->quota_total, 'quota_used' => (string) $account->quota_used,
            'balances' => $balances,
            'entries' => $entries->items(),
            'entry_pagination' => ['total' => $entries->total(), 'page' => $entries->currentPage(),
                'prev' => $entries->previousPageUrl(), 'next' => $entries->nextPageUrl()]];
    }

    public function member(int $userId, int $page = 1): ?array
    {
        if (!Schema::hasTable('umi_business_accounts')) { return null; }
        $account = DB::table('umi_business_accounts')->where('user_id', $userId)
            ->where('fixture', false)->first();
        return $account ? $this->snapshot($account, $page) : null;
    }

    public function admin(string $search, int $page, ?int $selected, int $entryPage = 1): array
    {
        if (!Schema::hasTable('umi_business_accounts')) { return ['accounts' => null, 'selected' => null]; }
        $accounts = DB::table('umi_business_accounts')->where('fixture', false)
            ->when($search !== '', function ($q) use ($search): void {
                $q->where(function ($q) use ($search): void {
                    $q->where('code', 'like', '%' . $search . '%');
                    if (ctype_digit($search)) { $q->orWhere('id', (int) $search)
                        ->orWhere('legacy_id', (int) $search)->orWhere('user_id', (int) $search); }
                });
            })->orderBy('id')->paginate(25,
                ['id', 'legacy_id', 'user_id', 'code', 'parent_id', 'level', 'quota_total', 'quota_used'],
                'history_page', $page)->withQueryString();
        $account = $selected ? DB::table('umi_business_accounts')->where('id', $selected)
            ->where('fixture', false)->first() : null;
        return ['accounts' => $accounts, 'selected' => $account ? $this->snapshot($account, $entryPage) : null,
            'entry_total' => DB::table('umi_business_entries')->count()];
    }
}
