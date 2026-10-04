<?php

namespace App\Services\Umi\V2;

use App\Domain\Umi\V2\Decimal;
use App\Events\WalletUpdated;
use App\Models\Wallet\Wallet;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Audited HK08379 internal share ledger; point vesting never edits Deepro spot wallets. */
final class StockShares
{
    public function audit(): array
    {
        $issued = '0'; $writtenOff = '0'; $exported = '0'; $held = '0';
        $totals = DB::table('umi_v2_stock_share_moves')->selectRaw(
            "COALESCE(SUM(CASE WHEN kind = 'entitlement' THEN shares ELSE 0 END), 0) AS issued,
             COALESCE(SUM(CASE WHEN kind = 'write_off' THEN shares ELSE 0 END), 0) AS written_off,
             COALESCE(SUM(CASE WHEN kind = 'exchange_transfer' THEN shares ELSE 0 END), 0) AS exported"
        )->first();
        $issued = (string) $totals->issued;
        $writtenOff = (string) $totals->written_off;
        $exported = (string) $totals->exported;
        $held = (string) DB::table('umi_v2_stock_share_accounts')->selectRaw(
            'COALESCE(SUM(locked_shares + available_shares), 0) AS held')->value('held');
        return ['ok' => Decimal::cmp(Decimal::sub($issued, $writtenOff),
                Decimal::add($held, $exported)) === 0,
            'issued_shares' => Decimal::display($issued), 'written_off_shares' => Decimal::display($writtenOff),
            'exported_shares' => Decimal::display($exported), 'held_shares' => Decimal::display($held)];
    }

    public function settleDue(): array
    {
        FundedRuntime::requireEnabled();
        $confirmed = 0; $unlocked = 0;
        foreach (DB::table('umi_v2_live_point_terms')
            ->where(function ($q): void {
                $q->where(fn ($q) => $q->where('status', 'points_only')
                    ->where('eligible_at', '<=', FundedTime::database(now())))
                    ->orWhere(fn ($q) => $q->where('status', 'locked')
                        ->where('unlock_at', '<=', FundedTime::database(now())));
            })->orderBy('point_entry_id')->limit(200)->pluck('point_entry_id') as $id) {
            $state = DB::transaction(function () use ($id): ?string {
                $term = DB::table('umi_v2_live_point_terms')->where('point_entry_id', $id)
                    ->lockForUpdate()->first();
                if (!$term || !in_array($term->status, ['points_only', 'locked'], true)
                    || ($term->status === 'points_only' && \Carbon\CarbonImmutable::parse($term->eligible_at)->gt(now()))
                    || ($term->status === 'locked' && \Carbon\CarbonImmutable::parse($term->unlock_at)->gt(now()))) { return null; }
                $point = DB::table('umi_v2_stock_point_entries')->find($id);
                if (!$point || !in_array($point->kind, ['burn_credit'], true)) {
                    throw new DomainException('股票积分记录异常，停止确权。');
                }
                $memberId = (int) $point->member_id;
                $shares = Decimal::amount((string) $point->delta_points, true);
                $account = $this->account($memberId);
                $now = now('UTC')->startOfSecond();
                if ($term->status === 'points_only') {
                    DB::table('umi_v2_stock_share_accounts')->where('member_id', $memberId)->update([
                        'locked_shares' => Decimal::add((string) $account->locked_shares, $shares),
                        'updated_at' => FundedTime::database($now),
                    ]);
                    $this->record('entitlement:' . $id, 'entitlement', null, $memberId,
                        $id, $shares, null, '积分满 60 天转为股票余额，自确权起锁定 90 天');
                    DB::table('umi_v2_live_point_terms')->where('point_entry_id', $id)->update([
                        'status' => 'locked', 'confirmed_at' => FundedTime::database($now),
                        'unlock_at' => FundedTime::database($now->copy()->addDays(90)),
                        'updated_at' => FundedTime::database($now),
                    ]);
                    return 'confirmed';
                }
                if ($term->unlock_at && \Carbon\CarbonImmutable::parse($term->unlock_at)->lte($now)) {
                    if (Decimal::cmp((string) $account->locked_shares, $shares) < 0) {
                        throw new DomainException('股票锁定份额不足，停止解锁。');
                    }
                    DB::table('umi_v2_stock_share_accounts')->where('member_id', $memberId)->update([
                        'locked_shares' => Decimal::sub((string) $account->locked_shares, $shares),
                        'available_shares' => Decimal::add((string) $account->available_shares, $shares),
                        'updated_at' => FundedTime::database($now),
                    ]);
                    $this->record('unlock:' . $id, 'unlock', $memberId, $memberId,
                        $id, $shares, null, '锁定期结束，可站内划转');
                    DB::table('umi_v2_live_point_terms')->where('point_entry_id', $id)->update([
                        'status' => 'tradable', 'tradable_at' => FundedTime::database($now), 'updated_at' => FundedTime::database($now),
                    ]);
                    return 'unlocked';
                }
                return $term->status === 'points_only' ? 'confirmed' : null;
            }, 3);
            if ($state === 'confirmed') { $confirmed++; }
            if ($state === 'unlocked') { $unlocked++; }
        }
        return ['confirmed' => $confirmed, 'unlocked' => $unlocked];
    }

