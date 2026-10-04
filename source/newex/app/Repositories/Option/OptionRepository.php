<?php

namespace App\Repositories\Option;

use App\Events\WalletUpdated;
use App\Models\Market\Market;
use App\Models\Option\Option;
use App\Models\Option\OptionTemplate;
use App\Models\Option\OptionTemplateUser;
use App\Models\User\User;
use App\Models\Wallet\Wallet;
use App\Repositories\Market\MarketRepository;
use App\Repositories\Wallet\WalletRepository;
use App\Services\Wallet\WalletService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Setting;

class OptionRepository
{
    public
        $order,
        $market,
        $walletService,
        $walletRepository,
        $orderService,
        $marketRepository,
        $user,
        $type;

    public function __construct()
    {
        $this->walletService = new WalletService();

        $this->marketRepository = new MarketRepository();
        $this->walletRepository = new WalletRepository();
    }

    public function get($market)
    {
        $market = Market::whereName($market)->value('id');

        $orders = Option::whereMarketId($market);

        return $orders->get();
    }

    public function findById($uuid)
    {
        return Option::find($uuid);
    }

    public function insert($insert)
    {
        Option::insert($insert);

        return $this->findById($this->uuid);
    }

    public function store()
    {
        return app(\App\Services\Order\IdempotentOrderRequest::class)->run('options', fn() => $this->storeInternal());
    }

