<?php

namespace App\Services\Umi\V2;

use App\Domain\Umi\V2\Decimal;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class FundedDashboard
{
    public function __construct(
        private readonly FundedReadiness $gate,
        private readonly FundedWallet $wallet,
    ) {}

    public function member(int $userId): array
    {
        if (!FundedRuntime::schemaReady()) {
            return ['mode' => 'funded', 'ready' => false, 'enrollment_enabled' => false,
                'member' => null, 'historical_activation' => false,
                'burn' => ['confirmed_umi' => '0', 'past_24h_umi' => '0', 'today_umi' => '0', 'pending_umi' => '0']];
        }
        $member = DB::table('umi_v2_members')->where('user_id', $userId)->first();
        $burn = $this->burnSummary();
        $historical = (Schema::hasTable('umi_legacy_accounts')
            && DB::table('umi_legacy_accounts')->where('user_id', $userId)
                ->where('activation_status', 'activated')->exists())
            || (Schema::hasTable('umi_business_accounts')
                && DB::table('umi_business_accounts')->where('user_id', $userId)
                    ->where('fixture', false)->exists());
        $readiness = $this->gate->report();
        $settings = $this->gate->settings();
        $operations = ['intake' => $readiness['ready'] && (bool) $settings->intake_enabled,
            'withdrawal' => $readiness['ready'] && (bool) $settings->withdrawal_enabled];
        $base = ['mode' => 'funded', 'ready' => $readiness['ready'], 'operations' => $operations,
            'enrollment_enabled' => (bool) config('umi-v2.funded_enabled'),
            'member' => $member, 'burn' => $burn, 'historical_activation' => $historical];
        if (!$member) { return $base; }
        $id = (int) $member->id;
        $member->invite_code = Schema::hasTable('umi_v2_invite_aliases')
            ? DB::table('umi_v2_invite_aliases')->where('member_id',$id)->whereRaw('length(code) = 6')->orderBy('created_at')->value('code') : null;
        $cycles = DB::table('umi_v2_cycles as c')
            ->join('umi_v2_live_intents as i', 'i.cycle_id', '=', 'c.id')
            ->where('c.member_id', $id)->orderByDesc('c.id')
            ->select('c.id', 'c.cycle_number', 'c.multiplier', 'c.principal_umi',
                'c.cap_umi', 'c.released_umi', 'c.status', 'c.starts_on')->get();
        $remaining = '0'; $released = '0'; $active = 0;
        foreach ($cycles as $cycle) {
            $cycle->remaining_umi = Decimal::sub((string) $cycle->cap_umi,
                (string) $cycle->released_umi);
            $released = Decimal::add($released, (string) $cycle->released_umi);
            if ($cycle->status === 'active') {
                $active++;
                $remaining = Decimal::add($remaining, $cycle->remaining_umi);
            }
        }
        $used=[]; $available=[];
        foreach (DB::table('umi_v2_income_transfer_allocations as a')->join('umi_v2_release_events as e','e.id','=','a.release_event_id')
            ->where('e.member_id',$id)->get(['a.release_event_id','a.amount_umi']) as $a)
            $used[$a->release_event_id]=Decimal::add($used[$a->release_event_id]??'0',(string)$a->amount_umi);
        $incomeSummary = ['total_umi' => '0', 'today_umi' => '0', 'by_kind' => ['static' => '0', 'team' => '0', 'referral' => '0']];
        $zone = config('umi-v2.timezone', 'Asia/Shanghai');
        $today = now($zone)->toDateString();
        foreach (DB::table('umi_v2_release_events')->where('member_id',$id)->get(['id','cycle_id','kind','released_umi','created_at']) as $event) {
            $available[$event->cycle_id][$event->kind]=Decimal::add($available[$event->cycle_id][$event->kind]??'0',Decimal::sub((string)$event->released_umi,$used[$event->id]??'0'));
            $incomeSummary['total_umi'] = Decimal::add($incomeSummary['total_umi'], (string) $event->released_umi);
            $incomeSummary['by_kind'][$event->kind] = Decimal::add($incomeSummary['by_kind'][$event->kind] ?? '0', (string) $event->released_umi);
            if (\Carbon\CarbonImmutable::parse($event->created_at, config('app.timezone'))->setTimezone($zone)->toDateString() === $today)
                $incomeSummary['today_umi'] = Decimal::add($incomeSummary['today_umi'], (string) $event->released_umi);
        }
        foreach ($cycles as $cycle) $cycle->transferable=$available[$cycle->id]??[];
        try { $wallet = $this->wallet->balance($userId, 'trade'); }
        catch (\Throwable) { $wallet = null; }
        $withdrawals = DB::table('umi_v2_withdrawals as w')
                ->leftJoin('umi_v2_live_withdrawal_funding as f', 'f.withdrawal_id', '=', 'w.id')
                ->where('w.member_id', $id)->orderByDesc('w.id')->limit(30)
                ->select('w.*', 'f.from_income_umi', 'f.from_wallet_umi',
                    'f.from_deposit_umi', 'f.remaining_umi')->get();
        $withdrawalIds = $withdrawals->pluck('id');
        $proofs = DB::table('umi_v2_live_burn_lots as l')
            ->leftJoin('umi_v2_live_burn_proofs as p', 'p.lot_id', '=', 'l.id')
            ->where('l.member_id', $id)->whereIn('l.withdrawal_id', $withdrawalIds)
            ->select('l.id', 'l.withdrawal_id', 'l.status', 'l.amount_umi', 'l.created_at',
                'l.confirmed_at', 'p.tx_hash', 'p.chain_id', 'p.finalized_at')->get()->groupBy('withdrawal_id');
        $points = DB::table('umi_v2_stock_point_entries as p')
            ->leftJoin('umi_v2_live_point_terms as t', 't.point_entry_id', '=', 'p.id')
            ->where('p.member_id', $id)->whereIn('p.withdrawal_id', $withdrawalIds)
            ->select('p.id', 'p.withdrawal_id', 'p.delta_points', 'p.created_at',
                't.status as term_status', 't.eligible_at', 't.confirmed_at', 't.unlock_at', 't.tradable_at')
            ->get()->groupBy('withdrawal_id');
        foreach ($withdrawals as $withdrawal) {
            $withdrawal->burn_records = $proofs->get($withdrawal->id, collect());
            $withdrawal->point_records = $points->get($withdrawal->id, collect());
        }
        return FundedTime::forDisplay($base + [
            'parent_code' => DB::table('umi_v2_sponsor_edges as e')
                ->join('umi_v2_members as p', 'p.id', '=', 'e.parent_member_id')
                ->where('e.child_member_id', $id)->value('p.member_code'),
            'direct_count' => DB::table('umi_v2_sponsor_edges')
                ->where('parent_member_id', $id)->count(),
            'wallet_umi' => $wallet, 'wallet_available'=>$wallet!==null,
            'income_summary' => $incomeSummary,
            'spot_quote' => $this->spotQuoteStatus(),
            'participation_tiers' => \App\Domain\Umi\V2\TierPolicy::displayTiers(),
            'stock_balance_shares'=>Decimal::add((string)(DB::table('umi_v2_stock_share_accounts')->where('member_id',$id)->value('locked_shares')??'0'),(string)(DB::table('umi_v2_stock_share_accounts')->where('member_id',$id)->value('available_shares')??'0')),
            'account_merge'=>DB::table('umi_v2_account_merges')->where('member_id',$id)->first(),
            'pending_principal_umi' => (string)(DB::table('umi_v2_pending_principals')->where('member_id',$id)->value('amount_umi')??'0'),
            'cycles' => $cycles,
            'cycle_summary' => ['active_count' => $active,
                'active_remaining_umi' => $remaining, 'total_released_umi' => $released],
            'income' => DB::table('umi_v2_income_accounts')->where('member_id', $id)->first(),
            'intents' => DB::table('umi_v2_live_intents')->where('member_id', $id)
                ->orderByDesc('id')->limit(30)->get(),
            'releases' => DB::table('umi_v2_release_events')->where('member_id', $id)
                ->orderByDesc('id')->limit(40)->get(),
            'transfers' => DB::table('umi_v2_income_transfers')->where('member_id', $id)
                ->orderByDesc('id')->limit(30)->get(),
            'withdrawals' => $withdrawals,
            'points' => DB::table('umi_v2_stock_point_entries as p')
                ->leftJoin('umi_v2_live_point_terms as t', 't.point_entry_id', '=', 'p.id')
                ->where('p.member_id', $id)->orderByDesc('p.id')->limit(30)
                ->select('p.*', 't.eligible_at', 't.unlock_at', 't.status as term_status',
                    't.confirmed_at', 't.tradable_at')->get(),
            'locked_points' => DB::table('umi_v2_stock_point_entries as p')
                ->join('umi_v2_live_point_terms as t','t.point_entry_id','=','p.id')
                ->where('p.member_id',$id)->where('t.status','locked')->orderBy('t.unlock_at')->limit(30)
                ->select('p.id','p.delta_points','t.confirmed_at','t.unlock_at')->get(),
            'pending_points' => (string) DB::table('umi_v2_stock_point_entries as p')
                ->join('umi_v2_live_point_terms as t', 't.point_entry_id', '=', 'p.id')
                ->where('p.member_id', $id)->where('t.status', 'points_only')->sum('p.delta_points'),
            'stock_account' => Schema::hasTable('umi_v2_stock_share_accounts')
                ? DB::table('umi_v2_stock_share_accounts')->where('member_id', $id)->first() : null,
            'stock_trading_wallet' => Schema::hasColumn('umi_v2_live_settings', 'stock_transfer_enabled')
                ? app(StockShares::class)->tradingWalletState($userId)
                : ['enabled' => false, 'balance' => '0', 'market' => 'HK08379-USDT'],
            'stock_moves' => app(StockShares::class)->memberMovements($id),
            'legacy_link' => Schema::hasTable('umi_v2_legacy_parent_links')
                ? DB::table('umi_v2_legacy_parent_links')->where('member_id', $id)->first() : null,
            'lots' => DB::table('umi_v2_live_burn_lots')->where('member_id', $id)
                ->orderByDesc('id')->limit(40)->get(),
        ]);
    }

    private function spotQuoteStatus(): array
    {
        try { return ['available' => true, 'quote' => app(BestAskQuote::class)->read()]; }
        catch (\DomainException $error) { return ['available' => false, 'message' => $error->getMessage()]; }
    }

    public function admin(string $search = ''): array
    {
        if (!FundedRuntime::schemaReady()) {
            throw new \DomainException('UMI 运营服务正在准备中。');
        }
        $settings = $this->gate->settings();
        $search = mb_substr(trim($search), 0, 60);
        $members = DB::table('umi_v2_members as m')
            ->leftJoin('umi_v2_legacy_parent_links as l', 'l.member_id', '=', 'm.id')
            ->when($search !== '', function ($q) use ($search): void {
                $q->where(function ($q) use ($search): void {
                    $q->where('m.member_code', 'like', '%' . $search . '%')
                        ->orWhere('m.legacy_identity_ref', 'like', '%' . $search . '%');
                    if (ctype_digit($search)) { $q->orWhere('m.user_id', (int) $search); }
                });
            })->orderByDesc('m.id')->limit(100)
            ->select('m.*', 'l.parent_legacy_id', 'l.status as legacy_link_status')->get();
        $legacyCandidates = Schema::hasTable('umi_legacy_accounts')
            ? DB::table('umi_legacy_accounts as a')
                ->leftJoin('umi_v2_members as m', 'm.user_id', '=', 'a.user_id')
                ->where('a.activation_status', 'activated')->whereNotNull('a.user_id')
                ->whereNull('m.id')->orderBy('a.legacy_id')->limit(50)
                ->select('a.legacy_id', 'a.user_id', 'a.parent_legacy_id')->get() : [];
        if (Schema::hasTable('umi_business_accounts')) {
            $businessCandidates = DB::table('umi_business_accounts as a')
                ->leftJoin('umi_v2_members as m', 'm.user_id', '=', 'a.user_id')
                ->whereNotNull('a.user_id')->where('a.fixture', false)->whereNull('m.id')
                ->when(Schema::hasTable('umi_legacy_accounts'), function ($q): void {
                    $q->leftJoin('umi_legacy_accounts as l', 'l.user_id', '=', 'a.user_id')
                        ->whereNull('l.legacy_id');
                })->orderBy('a.id')->limit(50)
                ->select('a.legacy_id', 'a.user_id', 'a.legacy_parent_id as parent_legacy_id')->get();
            $legacyCandidates = collect($legacyCandidates)->concat($businessCandidates)->take(50)->values();
        }
        return FundedTime::forDisplay(['mode' => 'funded', 'readiness' => $this->gate->report(),
            'settings' => $settings, 'settings_revision'=>app(FundedConfiguration::class)->revision($settings),
            'settlement_calendar'=>app(FundedCalendar::class)->status(), 'recovery'=>app(FundedRecovery::class)->overview(), 'spot_quote' => $this->spotQuoteStatus(),
            'burn_address' => FundedReadiness::DEAD,
            'terminal_supply_umi' => '21000000',
            'settings_audit' => DB::table('umi_v2_live_settings_audit')
                ->orderByDesc('id')->limit(20)->get(),
            'quotes' => DB::table('umi_v2_live_quotes')
                ->orderByDesc('id')->limit(20)->get(),
            'members' => $members, 'member_search' => $search,
            'member_total' => DB::table('umi_v2_members')->count(),
            'active_cycle_total' => DB::table('umi_v2_cycles as c')
                ->join('umi_v2_live_intents as i', 'i.cycle_id', '=', 'c.id')
                ->where('c.status', 'active')->count(),
            'legacy_total' => Schema::hasTable('umi_legacy_accounts')
                ? DB::table('umi_legacy_accounts')->count() : 0,
            'legacy_pending' => Schema::hasTable('umi_legacy_accounts')
                ? DB::table('umi_legacy_accounts')->whereNull('user_id')->count() : 0,
            'legacy_bound' => Schema::hasTable('umi_legacy_accounts')
                ? DB::table('umi_legacy_accounts')->whereNotNull('user_id')->count() : 0,
            'legacy_pending_sample' => Schema::hasTable('umi_legacy_accounts')
                ? DB::table('umi_legacy_accounts')->whereNull('user_id')
                    ->orderBy('legacy_id')->limit(30)
                    ->get(['legacy_id', 'parent_legacy_id', 'level', 'activation_status']) : [],
            'legacy_candidates' => $legacyCandidates,
            'activation_audit' => Schema::hasTable('umi_v2_member_activation_audit')
                ? DB::table('umi_v2_member_activation_audit')->orderByDesc('id')->limit(30)->get() : [],
            'stock_accounts' => Schema::hasTable('umi_v2_stock_share_accounts')
                ? DB::table('umi_v2_stock_share_accounts')->orderByDesc('member_id')->limit(60)->get() : [],
            'stock_terms' => DB::table('umi_v2_live_point_terms as t')
                ->join('umi_v2_stock_point_entries as p', 'p.id', '=', 't.point_entry_id')
                ->orderByDesc('t.point_entry_id')->limit(60)
                ->select('t.*', 'p.member_id', 'p.delta_points')->get(),
            'stock_moves' => Schema::hasTable('umi_v2_stock_share_moves')
                ? DB::table('umi_v2_stock_share_moves')->orderByDesc('id')->limit(60)->get() : [],
            'stock_coverage'=>app(StockShares::class)->coverage((int)$settings->pool_user_id),
            'stock_audit' => Schema::hasTable('umi_v2_stock_share_moves')
                ? app(StockShares::class)->audit() : null,
            'intents' => DB::table('umi_v2_live_intents')->orderByDesc('id')->limit(60)->get(),
            'cycles' => DB::table('umi_v2_cycles as c')
                ->join('umi_v2_live_intents as i', 'i.cycle_id', '=', 'c.id')
                ->orderByDesc('c.id')->limit(60)->select('c.*')->get(),
            'withdrawals' => DB::table('umi_v2_withdrawals')->orderByDesc('id')->limit(60)->get(),
            'lots' => DB::table('umi_v2_live_burn_lots')->orderByDesc('id')->limit(80)->get(),
            'daily_runs' => DB::table('umi_v2_live_daily_runs')
                ->orderByDesc('business_date')->limit(30)->get(),
            'burn' => $this->burnSummary()]);
    }

    public function burnSummary(): array
    {
        $cutoff = now()->subHours(24);
        $dayStart = now(config('umi-v2.timezone'))->startOfDay();
        $start = $dayStart->copy()->setTimezone(config('app.timezone'));
        $end = $dayStart->copy()->addDay()->setTimezone(config('app.timezone'));
        $row = DB::table('umi_v2_live_burn_lots')->selectRaw(
            "COALESCE(SUM(CASE WHEN status = 'confirmed' THEN amount_umi ELSE 0 END), 0) AS confirmed,
             COALESCE(SUM(CASE WHEN status IN ('pending','custody','review') THEN amount_umi ELSE 0 END), 0) AS pending,
             COALESCE(SUM(CASE WHEN status = 'confirmed' AND confirmed_at > ? THEN amount_umi ELSE 0 END), 0) AS past24,
             COALESCE(SUM(CASE WHEN status = 'confirmed' AND confirmed_at >= ? AND confirmed_at < ? THEN amount_umi ELSE 0 END), 0) AS today",
            [FundedTime::database($cutoff), FundedTime::database($start), FundedTime::database($end)])->first();
        return ['confirmed_umi' => (string) $row->confirmed, 'past_24h_umi' => (string) $row->past24,
            'today_umi' => (string) $row->today, 'pending_umi' => (string) $row->pending];
    }
}