    public function transfer(int $from, int $to, string $amount, string $key,
        int $actor, string $reason): object
    {
        if ($from === $to) { throw new DomainException('收付成员不能相同。'); }
        return $this->move('transfer', $from, $to, $amount, $key, $actor, $reason);
    }

    public function writeOff(int $from, string $amount, string $key,
        int $actor, string $reason): object
    {
        return $this->move('write_off', $from, null, $amount, $key, $actor, $reason);
    }

    /** Move existing HK08379 station units from the configured pool into a member's trade wallet. */
    public function toTradingWallet(int $userId, string $amount, string $key): object
    {
        FundedRuntime::requireEnabled();
        $amount = Decimal::amount($amount, true);
        return DB::transaction(function () use ($userId, $amount, $key): object {
            $member = DB::table('umi_v2_members')->where('user_id', $userId)->first();
            if (!$member || $member->status !== 'active') {
                throw new DomainException('UMI 账户暂不可用。');
            }
            // The same lock precedes replay lookup and all stock exports, so two
            // simultaneous retries return the same receipt instead of a unique-key error.
            $settings = DB::table('umi_v2_live_settings')->where('id', 1)->lockForUpdate()->first();
            $prior = DB::table('umi_v2_stock_share_moves')->where('request_key', $key)->first();
            if ($prior) {
                if ($prior->kind !== 'exchange_transfer'
                    || (int) $prior->from_member_id !== (int) $member->id
                    || (int) $prior->exchange_user_id !== $userId
                    || Decimal::cmp((string) $prior->shares, $amount) !== 0) {
                    throw new DomainException('操作编号已用于其他股票记录。');
                }
                return $prior;
            }
            app(\App\Services\Deposit\DepositRisk::class)->assertClear($userId);
            if (!$settings || !$settings->stock_transfer_enabled || !$settings->pool_user_id
                || (int) $settings->pool_user_id === $userId) {
                throw new DomainException('股票划转暂未开放。');
            }
            app(\App\Services\Deposit\DepositRisk::class)->assertClear((int)$settings->pool_user_id);
            $asset = $this->tradingAsset();
            $precision = min(8, (int) $asset['currency']->decimals,
                (int) $asset['market']->base_precision);
            if (Decimal::cmp($amount, bcadd($amount, '0', $precision)) !== 0) {
                throw new DomainException('股票划转数量精度不正确。');
            }
            $users = DB::table('users')->whereIn('id', [$userId, $settings->pool_user_id])
                ->get()->keyBy('id');
            foreach ([$userId, (int) $settings->pool_user_id] as $id) {
                $user = $users->get($id);
                if (!$user || (bool) ($user->deleted ?? false)
                    || (bool) ($user->deactivated ?? false) || (bool) ($user->is_xn ?? false)
                    || (bool) ($user->is_xm ?? false)) {
                    throw new DomainException('股票划转账户暂不可用。');
                }
            }
            $walletRows = DB::table('wallets')->where('currency_id', $asset['currency']->id)
                ->whereIn('user_id', [$userId, $settings->pool_user_id])
                ->orderBy('id')->lockForUpdate()->get();
            if ($walletRows->count() !== 2) {
                throw new DomainException('股票交易账户尚未准备好。');
            }
            $wallets = $walletRows->keyBy('user_id');
            $source = $wallets->get($settings->pool_user_id);
            $destination = $wallets->get($userId);
            if (!$source || !$destination || count($wallets) !== 2) {
                throw new DomainException('股票交易账户尚未准备好。');
            }
            foreach ([$source, $destination] as $wallet) {
                foreach (['balance_in_virtual_trade', 'balance_in_virtual_order'] as $field) {
                    if (Decimal::cmp((string) ($wallet->{$field} ?? '0'), '0') > 0) {
                        throw new DomainException('当前账户类型不支持股票划转，请联系运营核对。');
                    }
                }
            }
            $account = $this->account((int) $member->id);
            if (Decimal::cmp((string) $account->available_shares, $amount) < 0) {
                throw new DomainException('可划转股票份额不足。');
            }
            if (Decimal::cmp((string) $source->balance_in_trade, $amount) < 0) {
                throw new DomainException('股票资金池份额不足。');
            }
            $poolAfter = Decimal::sub((string) $source->balance_in_trade, $amount);
            $walletAfter = Decimal::add((string) $destination->balance_in_trade, $amount);
            DB::table('umi_v2_stock_share_accounts')->where('member_id', $member->id)
                ->update(['available_shares' => Decimal::sub((string) $account->available_shares,
                    $amount), 'updated_at' => FundedTime::database(now())]);
            DB::table('wallets')->where('id', $source->id)
                ->update(['balance_in_trade' => $poolAfter, 'updated_at' => FundedTime::database(now())]);
            DB::table('wallets')->where('id', $destination->id)
                ->update(['balance_in_trade' => $walletAfter, 'updated_at' => FundedTime::database(now())]);
            $moveId = $this->record($key, 'exchange_transfer', (int) $member->id,
                null, null, $amount, $userId, '用户转入 Deepro HK08379 交易账户', [
                    'exchange_user_id' => $userId,
                    'exchange_currency_id' => $asset['currency']->id,
                    'pool_wallet_id' => $source->id,
                    'exchange_wallet_id' => $destination->id,
                    'pool_balance_before' => $source->balance_in_trade,
                    'pool_balance_after' => $poolAfter,
                    'exchange_balance_before' => $destination->balance_in_trade,
                    'exchange_balance_after' => $walletAfter,
                ]);
            DB::afterCommit(function () use ($userId, $settings, $source, $destination): void {
                foreach ([$userId, (int) $settings->pool_user_id] as $id) {
                    try { app(\App\Services\Performance\ReadModelCacheService::class)
                        ->invalidateWallets($id); } catch (\Throwable) {}
                }
                foreach ([$source->id, $destination->id] as $walletId) {
                    try { event(new WalletUpdated(Wallet::findOrFail($walletId))); }
                    catch (\Throwable) {}
                }
            });
            return DB::table('umi_v2_stock_share_moves')->find($moveId);
        }, 3);
    }