    private function storeInternal()
    {
        app(\App\Services\Deposit\DepositRisk::class)->assertClear((int)auth()->id());
        return DB::transaction(function () {
            $market = $this->marketRepository->get(request()->get('market'));

            if (!$market) {
                throw new \Exception('Market not found');
            }

            app(\App\Services\Order\ProductTradingAvailability::class)->check($market, 'options', (string)request()->get('side'));
            $authUser = auth()->user();

            if (!$authUser) {
                throw new \Exception('Unauthorized');
            }

            $user = $authUser->getAuthIdentifier();

            $amount = $this->safeDecimal(request()->get('quantity'));
            $type = request()->get('type');
            $side = request()->get('side');

            if ($this->safeCompare($amount, 0) <= 0) {
                throw new \Exception('Invalid amount');
            }

            $useVirtualWallet = (bool) ($authUser->is_xn || $authUser->is_xm);
            $openingQuote = $useVirtualWallet ? ['price' => market_get_stats($market->id, 'last'), 'source' => 'simulation'] : app(\App\Services\Option\OptionPrice::class)->quote($market);
            $marketPrice = $openingQuote['price'];

            $templateId = null;
            $template = $useVirtualWallet ? $this->getOptionsTemplateByFilter($market->id, $side, $type, $amount) : null;

            $isException = false;
            $isUserUsed = false;

            if ($template) {
                $isException = $template->action == 'profit' ? 'won' : 'lost';
                $templateId = $template->id;

                $isUserUsed = $this->isTemplateUsed($template->id, $user);

                if ($isUserUsed) {
                    $isException = null;
                } else {
                    $this->storeTemplateUsed($template->id, $user);
                }
            }

            $pnlSelected = setting('trade.options_pnl');
            $pnl = math_percentage($amount, $pnlSelected);

            $feeRate = Setting::get('futures.taker_fee', INITIAL_FUTURES_TAKER_FEE);
            $originalFee = math_percentage($amount, $feeRate);
            $totalDeductAmount = math_sum($amount, $originalFee);

            $requestedTimeframe = request()->get('timeframeSeconds');
            $requestedStartAt = request()->get('startAt');
            $serverNow = Carbon::now();

            if ($requestedStartAt !== null && $requestedStartAt !== '') {
                $startMs = (int) $requestedStartAt;

                if ($startMs > 0) {
                    $frontendStartAt = Carbon::createFromTimestampMs($startMs);

                    if ($frontendStartAt->lt($serverNow)) {
                        throw new \Exception('Selected time cannot be earlier than server time');
                    }
                }
            }

            if (!empty($requestedTimeframe)) {
                $timeframeSeconds = intval($requestedTimeframe);
            } else {
                $typesMap = config('app.options_types');
                $timeframeSeconds = $typesMap[$type] ?? 60;
            }

            if (!$timeframeSeconds || $timeframeSeconds <= 0) {
                $timeframeSeconds = 60;
            }

            $startAt = $serverNow->copy();
            $endAt = $startAt->copy()->addSeconds((int) $timeframeSeconds);
            $status = 'active';

            $vip = intval($authUser->vip ?? 0);
            $discount = $this->getVipFeeDiscount($vip);
            $refundRate = $this->getRefundRateByDiscount($discount);
            $refundAmount = '0';

            if ($this->safeCompare($originalFee, 0) > 0 && $this->safeCompare($refundRate, 0, 8) > 0) {
                $refundAmount = $this->safeDecimal(
                    math_formatter(math_percentage($originalFee, $refundRate), 8)
                );
            }

            $wallet = $this->getOptionWalletByCurrency($user, $market->quote_currency_id);

            if (!$wallet) {
                throw new \Exception('Wallet not found');
            }

            $virtualBalance = $this->getOptionVirtualBalance($wallet);
            $useVirtualWallet = (bool) ($authUser->is_xn || $authUser->is_xm);

            if ($useVirtualWallet && $this->safeCompare($virtualBalance, $totalDeductAmount) < 0) {
                throw new \Exception(
                    'Insufficient virtual options balance. Available: ' . $virtualBalance .
                    ', Required: ' . $totalDeductAmount .
                    ', Wallet ID: ' . $wallet->id
                );
            }

            if (!$useVirtualWallet) {
                $tradeBalance = $this->safeDecimal($wallet->balance_in_trade ?? 0);

                if ($this->safeCompare($tradeBalance, $totalDeductAmount) < 0) {
                    throw new \Exception('Insufficient balance');
                }
            }

            $option = new Option();
            $option->market_id = $market->id;
            $option->user_id = $user;
            $option->currency_id = $market->quote_currency_id;
            $option->amount = $amount;
            $option->price = $marketPrice;
            $option->opening_source = $openingQuote['source'];
            $option->pnl = $pnl;
            $option->period = $type;
            $option->type = $side;
            $option->template_id = $templateId;
            $option->status = $status;
            $option->uuid = generate_uuid();
            $option->is_exception = $useVirtualWallet ? $isException : null;
            $option->funding_domain = $useVirtualWallet ? 'virtual' : 'real';
            $option->funding_wallet_id = $wallet->id;
            $option->timeframe_seconds = $timeframeSeconds;
            $option->fee_rate = $feeRate;
            $option->fee = $originalFee;
            $option->fee_refund_rate = $refundRate;
            $option->fee_refund_amount = $refundAmount;

            if (Schema::hasColumn($option->getTable(), 'start_at')) {
                $option->start_at = $startAt;
            }

            if (Schema::hasColumn($option->getTable(), 'end_at')) {
                $option->end_at = $endAt;
            }

            $option->created_at = $startAt;
            $option->updated_at = Carbon::now();

            $option->save();

            if ($useVirtualWallet) {
                $this->decreaseOptionVirtualBalance($wallet, $totalDeductAmount);
            } else {
                $this->walletService->decrease($wallet, $totalDeductAmount, 'trade');
            }

            if ($this->safeCompare($refundAmount, 0) > 0) {
                if ($useVirtualWallet) {
                    $this->increaseWalletField($wallet, 'balance_in_virtual_wallet', $refundAmount);
                } else {
                    $this->walletService->increase($wallet, $refundAmount, 'trade');
                }

                $this->createOptionFeeRefundRecord(
                    $authUser,
                    $wallet,
                    $option,
                    $originalFee,
                    $vip,
                    $discount,
                    $refundRate,
                    $refundAmount
                );
            }

            DB::afterCommit(fn()=>event(new WalletUpdated($wallet)));

            return $option->uuid;
        });
    }

    public function storeTemplate($insert)
    {
        return OptionTemplate::insert($insert);
    }

    public function updateTemplate($id, $data)
    {
        $template = OptionTemplate::find($id);
        $template->update($data);

        return $template->fresh();
    }

    public function deleteTemplate($id)
    {
        $template = OptionTemplate::find($id);
        $template->delete();

        return true;
    }

    public function getOptionTemplateId($id)
    {
        return OptionTemplate::find($id);
    }

    public function open($market = false)
    {
        $orders = Option::query()
            ->whereIn('status', ['active', 'scheduled', 'review_required'])
            ->where('user_id', auth()->id());

        if ($market) {
            $market = Market::whereName($market)->value('id');
            $orders->whereMarketId($market);
        }

        $orders->has('market');

        return $orders->get();
    }

    public function trades($market = false)
    {
        $orders = Option::processed()->where('user_id', auth()->id());

        if ($market) {
            $market = Market::whereName($market)->value('id');
            $orders->whereMarketId($market);
        }

        $orders->limit(10);

        $orders->orderBy('created_at', 'desc');

        $orders->has('market');

        return $orders->get();
    }

    public function getReport()
    {
        $q = Option::query();

        $q->filter(request()->only(['search', 'referrer']))->orderByLatest();
        $q->has('market')->has('user')->has('currency');
        $q->with(['market', 'currency', 'user']);

        $referrer = request()->get('referrer');
        $scopeUserIds = $this->resolveScopeUserIds($referrer ? (int) $referrer : null);

        if (is_array($scopeUserIds)) {
            if (empty($scopeUserIds)) {
                return $q->whereRaw('1 = 0')->paginate(50)->withQueryString();
            }

            $q->whereIn('user_id', $scopeUserIds);
        }

        return $q->paginate(50)->withQueryString();
    }

    public function getReportUser(User $user)
    {
        $transaction = Option::query();

        $transaction->filterUser(request()->only(['market', 'side']))->orderByLatest();

        $transaction->has('market')->has('user')->has('currency');

        $transaction->with(['market', 'currency', 'user']);

        if ($this->canAccessUser((int) $user->id)) {
            $transaction->where('user_id', $user->id);
        } else {
            $transaction->whereRaw('1 = 0');
        }

        $transaction->whereNotIn('status', ['active', 'scheduled', 'review_required']);

        return $transaction->paginate(50)->withQueryString();
    }

    public function getOptionsTemplates()
    {
        $options = OptionTemplate::query();

        $options->with('market');

        $options->orderBy('id', 'desc');

        return $options->paginate(50)->withQueryString();
    }

    public function getOptionsTemplateByFilter($market, $type, $period, $amount)
    {
        $options = OptionTemplate::query();

        $options->where('type', $type);
        $options->where('period', $period);
        $options->where('amount', $amount);
        $options->where('market_id', $market);

        return $options->first();
    }

    public function isTemplateUsed($id, $user)
    {
        return OptionTemplateUser::where('template_id', $id)->where('user_id', $user)->first();
    }

    public function storeTemplateUsed($id, $user)
    {
        $m = new OptionTemplateUser();
        $m->template_id = $id;
        $m->user_id = $user;
        $m->save();
    }

    public function getStatReport($period)
    {
        return DB::table('options')
            ->selectRaw('markets.name as pair, currencies.symbol, SUM(options.pnl) as income, COUNT(*) as total')
            ->join('currencies', 'currencies.id', 'options.currency_id')
            ->join('markets', 'markets.id', 'options.market_id')
            ->whereNotIn('options.status', ['active', 'scheduled', 'review_required'])
            ->whereBetween('options.created_at', $period)
            ->groupByRaw('markets.name, currencies.symbol')
            ->get();
    }

    protected function getCurrentRoleIds(): array
    {
        $currentUser = auth()->user();

        if (!$currentUser) {
            return [];
        }

        $currentUser->loadMissing('roles');

        return $currentUser->roles
            ->pluck('id')
            ->map(function ($id) {
                return (int) $id;
            })
            ->toArray();
    }

    protected function getCurrentRoleNames(): array
    {
        $currentUser = auth()->user();

        if (!$currentUser) {
            return [];
        }

        $currentUser->loadMissing('roles');

        return $currentUser->roles
            ->pluck('name')
            ->filter()
            ->values()
            ->toArray();
    }

    protected function isSuperAdmin(): bool
    {
        $roleNames = $this->getCurrentRoleNames();

        if (in_array('superadmin', $roleNames, true)) {
            return true;
        }

        return (auth()->user()?->hasRole('superadmin') ?? false);
    }

    protected function hasTeamDataScope(): bool
    {
        $roleNames = $this->getCurrentRoleNames();

        $teamScopeRoles = [
            'admin',
            'user_leader',
            'salesman',
            'user_editor',
            'perm_users',
        ];

        if (count(array_intersect($roleNames, $teamScopeRoles)) > 0) {
            return true;
        }

        return (auth()->user()?->hasRole('admin') ?? false);
    }

    /**
     * 返回值说明：
     * null = 超级管理员看全部，不加 whereIn
     * [] = 没有权限
     * array = 允许查看的团队用户ID
     */
    protected function resolveScopeUserIds(?int $teamUserId = null)
    {
        $currentUser = auth()->user();

        if (!$currentUser) {
            return [];
        }

        if ($this->isSuperAdmin()) {
            if ($teamUserId) {
                return $this->getAllTeamUserIds($teamUserId);
            }

            return null;
        }

        if ($this->hasTeamDataScope()) {
            $myTeamUserIds = $this->getAllTeamUserIds((int) $currentUser->id);
            $myTeamUserIds = array_map('intval', $myTeamUserIds);

            if ($teamUserId) {
                if (!in_array((int) $teamUserId, $myTeamUserIds, true)) {
                    return [];
                }

                return $this->getAllTeamUserIds((int) $teamUserId);
            }

            return $myTeamUserIds;
        }

        return [];
    }