    public function tradingWalletState(int $userId): array
    {
        $balance = '0'; $ready = false; $maximum = '0'; $reason = '股票划转暂未开放。';
        try {
            FundedRuntime::requireEnabled();
            $asset = $this->tradingAsset();
            $settings = DB::table('umi_v2_live_settings')->find(1);
            $wallet = DB::table('wallets')->where('currency_id', $asset['currency']->id)
                ->where('user_id', $userId)->first();
            $pool = $settings?->pool_user_id ? DB::table('wallets')
                ->where('currency_id', $asset['currency']->id)
                ->where('user_id', $settings->pool_user_id)->first() : null;
            $balance = (string) ($wallet?->balance_in_trade ?? '0');
            $users = DB::table('users')->whereIn('id', [$userId, $settings?->pool_user_id])
                ->get()->keyBy('id');
            $ready = (bool) ($settings?->stock_transfer_enabled && $wallet && $pool
                && (int) $settings->pool_user_id !== $userId);
            foreach ([$userId, (int) ($settings?->pool_user_id ?? 0)] as $id) {
                $user = $users->get($id);
                $ready = $ready && $user && !$user->deleted && !$user->deactivated
                    && !$user->is_xn && !($user->is_xm ?? false);
            }
            foreach ([$wallet, $pool] as $item) {
                foreach (['balance_in_virtual_trade', 'balance_in_virtual_order'] as $field) {
                    $ready = $ready && Decimal::cmp((string) ($item->{$field} ?? '0'), '0') === 0;
                }
            }
            $member = DB::table('umi_v2_members')->where('user_id', $userId)->first();
            $ready = $ready && $member && $member->status === 'active';
            $available = $member ? DB::table('umi_v2_stock_share_accounts')
                ->where('member_id', $member->id)->value('available_shares') : '0';
            $maximum = $ready ? (Decimal::cmp((string) ($available ?? '0'), (string) $pool->balance_in_trade) < 0
                ? (string) ($available ?? '0') : (string) $pool->balance_in_trade) : '0';
            $precision = min(8, (int) $asset['currency']->decimals, (int) $asset['market']->base_precision);
            $maximum = bcadd($maximum, '0', $precision);
            if ($ready && Decimal::cmp($maximum, '0') <= 0) {
                $ready = false; $reason = '当前暂无可划转份额。';
            }
        } catch (DomainException $error) { $reason = $error->getMessage(); }
        return ['enabled' => $ready, 'balance' => $balance,
            'maximum' => $maximum, 'reason' => $ready ? null : $reason,
            'market' => 'HK08379-USDT'];
    }