    protected function canAccessUser(int $userId): bool
    {
        $currentUser = auth()->user();

        if (!$currentUser) {
            return false;
        }

        if ((int) $currentUser->id === (int) $userId) {
            return true;
        }

        if ($this->isSuperAdmin()) {
            return true;
        }

        if ($this->hasTeamDataScope()) {
            $teamUserIds = $this->getAllTeamUserIds((int) $currentUser->id);
            $teamUserIds = array_map('intval', $teamUserIds);

            return in_array((int) $userId, $teamUserIds, true);
        }

        return false;
    }

    protected function getAllTeamUserIds(int $userId): array
    {
        $allIds = [$userId];
        $pendingIds = [$userId];

        while (!empty($pendingIds)) {
            $children = User::query()
                ->whereIn('referral_id', $pendingIds)
                ->pluck('id')
                ->map(function ($id) {
                    return (int) $id;
                })
                ->toArray();

            $children = array_values(array_diff($children, $allIds));

            if (empty($children)) {
                break;
            }

            $allIds = array_merge($allIds, $children);
            $pendingIds = $children;
        }

        return array_values(array_unique(array_map('intval', $allIds)));
    }

    protected function getVipFeeDiscount($vip)
    {
        $vip = intval($vip ?? 0);

        $discountMap = [
            0 => '1',
            1 => '0.9',
            2 => '0.8',
            3 => '0.65',
            4 => '0.55',
            5 => '0.45',
            6 => '0.35',
            7 => '0.25',
            8 => '0.20',
        ];

        return $discountMap[$vip] ?? '1';
    }

    protected function getRefundRateByDiscount($discount)
    {
        $discount = $this->safeDecimal($discount, 8);

        if ($this->safeCompare($discount, 0, 8) < 0) {
            $discount = '1';
        }

        if ($this->safeCompare($discount, 1, 8) > 0) {
            $discount = '1';
        }

        $refundRate = math_multiply(math_sub('1', $discount), '100');

        if ($this->safeCompare($refundRate, 0, 8) < 0) {
            return '0';
        }

        if ($this->safeCompare($refundRate, 100, 8) > 0) {
            return '100';
        }

        return $this->safeDecimal($refundRate, 8);
    }

    protected function createOptionFeeRefundRecord(
        $user,
        $wallet,
        $option,
        $originalFee,
        $vip,
        $discount,
        $refundRate,
        $refundAmount
    ) {
        DB::table('option_fee_refund_records')->insert([
            'id' => generate_uuid(),
            'user_id' => $user->id,
            'wallet_id' => $wallet->id ?? null,
            'option_id' => $option->id ?? null,
            'option_uuid' => (string) $option->uuid,
            'market_id' => $option->market_id,
            'currency_id' => $option->currency_id,
            'original_fee' => $this->safeDecimal($originalFee),
            'vip_level' => intval($vip ?? 0),
            'discount_rate' => $this->safeDecimal($discount, 8),
            'refund_rate' => $this->safeDecimal($refundRate, 8),
            'refund_amount' => $this->safeDecimal($refundAmount),
            'remark' => 'Option fee refund',
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    private function getOptionWalletByCurrency($userId, $currencyId): ?Wallet
    {
        $query = Wallet::query()
            ->where('user_id', $userId)
            ->where('currency_id', $currencyId)
            ->lockForUpdate();

        $virtualSqlParts = [];

        if (Schema::hasColumn('wallets', 'balance_in_virtual_wallet')) {
            $virtualSqlParts[] = 'COALESCE(balance_in_virtual_wallet, 0)';
        }

        if (Schema::hasColumn('wallets', 'balance_in_virtual_trade')) {
            $virtualSqlParts[] = 'COALESCE(balance_in_virtual_trade, 0)';
        }

        if (!empty($virtualSqlParts)) {
            $query->orderByRaw('(' . implode(' + ', $virtualSqlParts) . ') DESC');
        }

        $wallet = $query
            ->orderByDesc('id')
            ->first();

        if ($wallet) {
            return $wallet;
        }

        $wallet = new Wallet();
        $wallet->user_id = $userId;
        $wallet->currency_id = $currencyId;
        $wallet->save();

        return Wallet::query()
            ->where('id', $wallet->id)
            ->lockForUpdate()
            ->first();
    }

    private function getOptionVirtualBalance(Wallet $wallet): string
    {
        $wallet->refresh();

        $virtualWallet = '0';
        $virtualTrade = '0';

        if (Schema::hasColumn('wallets', 'balance_in_virtual_wallet')) {
            $virtualWallet = $this->safeDecimal($wallet->balance_in_virtual_wallet ?? 0);
        }

        if (Schema::hasColumn('wallets', 'balance_in_virtual_trade')) {
            $virtualTrade = $this->safeDecimal($wallet->balance_in_virtual_trade ?? 0);
        }

        return $this->safeDecimal(math_sum($virtualWallet, $virtualTrade));
    }

    private function decreaseOptionVirtualBalance(Wallet $wallet, $amount): void
    {
        $amount = $this->safeDecimal($amount);
        $remaining = $amount;

        $sourceFields = [
            'balance_in_virtual_wallet',
            'balance_in_virtual_trade',
        ];

        foreach ($sourceFields as $sourceField) {
            if ($this->safeCompare($remaining, 0) <= 0) {
                break;
            }

            if (!Schema::hasColumn('wallets', $sourceField)) {
                continue;
            }

            $wallet->refresh();

            $available = $this->safeDecimal($wallet->{$sourceField} ?? 0);

            if ($this->safeCompare($available, 0) <= 0) {
                continue;
            }

            $useAmount = $this->safeCompare($available, $remaining) >= 0
                ? $remaining
                : $available;

            if ($this->safeCompare($useAmount, 0) <= 0) {
                continue;
            }

            $this->decreaseWalletField($wallet, $sourceField, $useAmount);

            $remaining = $this->safeDecimal(math_sub($remaining, $useAmount));
        }

        if ($this->safeCompare($remaining, 0) > 0) {
            throw new \Exception('Insufficient virtual options balance. Required remaining: ' . $remaining);
        }
    }

    private function increaseWalletField(Wallet $wallet, string $field, $amount): void
    {
        $amount = $this->safeDecimal($amount);

        if ($this->safeCompare($amount, 0) <= 0) {
            return;
        }

        $allowedFields = [
            'balance_in_wallet',
            'balance_in_trade',
            'balance_in_order',
            'balance_in_withdraw',
            'balance_in_lc',
            'balance_in_virtual_wallet',
            'balance_in_virtual_trade',
            'balance_in_virtual_order',
            'balance_in_virtual_withdraw',
        ];

        if (!in_array($field, $allowedFields, true) || !Schema::hasColumn('wallets', $field)) {
            throw new \Exception('Invalid wallet balance field');
        }

        DB::statement(
            "UPDATE wallets SET {$field} = COALESCE({$field}, 0) + ?, updated_at = ? WHERE id = ?",
            [$amount, Carbon::now(), $wallet->id]
        );

        $wallet->refresh();
    }

    private function decreaseWalletField(Wallet $wallet, string $field, $amount): void
    {
        $amount = $this->safeDecimal($amount);

        if ($this->safeCompare($amount, 0) <= 0) {
            return;
        }

        $allowedFields = [
            'balance_in_wallet',
            'balance_in_trade',
            'balance_in_order',
            'balance_in_withdraw',
            'balance_in_lc',
            'balance_in_virtual_wallet',
            'balance_in_virtual_trade',
            'balance_in_virtual_order',
            'balance_in_virtual_withdraw',
        ];

        if (!in_array($field, $allowedFields, true) || !Schema::hasColumn('wallets', $field)) {
            throw new \Exception('Invalid wallet balance field');
        }

        $freshWallet = Wallet::query()
            ->where('id', $wallet->id)
            ->lockForUpdate()
            ->first();

        if (!$freshWallet) {
            throw new \Exception('Wallet not found');
        }

        $available = $this->safeDecimal($freshWallet->{$field} ?? 0);

        if ($this->safeCompare($available, $amount) < 0) {
            throw new \Exception(
                'Insufficient balance on ' . $field .
                '. Available: ' . $available .
                ', Required: ' . $amount
            );
        }

        DB::statement(
            "UPDATE wallets SET {$field} = GREATEST(COALESCE({$field}, 0) - ?, 0), updated_at = ? WHERE id = ?",
            [$amount, Carbon::now(), $wallet->id]
        );

        $wallet->refresh();
    }

    private function safeDecimal($value, $scale = 18)
    {
        return \App\Services\Math\ExactDecimal::normalize($value,(int)$scale);
    }

    private function safeCompare($left, $right, $scale = 18)
    {
        return bccomp($this->safeDecimal($left, $scale), $this->safeDecimal($right, $scale), $scale);
    }
}