    public function memberMovements(int $memberId): array
    {
        return DB::table('umi_v2_stock_share_moves')
            ->where(fn ($q) => $q->where('from_member_id', $memberId)->orWhere('to_member_id', $memberId))
            ->orderByDesc('id')->limit(30)
            ->get(['id', 'kind', 'shares', 'created_at'])->all();
    }

    public static function publicReceipt(object $move): array
    {
        return ['id' => $move->id, 'kind' => $move->kind,
            'shares' => (string) $move->shares, 'created_at' => FundedTime::database($move->created_at)];
    }

    public function coverage(int $poolUserId): array
    {
        $held='0';$pending='0';
        foreach(DB::table('umi_v2_stock_share_accounts')->cursor() as $a) $held=Decimal::add($held,Decimal::add((string)$a->locked_shares,(string)$a->available_shares));
        foreach(DB::table('umi_v2_live_point_terms as t')->join('umi_v2_stock_point_entries as p','p.id','=','t.point_entry_id')->where('t.status','points_only')->cursor() as $p) $pending=Decimal::add($pending,(string)$p->delta_points);
        $owed=Decimal::add($held,$pending);$balance='0';$available=false;
        try {$asset=$this->tradingAsset();$balance=(string)(DB::table('wallets')->where('user_id',$poolUserId)->where('currency_id',$asset['currency']->id)->value('balance_in_trade')??'0');$available=true;}
        catch(DomainException) {}
        return ['asset_ready'=>$available,'held_shares'=>$held,'pending_points'=>$pending,'total_obligation'=>$owed,'pool_available'=>$balance,
            'shortfall'=>Decimal::cmp($owed,$balance)>0?Decimal::sub($owed,$balance):'0','scope'=>'internal_product_units_only'];
    }

    public function assertPoolAvailable(int $poolUserId): void
    {
        FundedRuntime::requireEnabled();
        $asset = $this->tradingAsset();
        $user = DB::table('users')->find($poolUserId);
        $wallet = DB::table('wallets')->where('user_id', $poolUserId)
            ->where('currency_id', $asset['currency']->id)->first();
        if (!$user || $user->deleted || $user->deactivated || $user->is_xn || ($user->is_xm ?? false)
            || !$wallet || Decimal::cmp((string) $wallet->balance_in_trade, '0') <= 0
            || Decimal::cmp((string) ($wallet->balance_in_virtual_trade ?? '0'), '0') > 0
            || Decimal::cmp((string) ($wallet->balance_in_virtual_order ?? '0'), '0') > 0) {
            throw new DomainException('请先为有效的真实资金池账户准备 HK08379 可用交易份额。');
        }
        if (Decimal::cmp($this->coverage($poolUserId)['shortfall'],'0')>0) {
            throw new DomainException('资金池参考份额尚未覆盖已记录的积分和持有份额，请先核对库存。');
        }
    }

    private function tradingAsset(): array
    {
        if (!config('hk-price-products.trading_enabled')
            || !config('hk-price-products.assets.HK08379.tradingEnabled')
            || config('hk-price-products.assets.HK08379.suspended')
            || !Schema::hasColumn('currencies', 'asset_reference')) {
            throw new DomainException('盈证国际交易市场暂未开放。');
        }
        $currencies = DB::table('currencies')->where('symbol', 'HK08379')
            ->whereNull('deleted_at')->get();
        if (count($currencies) !== 1) {
            throw new DomainException('盈证国际交易资产尚未准备好。');
        }
        $currency = $currencies[0];
        $reference = json_decode((string) $currency->asset_reference, true);
        $chainContracts = ['bep_contract', 'contract', 'trc_contract', 'sol_contract',
            'matic_contract', 'xlayer_contract', 'custom_contract'];
        foreach ($chainContracts as $field) {
            if ((string) ($currency->{$field} ?? '') !== '') {
                throw new DomainException('盈证国际交易资产身份不匹配。');
            }
        }
        if (!$currency->status || $currency->asset_category !== 'stock'
            || $currency->asset_unit !== 'product_unit'
            || ($reference['instrumentType'] ?? null) !== 'equity_price_reference'
            || ($reference['securityCode'] ?? null) !== '08379'
            || $currency->deposit_status || $currency->withdraw_status) {
            throw new DomainException('盈证国际交易资产身份不匹配。');
        }
        $market = DB::table('markets')->where('name', 'HK08379-USDT')->first();
        $quote = DB::table('currencies')->where('symbol', 'USDT')->whereNull('deleted_at')->first();
        if (!$market || !$quote || !$market->status || !$market->trade_status
            || (int) $market->base_currency_id !== (int) $currency->id
            || (int) $market->quote_currency_id !== (int) $quote->id) {
            throw new DomainException('盈证国际交易市场暂未开放。');
        }
        return compact('currency', 'market');
    }

    private function move(string $kind, int $from, ?int $to, string $amount,
        string $key, int $actor, string $reason): object
    {
        FundedRuntime::requireEnabled();
        $amount = Decimal::amount($amount, true);
        if (mb_strlen(trim($reason)) < 10) { throw new DomainException('请填写至少十个字的操作依据。'); }
        return DB::transaction(function () use ($kind, $from, $to, $amount, $key, $actor, $reason): object {
            DB::table('umi_v2_live_settings')->where('id', 1)->lockForUpdate()->first();
            $prior = DB::table('umi_v2_stock_share_moves')->where('request_key', $key)->first();
            if ($prior) {
                if ($prior->kind !== $kind || (int) $prior->from_member_id !== $from
                    || ($prior->to_member_id === null ? null : (int) $prior->to_member_id) !== $to
                    || Decimal::cmp((string) $prior->shares, $amount) !== 0
                    || (int) $prior->actor_id !== $actor || $prior->reason !== trim($reason)) {
                    throw new DomainException('操作编号已用于其他股票记录。');
                }
                return $prior;
            }
            foreach (array_unique(array_filter([$from, $to])) as $id) {
                if (!DB::table('umi_v2_members')->where('id', $id)->exists()) {
                    throw new DomainException('UMI 成员不存在。');
                }
                DB::table('umi_v2_stock_share_accounts')->insertOrIgnore([
                    'member_id' => $id, 'locked_shares' => '0', 'available_shares' => '0',
                    'created_at' => FundedTime::database(now()), 'updated_at' => FundedTime::database(now()),
                ]);
            }
            $accounts = DB::table('umi_v2_stock_share_accounts')
                ->whereIn('member_id', array_filter([$from, $to]))->orderBy('member_id')
                ->lockForUpdate()->get()->keyBy('member_id');
            $source = $accounts[$from];
            if (Decimal::cmp((string) $source->available_shares, $amount) < 0) {
                throw new DomainException('可划转股票份额不足。');
            }
            DB::table('umi_v2_stock_share_accounts')->where('member_id', $from)->update([
                'available_shares' => Decimal::sub((string) $source->available_shares, $amount),
                'updated_at' => FundedTime::database(now()),
            ]);
            if ($to !== null) {
                DB::table('umi_v2_stock_share_accounts')->where('member_id', $to)->update([
                    'available_shares' => Decimal::add((string) $accounts[$to]->available_shares, $amount),
                    'updated_at' => FundedTime::database(now()),
                ]);
            }
            $id = $this->record($key, $kind, $from, $to, null, $amount, $actor, trim($reason));
            return DB::table('umi_v2_stock_share_moves')->find($id);
        }, 3);
    }

    private function account(int $memberId): object
    {
        DB::table('umi_v2_stock_share_accounts')->insertOrIgnore([
            'member_id' => $memberId, 'locked_shares' => '0', 'available_shares' => '0',
            'created_at' => FundedTime::database(now()), 'updated_at' => FundedTime::database(now()),
        ]);
        return DB::table('umi_v2_stock_share_accounts')->where('member_id', $memberId)
            ->lockForUpdate()->first();
    }

    private function record(string $key, string $kind, ?int $from, ?int $to,
        ?int $pointId, string $shares, ?int $actor, string $reason, array $extra = []): int
    {
        return DB::table('umi_v2_stock_share_moves')->insertGetId([
            'request_key' => $key, 'kind' => $kind,
            'from_member_id' => $from, 'to_member_id' => $to,
            'point_entry_id' => $pointId, 'shares' => $shares,
            'actor_id' => $actor, 'reason' => $reason, 'created_at' => FundedTime::database(now()),
        ] + $extra);
    }
}
