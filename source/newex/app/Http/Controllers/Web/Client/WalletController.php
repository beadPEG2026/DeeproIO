<?php

namespace App\Http\Controllers\Web\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Wallet\FiatDepositFormRequest;
use App\Http\Requests\Web\Wallet\FiatWithdrawFormRequest;
use App\Http\Resources\Country\CountryCollection;
use App\Http\Resources\Currency\Currency;
use App\Http\Resources\Currency\CurrencyCollection;
use App\Http\Resources\Currency\FiatCurrency;
use App\Mail\Deposits\AdminDepositReceived;
use App\Models\Network\Network;
use App\Repositories\Country\CountryRepository;
use App\Repositories\Currency\CurrencyRepository;
use App\Repositories\Deposit\FiatDepositRepository;
use App\Repositories\Wallet\WalletRepository;
use App\Repositories\Withdrawal\FiatWithdrawalRepository;
use App\Services\Currency\CurrencyService;
use App\Services\Wallet\AutoInvestOrderService;
use App\Services\Withdrawal\WithdrawalFeeService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Setting;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    /**
     * @var CurrencyService
     */
    protected $currencyService;

    /**
     * WalletController Constructor
     *
     * @param CurrencyService $currencyService
     */
    public function __construct(CurrencyService $currencyService)
    {
        $this->currencyService = $currencyService;
    }

    /** Initial overview and its balances travel in one authenticated response. */
    private function balanceSnapshot(): ?array
    {
        try {
            return [
                'owner_id' => (int) auth()->id(),
                'wallets' => app(\App\Http\Controllers\Api\v1\WalletController::class)
                    ->index(request(), true)->resolve(request()),
            ];
        } catch (\Throwable $e) {
            // Keep navigation usable; the client retries the dedicated balance endpoint.
            return null;
        }
    }

    public function index()
    {
        return Inertia::render('Wallet/Wallets', ['walletSnapshot' => fn () => $this->balanceSnapshot()]);
    }

    public function indexLite()
    {
        return Inertia::render('WalletLite/Wallets');
    }

    /**
     * New Wallets landing page with Funding and Trading links
     */
    public function newWallets()
    {
        return Inertia::render('Wallet/NewWallets', ['walletSnapshot' => fn () => $this->balanceSnapshot()]);
    }

public function autoInvest()
{
    $user = auth()->user();

    $pageData = $this->getAutoInvestPageData($user);

    $rateSettings = $this->getAutoInvestRateSettings();

    // VIP 收益加成从 settings 表读取，不再前端写死。
    $rateSettings['vip_boosts'] = $this->getAutoInvestVipBoostSettings();

    $vipLevel = $this->getAutoInvestUserVipLevel($user);
    $vipBoostPercent = $vipLevel > 0 && isset($rateSettings['vip_boosts'][$vipLevel])
        ? (float) $rateSettings['vip_boosts'][$vipLevel]
        : 0;

    $pageData['autoInvestRates'] = $rateSettings;
    $pageData['lcSettings'] = $rateSettings['raw'];

    $autoInvestEarnings = isset($pageData['autoInvestEarnings']) && is_array($pageData['autoInvestEarnings'])
        ? $pageData['autoInvestEarnings']
        : [];

    $autoInvestEarnings['vip_level'] = $vipLevel;
    $autoInvestEarnings['current_vip_level'] = $vipLevel;
    $autoInvestEarnings['vip_boost_percent'] = $vipBoostPercent;

    $pageData['autoInvestEarnings'] = $autoInvestEarnings;

    return Inertia::render('Wallet/AutoInvest', $pageData);
}

/**
 * 获取 VIP1 - VIP8 理财收益加成配置。
 * 读取 settings 表：
 * trade.lc_vip_1_boost_percent ... trade.lc_vip_8_boost_percent
 */
private function getAutoInvestVipBoostSettings(): array
{
    $boosts = [];

    for ($level = 1; $level <= 8; $level++) {
        $default = $level * 10;
        $settingKey = 'trade.lc_vip_' . $level . '_boost_percent';

        $value = $this->getAutoInvestSettingValueFromTable($settingKey, $default);
        $boosts[$level] = $this->normalizeAutoInvestPercentValue($value, $default);
    }

    return $boosts;
}

/**
 * 从 settings 表读取配置。
 * 兼容三种保存方式：
 * 1. key = trade.lc_vip_1_boost_percent
 * 2. key = lc_vip_1_boost_percent
 * 3. key = trade，value 是 JSON，里面有 lc_vip_1_boost_percent
 */
private function getAutoInvestSettingValueFromTable(string $key, $default = null)
{
    $value = \Illuminate\Support\Facades\DB::table('settings')
        ->where('key', $key)
        ->value('value');

    if ($value !== null && $value !== '') {
        return $value;
    }

    if (strpos($key, 'trade.') === 0) {
        $shortKey = substr($key, strlen('trade.'));

        $value = \Illuminate\Support\Facades\DB::table('settings')
            ->where('key', $shortKey)
            ->value('value');

        if ($value !== null && $value !== '') {
            return $value;
        }

        $tradeValue = \Illuminate\Support\Facades\DB::table('settings')
            ->where('key', 'trade')
            ->value('value');

        if ($tradeValue !== null && $tradeValue !== '') {
            $decoded = json_decode((string) $tradeValue, true);

            if (is_array($decoded) && array_key_exists($shortKey, $decoded)) {
                return $decoded[$shortKey];
            }
        }
    }

    return $default;
}

private function normalizeAutoInvestPercentValue($value, float $default = 0): float
{
    if ($value === null || $value === '') {
        return $default;
    }

    $text = str_replace(['%', '％', ',', ' '], '', (string) $value);

    if (!is_numeric($text)) {
        return $default;
    }

    return (float) $text;
}

/**
 * 当前用户 VIP 等级从 users 表读取。
 */
private function getAutoInvestUserVipLevel($user): int
{
    $userId = is_object($user) && isset($user->id) ? (int) $user->id : 0;

    if ($userId <= 0) {
        return 0;
    }

    $userRow = \Illuminate\Support\Facades\DB::table('users')
        ->where('id', $userId)
        ->first();

    if (!$userRow) {
        return 0;
    }

    $userData = (array) $userRow;
    $candidateFields = [
        'vip',
        'vip_level',
        'current_vip_level',
        'vip_grade',
        'member_level',
        'membership_level',
        'account_level',
        'level',
        'rank',
        'grade',
    ];

    foreach ($candidateFields as $field) {
        if (!array_key_exists($field, $userData)) {
            continue;
        }

        $level = $this->parseAutoInvestVipLevel($userData[$field]);

        if ($level >= 1 && $level <= 8) {
            return $level;
        }
    }

    return 0;
}

private function parseAutoInvestVipLevel($value): int
{
    if ($value === null || $value === '') {
        return 0;
    }

    if (is_numeric($value)) {
        return (int) $value;
    }

    preg_match('/\d+/', (string) $value, $matches);

    if (empty($matches[0])) {
        return 0;
    }

    return (int) $matches[0];
}
public function redeemAutoInvestOrder(\Illuminate\Http\Request $request)
{
    $user = auth()->user();

    if (!$user) {
        return response()->json([
            'code' => 0,
            'msg' => __('Unauthorized'),
        ], 401);
    }

    $orderId = $request->input('order_id') ?: $request->input('id');

    if (!$orderId) {
        return response()->json([
            'code' => 0,
            'msg' => __('Order ID not found.'),
        ], 422);
    }

    try {
        $result = (new AutoInvestOrderService())->redeemOrder($user, $orderId);

        return response()->json([
            'code' => 1,
            'msg' => __('Redeemed successfully'),
            'data' => $result,
        ]);
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::error($e);

        return response()->json([
            'code' => 0,
            'msg' => $e->getMessage(),
        ], 422);
    }

    try {
        $result = \Illuminate\Support\Facades\DB::transaction(function () use ($user, $orderId) {
            $orderQuery = \Illuminate\Support\Facades\DB::table('auto_invest_orders')
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->lockForUpdate();

            if (is_numeric($orderId)) {
                $orderQuery->where('id', $orderId);
            } else {
                $orderQuery->where(function ($query) use ($orderId) {
                    $query->where('order_no', $orderId);

                    if (\Illuminate\Support\Facades\Schema::hasColumn('auto_invest_orders', 'order_id')) {
                        $query->orWhere('order_id', $orderId);
                    }
                });
            }

            $order = $orderQuery->first();

            if (!$order) {
                throw new \Exception(__('Auto Invest order not found.'));
            }

            $investmentType = strtolower((string) ($order->investment_type ?? $order->type ?? 'fixed'));
            $isFlexible = $investmentType === 'flexible';

            $amount = $this->autoInvestDecimal($order->amount ?? 0);
            $usedMargin = $this->autoInvestDecimal($order->used_margin ?? 0);
            $principalAmount = $this->autoInvestDecimal($order->principal_amount ?? 0);
            $redeemedAmountBefore = $this->autoInvestDecimal($order->redeemed_amount ?? 0);

            if ($this->autoInvestCompare($amount, 0) <= 0) {
                throw new \Exception(__('Redeemable amount is insufficient.'));
            }

            /**
             * 老数据 principal_amount 可能为 0。
             * 如果没有本金记录，就把当前 amount 当成本金，避免把全部金额误判为收益。
             */
            if ($this->autoInvestCompare($principalAmount, 0) <= 0) {
                $principalAmount = $amount;
            }

            $maturityDate = $this->getAutoInvestOrderMaturityDate($order);
            $isMatured = $isFlexible || ($maturityDate && now()->greaterThanOrEqualTo($maturityDate));

            if (!$isFlexible && !$isMatured) {
                throw new \Exception(__('Fixed Term orders can only be redeemed after maturity.'));
            }

            /**
             * 先判断已用保证金。
             *
             * used_margin = 0：
             *      整笔订单都可以赎回，订单关闭。
             *
             * used_margin > 0：
             *      只能赎回 amount - used_margin，订单继续 active。
             */
            if ($this->autoInvestCompare($usedMargin, 0) <= 0) {
                $availableAmount = $amount;
                $remainingAmount = '0';
                $remainingUsedMargin = '0';
                $shouldCloseOrder = true;
            } else {
                $availableAmount = $this->autoInvestSub($amount, $usedMargin);

                if ($this->autoInvestCompare($availableAmount, 0) <= 0) {
                    throw new \Exception(__('This order is currently being used as trading margin and cannot be redeemed.'));
                }

                $remainingAmount = $usedMargin;
                $remainingUsedMargin = $usedMargin;
                $shouldCloseOrder = false;
            }

            /**
             * 计算这笔订单当前总收益。
             *
             * 注意：
             * amount 已经包含每日收益。
             * 所以总收益 = amount - principal_amount。
             */
            $totalProfitAmount = $this->autoInvestSub($amount, $principalAmount);

            /**
             * 如果是部分赎回，需要按比例拆出可赎回部分对应的本金和收益。
             *
             * 例如：
             * amount = 1100
             * principal_amount = 1000
             * total_profit = 100
             * used_margin = 600
             * available_amount = 500
             *
             * available_ratio = 500 / 1100
             * 可赎回收益 = 100 * available_ratio
             * 可赎回本金 = 500 - 可赎回收益
             */
            $availableRatio = $this->autoInvestCompare($amount, 0) > 0
                ? $this->autoInvestDiv($availableAmount, $amount)
                : '0';

            if ($this->autoInvestCompare($totalProfitAmount, 0) > 0) {
                $availableProfitAmount = $this->autoInvestMul($totalProfitAmount, $availableRatio);
                $availablePrincipalAmount = $this->autoInvestSub($availableAmount, $availableProfitAmount);
            } else {
                /**
                 * 如果总收益是负数，不再做收益 50% 扣除。
                 * 直接按当前可赎回金额返还。
                 */
                $availableProfitAmount = '0';
                $availablePrincipalAmount = $availableAmount;
            }

            /**
             * 赎回金额：
             *
             * 定期：
             *      到期后全额返还可赎回金额。
             *
             * 活期：
             *      本金 + 正收益的 50%。
             *
             * 也就是：
             *      amount - 总收益 + 总收益 * 0.5
             */
            $deductedProfitAmount = '0';

            if ($isFlexible && $this->autoInvestCompare($availableProfitAmount, 0) > 0) {
                $halfProfit = $this->autoInvestDiv($availableProfitAmount, 2);
                $deductedProfitAmount = $halfProfit;
                $redeemAmount = $this->autoInvestAdd($availablePrincipalAmount, $halfProfit);
            } else {
                $redeemAmount = $availableAmount;
            }

            if ($this->autoInvestCompare($redeemAmount, 0) <= 0) {
                throw new \Exception(__('Redeemable amount is insufficient.'));
            }

            $wallet = \Illuminate\Support\Facades\DB::table('wallets')
                ->where('user_id', $user->id)
                ->where('currency_id', $order->currency_id)
                ->lockForUpdate()
                ->first();

            if (!$wallet) {
                throw new \Exception(__('Wallet not found'));
            }

            /**
             * 赎回金额返回原来源交易账户。
             * 如果这笔理财本金来自虚拟交易账户，则赎回也回到 balance_in_virtual_trade。
             * 如果是老订单或真实账户订单，则回到 balance_in_trade。
             */
            $returnBalanceField = $this->getAutoInvestOrderReturnBalanceField($order);

            \Illuminate\Support\Facades\DB::table('wallets')
                ->where('id', $wallet->id)
                ->update([
                    $returnBalanceField => \Illuminate\Support\Facades\DB::raw(
                        $returnBalanceField . ' + ' . $this->autoInvestSqlDecimal($redeemAmount)
                    ),
                    'updated_at' => now(),
                ]);

            /**
             * 计算订单剩余本金。
             * used_margin > 0 时，订单剩余 amount = used_margin。
             * used_margin = 0 时，订单关闭，剩余本金为 0。
             */
            if (
                !$shouldCloseOrder &&
                $this->autoInvestCompare($principalAmount, 0) > 0 &&
                $this->autoInvestCompare($amount, 0) > 0
            ) {
                $remainingRatio = $this->autoInvestDiv($remainingAmount, $amount);
                $newPrincipalAmount = $this->autoInvestMul($principalAmount, $remainingRatio);
            } else {
                $newPrincipalAmount = '0';
            }

            $newRedeemedAmount = $this->autoInvestAdd($redeemedAmountBefore, $redeemAmount);

            $update = [
                'amount' => $this->autoInvestDecimal($remainingAmount),
                'used_margin' => $this->autoInvestDecimal($remainingUsedMargin),
                'principal_amount' => $this->autoInvestDecimal($newPrincipalAmount),
                'status' => $shouldCloseOrder ? 'closed' : 'active',
                'updated_at' => now(),
            ];

            if (\Illuminate\Support\Facades\Schema::hasColumn('auto_invest_orders', 'redeemed_amount')) {
                $update['redeemed_amount'] = $this->autoInvestDecimal($newRedeemedAmount);
            }

            if (\Illuminate\Support\Facades\Schema::hasColumn('auto_invest_orders', 'deducted_profit_amount')) {
                $oldDeductedProfitAmount = $this->autoInvestDecimal($order->deducted_profit_amount ?? 0);

                $update['deducted_profit_amount'] = $this->autoInvestDecimal(
                    $this->autoInvestAdd($oldDeductedProfitAmount, $deductedProfitAmount)
                );
            }

            /**
             * 同步剩余订单的收益字段。
             */
            $remainingProfitAmount = $this->autoInvestSub($remainingAmount, $newPrincipalAmount);

            if (\Illuminate\Support\Facades\Schema::hasColumn('auto_invest_orders', 'settled_profit_amount')) {
                $update['settled_profit_amount'] = $this->autoInvestDecimal($remainingProfitAmount);
            }

            if (\Illuminate\Support\Facades\Schema::hasColumn('auto_invest_orders', 'total_profit')) {
                $update['total_profit'] = $this->autoInvestDecimal($remainingProfitAmount);
            }

            if (\Illuminate\Support\Facades\Schema::hasColumn('auto_invest_orders', 'current_total_profit_percent')) {
                if ($this->autoInvestCompare($newPrincipalAmount, 0) > 0) {
                    $profitPercent = $this->autoInvestMul(
                        $this->autoInvestDiv($remainingProfitAmount, $newPrincipalAmount),
                        100
                    );

                    $update['current_total_profit_percent'] = $this->autoInvestDecimal($profitPercent, 8);
                } else {
                    $update['current_total_profit_percent'] = 0;
                }
            }

            /**
             * 整笔关闭时写入关闭时间。
             */
            if ($shouldCloseOrder) {
                if (\Illuminate\Support\Facades\Schema::hasColumn('auto_invest_orders', 'redeemed_at')) {
                    $update['redeemed_at'] = now();
                }

                if (\Illuminate\Support\Facades\Schema::hasColumn('auto_invest_orders', 'closed_at')) {
                    $update['closed_at'] = now();
                }

                if (\Illuminate\Support\Facades\Schema::hasColumn('auto_invest_orders', 'ended_at')) {
                    $update['ended_at'] = now();
                }

                if (\Illuminate\Support\Facades\Schema::hasColumn('auto_invest_orders', 'last_settled_at')) {
                    $update['last_settled_at'] = now();
                }
            }

            \Illuminate\Support\Facades\DB::table('auto_invest_orders')
                ->where('id', $order->id)
                ->update($update);

            return [
                'redeem_amount' => $redeemAmount,
                'available_amount' => $availableAmount,
                'total_profit_amount' => $totalProfitAmount,
                'available_principal_amount' => $availablePrincipalAmount,
                'available_profit_amount' => $availableProfitAmount,
                'deducted_profit_amount' => $deductedProfitAmount,
                'order_remaining_amount' => $remainingAmount,
                'used_margin' => $remainingUsedMargin,
                'closed' => $shouldCloseOrder,
            ];
        });

        return response()->json([
            'code' => 1,
            'msg' => __('Redeemed successfully'),
            'data' => $result,
        ]);
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::error($e);

        return response()->json([
            'code' => 0,
            'msg' => $e->getMessage(),
        ], 422);
    }
}
private function getAutoInvestOrderMaturityDate($order)
{
    $dateValue = $order->maturity_date ?? $order->matured_at ?? null;

    if ($dateValue) {
        try {
            return \Carbon\Carbon::parse($dateValue);
        } catch (\Throwable $e) {
            //
        }
    }

    $startedAt = $order->started_at ?? $order->created_at ?? null;
    $days = (int) ($order->days ?? 0);

    if (!$startedAt || $days <= 0) {
        return null;
    }

    try {
        return \Carbon\Carbon::parse($startedAt)->addDays($days);
    } catch (\Throwable $e) {
        return null;
    }
}

private function isAutoInvestOrderPeriodCompleted($order): bool
{
    $maturityDate = $this->getAutoInvestOrderMaturityDate($order);

    if (!$maturityDate) {
        return false;
    }

    return now()->greaterThanOrEqualTo($maturityDate);
}

private function autoInvestDecimal($value, int $scale = 18): string
{
    if ($value === null || $value === '') {
        $value = 0;
    }

    return number_format((float) $value, $scale, '.', '');
}

private function autoInvestSqlDecimal($value, int $scale = 18): string
{
    return $this->autoInvestDecimal($value, $scale);
}

private function autoInvestCompare($left, $right): int
{
    $left = (float) $left;
    $right = (float) $right;

    if (abs($left - $right) < 0.000000000000000001) {
        return 0;
    }

    return $left > $right ? 1 : -1;
}

private function autoInvestAdd($left, $right): string
{
    return $this->autoInvestDecimal((float) $left + (float) $right);
}

private function autoInvestSub($left, $right): string
{
    return $this->autoInvestDecimal((float) $left - (float) $right);
}

private function autoInvestMul($left, $right): string
{
    return $this->autoInvestDecimal((float) $left * (float) $right);
}

private function autoInvestDiv($left, $right): string
{
    if ((float) $right == 0.0) {
        return '0';
    }

    return $this->autoInvestDecimal((float) $left / (float) $right);
}

/**
 * 理财扣款账户选择。
 *
 * 规则：
 * 1. 虚拟交易账户 balance_in_virtual_trade > 0 时，本次理财全部使用虚拟交易账户。
 * 2. 虚拟交易账户余额不足时直接报错，不混用真实交易账户。
 * 3. 没有虚拟交易余额时，才使用真实交易账户 balance_in_trade。
 */
private function getAutoInvestWalletDebitSource($wallet, $amount): array
{
    $amount = (float) $amount;

    if ($amount <= 0) {
        throw \Illuminate\Validation\ValidationException::withMessages([
            'amount' => ['Please enter a valid investment amount.'],
        ]);
    }

    $virtualTradeBalance = 0;

    if (\Illuminate\Support\Facades\Schema::hasColumn('wallets', 'balance_in_virtual_trade')) {
        $virtualTradeBalance = (float) ($wallet->balance_in_virtual_trade ?? 0);
    }

    if ($virtualTradeBalance > 0) {
        if ($virtualTradeBalance < $amount) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'amount' => ['Insufficient virtual trading balance.'],
            ]);
        }

        return [
            'account_type' => 'virtual',
            'field' => 'balance_in_virtual_trade',
        ];
    }

    $tradeBalance = (float) ($wallet->balance_in_trade ?? 0);

    if ($tradeBalance < $amount) {
        throw \Illuminate\Validation\ValidationException::withMessages([
            'amount' => ['Insufficient trading balance.'],
        ]);
    }

    return [
        'account_type' => 'real',
        'field' => 'balance_in_trade',
    ];
}

/**
 * 根据理财订单来源决定赎回回到哪个交易账户。
 *
 * 新订单会在 meta.source_balance_field 中记录来源。
 * 老订单没有来源记录时，默认按真实交易账户处理。
 */
private function getAutoInvestOrderReturnBalanceField($order): string
{
    $sourceField = null;

    if (isset($order->source_balance_field)) {
        $sourceField = $order->source_balance_field;
    }

    if (!$sourceField && isset($order->source_field)) {
        $sourceField = $order->source_field;
    }

    $meta = [];

    if (!empty($order->meta)) {
        $decoded = json_decode((string) $order->meta, true);
        $meta = is_array($decoded) ? $decoded : [];
    }

    if (!$sourceField && isset($meta['source_balance_field'])) {
        $sourceField = $meta['source_balance_field'];
    }

    if (!$sourceField && isset($meta['source_field'])) {
        $sourceField = $meta['source_field'];
    }

    if (!$sourceField && (isset($meta['source_account_type']) && $meta['source_account_type'] === 'virtual')) {
        $sourceField = 'balance_in_virtual_trade';
    }

    if (!$sourceField && isset($order->source_account_type) && $order->source_account_type === 'virtual') {
        $sourceField = 'balance_in_virtual_trade';
    }

    return $this->normalizeAutoInvestBalanceField($sourceField ?: 'balance_in_trade');
}

private function normalizeAutoInvestBalanceField($field): string
{
    $field = (string) $field;

    $allowedFields = [
        'balance_in_trade',
        'balance_in_virtual_trade',
    ];

    if (!in_array($field, $allowedFields, true)) {
        return 'balance_in_trade';
    }

    if (!\Illuminate\Support\Facades\Schema::hasColumn('wallets', $field)) {
        return 'balance_in_trade';
    }

    return $field;
}
private function getAutoInvestRateSettings(): array
{
    $trade = \Setting::get('trade', []);

    if (!is_array($trade)) {
        $decodedTrade = json_decode((string) $trade, true);
        $trade = is_array($decodedTrade) ? $decodedTrade : [];
    }

    /**
     * 活期 daily yield 配置：
     * trade.lc30 / trade.lc90 / trade.lc180 / trade.lc365
     */
    $lc30 = (string) ($trade['lc30'] ?? \Setting::get('trade.lc30', '0'));
    $lc90 = (string) ($trade['lc90'] ?? \Setting::get('trade.lc90', '0'));
    $lc180 = (string) ($trade['lc180'] ?? \Setting::get('trade.lc180', '0'));
    $lc365 = (string) ($trade['lc365'] ?? \Setting::get('trade.lc365', '0'));

    /**
     * 定期 daily yield 配置：
     * trade.lc_dq30 / trade.lc_dq90 / trade.lc_dq180 / trade.lc_dq365
     */
    $lcDq30 = (string) ($trade['lc_dq30'] ?? \Setting::get('trade.lc_dq30', '0'));
    $lcDq90 = (string) ($trade['lc_dq90'] ?? \Setting::get('trade.lc_dq90', '0'));
    $lcDq180 = (string) ($trade['lc_dq180'] ?? \Setting::get('trade.lc_dq180', '0'));
    $lcDq365 = (string) ($trade['lc_dq365'] ?? \Setting::get('trade.lc_dq365', '0'));

    return [
        'raw' => [
            'lc30' => $lc30,
            'lc90' => $lc90,
            'lc180' => $lc180,
            'lc365' => $lc365,
            'lc_dq30' => $lcDq30,
            'lc_dq90' => $lcDq90,
            'lc_dq180' => $lcDq180,
            'lc_dq365' => $lcDq365,
        ],

        /**
         * 活期日收益范围
         */
        'flexible' => [
            30 => $lc30,
            90 => $lc90,
            180 => $lc180,
            365 => $lc365,
        ],

        /**
         * 定期日收益范围
         */
        'fixed' => [
            30 => $lcDq30,
            90 => $lcDq90,
            180 => $lcDq180,
            365 => $lcDq365,
        ],
    ];
}

    /**
     * 获取用户 VIP 等级
     *
     * 兼容字段：
     * vip / vip_level / vipLevel / member_level / membership_level / level / vip.level / vip.vip_level
     */
    private function getUserVipLevel($user): int
    {
        if (!$user) {
            return 0;
        }

        $candidates = [
            data_get($user, 'vip'),
            data_get($user, 'vip_level'),
            data_get($user, 'vipLevel'),
            data_get($user, 'member_level'),
            data_get($user, 'membership_level'),
            data_get($user, 'level'),
            data_get($user, 'vip.level'),
            data_get($user, 'vip.vip_level'),
        ];

        foreach ($candidates as $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if (is_numeric($value)) {
                $level = (int) $value;
            } else {
                preg_match('/\d+/', (string) $value, $matches);
                $level = isset($matches[0]) ? (int) $matches[0] : 0;
            }

            if ($level >= 1 && $level <= 8) {
                return $level;
            }
        }

        return 0;
    }

    /**
     * VIP 收益加成比例
     *
     * VIP1 +10%
     * VIP2 +20%
     * VIP3 +30%
     * VIP4 +40%
     * VIP5 +50%
     * VIP6 +60%
     * VIP7 +70%
     * VIP8 +80%
     */
    private function getVipYieldBoostRate($user): float
    {
        $vipLevel = $this->getUserVipLevel($user);

        $rateMap = [
            1 => 10,
            2 => 20,
            3 => 30,
            4 => 40,
            5 => 50,
            6 => 60,
            7 => 70,
            8 => 80,
        ];

        return (float) ($rateMap[$vipLevel] ?? 0);
    }

    /**
     * 按 VIP 加成计算收益
     */
    private function applyVipYieldBoost(float $baseProfit, float $vipBoostRate): array
    {
        $baseProfit = max(0, $baseProfit);
        $vipBoostRate = max(0, $vipBoostRate);

        $vipProfit = $baseProfit * ($vipBoostRate / 100);
        $totalProfit = $baseProfit + $vipProfit;

        return [
            'base_profit' => $baseProfit,
            'vip_profit' => $vipProfit,
            'total_profit' => $totalProfit,
        ];
    }

    private function getAutoInvestPageData($user)
    {
        /**
         * 新逻辑：理财资产不再读取 wallet.balance_in_lc。
         * 理财本金存放在 auto_invest_orders 订单表中，钱包只扣减 balance_in_trade，
         * 订单中的 amount 表示被冻结的理财本金，used_margin 表示已被合约占用的理财本金。
         */
        $vipLevel = $this->getUserVipLevel($user);
        $vipYieldBoostRate = $this->getVipYieldBoostRate($user);

        $lcSettings = [
            'lc30'  => (float) Setting::get('trade.lc30', 30),
            'lc90'  => (float) Setting::get('trade.lc90', 90),
            'lc180' => (float) Setting::get('trade.lc180', 180),
            'lc365' => (float) Setting::get('trade.lc365', 365),
            'lc_dq30' => (float) Setting::get('trade.lc_dq30', 30),
            'lc_dq90' => (float) Setting::get('trade.lc_dq90', 90),
            'lc_dq180' => (float) Setting::get('trade.lc_dq180', 180),
            'lc_dq365' => (float) Setting::get('trade.lc_dq365', 365),
        ];

        $flexibleRateMap = [
            30  => $lcSettings['lc30'],
            90  => $lcSettings['lc90'],
            180 => $lcSettings['lc180'],
            365 => $lcSettings['lc365'],
        ];

        $fixedRateMap = [
            30  => $lcSettings['lc_dq30'],
            90  => $lcSettings['lc_dq90'],
            180 => $lcSettings['lc_dq180'],
            365 => $lcSettings['lc_dq365'],
        ];

        /**
         * 兼容旧变量名，默认用活期收益表。
         */
        $rateMap = $flexibleRateMap;

        $activeOrders = collect();
        $displayOrders = collect();

        if (\Schema::hasTable('auto_invest_orders')) {
            $ordersQuery = \DB::table('auto_invest_orders')
                ->where('user_id', $user->id);

            if (\Schema::hasColumn('auto_invest_orders', 'status')) {
                $ordersQuery->where('status', 'active');
            }

            if (
                \Schema::hasColumn('auto_invest_orders', 'started_at') &&
                \Schema::hasColumn('auto_invest_orders', 'created_at')
            ) {
                $ordersQuery->orderByRaw('COALESCE(started_at, created_at) DESC');
            } elseif (\Schema::hasColumn('auto_invest_orders', 'started_at')) {
                $ordersQuery->orderByDesc('started_at');
            } elseif (\Schema::hasColumn('auto_invest_orders', 'created_at')) {
                $ordersQuery->orderByDesc('created_at');
            }

            $displayOrders = $ordersQuery
                ->orderByDesc('id')
                ->get();

            $activeOrders = $displayOrders
                ->sortBy(function ($order) {
                    return sprintf(
                        '%s-%020d',
                        (string) ($order->started_at ?? $order->created_at ?? ''),
                        (int) ($order->id ?? 0)
                    );
                })
                ->values();
        }

        $firstOrder = $activeOrders->first();
        $autoInvestFunding = $activeOrders->isNotEmpty();

        $autoInvestDays = (int) (
            data_get($firstOrder, 'days')
            ?: $user->invest_funding_time
            ?: $user->auto_invest_days
            ?: 30
        );

        if (!in_array($autoInvestDays, [30, 90, 180, 365], true)) {
            $autoInvestDays = 30;
        }

        $autoInvestType = data_get($firstOrder, 'investment_type') ?: ($user->auto_invest_type ?: 'fixed');

        if (!in_array($autoInvestType, ['flexible', 'fixed'])) {
            $autoInvestType = 'fixed';
        }

        $autoInvestAmount = (float) ($activeOrders->sum(function ($order) {
            return (float) ($order->amount ?? 0);
        }) ?: 0);

        if ($autoInvestAmount <= 0) {
            $autoInvestAmount = (float) ($user->auto_invest_amount ?: 0);
        }

        $selectedRateMap = $autoInvestType === 'flexible' ? $flexibleRateMap : $fixedRateMap;
        $selectedConfiguredRate = (float) ($selectedRateMap[$autoInvestDays] ?? 0);
        $selectedStoredRate = (float) (data_get($firstOrder, 'rate') ?: 0);
        $selectedRate = $selectedConfiguredRate > 0
            ? $selectedConfiguredRate
            : ($selectedStoredRate > 0 ? $selectedStoredRate : $lcSettings['lc30']);
        $selectedBoostedRate = $selectedRate * (1 + ($vipYieldBoostRate / 100));

        $principalUsd = 0;
        $earningPrincipalUsd = 0;
        $usedMarginUsd = 0;

        $baseCurrentEarningsUsd = 0;
        $vipCurrentBonusUsd = 0;
        $currentEarningsUsd = 0;
        $quantCurrentEarningsUsd = 0;
        $futuresPnlUsd = 0;

        $baseMaturityEarningsUsd = 0;
        $vipMaturityBonusUsd = 0;
        $maturityEarningsUsd = 0;

        $maturityTotalUsd = 0;

        $weightedEarningDaysTotal = 0;
        $weightedElapsedDaysTotal = 0;

        $startAt = null;
        $maturityDate = null;

        $elapsedDays = 0;
        $earningDays = 0;
        $earnedRate = 0;
        $baseEarnedRate = 0;
        $progressPercent = 0;

        $assets = [];
        $activeOrderCount = 0;
        $maturedOrderCount = 0;
        $redeemableOrderCount = 0;
        $redeemablePrincipalUsd = 0;
        $redeemableProfitUsd = 0;
        $redeemableAmountUsd = 0;

        $currencyIds = $displayOrders->pluck('currency_id')->filter()->unique()->values();

        $currencies = $currencyIds->isNotEmpty()
            ? \App\Models\Currency\Currency::whereIn('id', $currencyIds)->get()->keyBy('id')
            : collect();

        $currencyRepository = new \App\Repositories\Currency\CurrencyRepository();
        $consumedAmountsByOrder = collect();
        $futuresPnlByOrder = collect();

        if (
            $displayOrders->isNotEmpty() &&
            \Schema::hasTable('auto_invest_margin_locks') &&
            \Schema::hasColumn('auto_invest_margin_locks', 'auto_invest_order_id') &&
            \Schema::hasColumn('auto_invest_margin_locks', 'consumed_amount')
        ) {
            $consumedAmountsByOrder = \DB::table('auto_invest_margin_locks')
                ->selectRaw('auto_invest_order_id, SUM(COALESCE(consumed_amount, 0)) as consumed_amount')
                ->whereIn('auto_invest_order_id', $displayOrders->pluck('id')->filter()->all())
                ->groupBy('auto_invest_order_id')
                ->pluck('consumed_amount', 'auto_invest_order_id');
        }

        if (
            $displayOrders->isNotEmpty() &&
            \Schema::hasTable('auto_invest_margin_locks') &&
            \Schema::hasTable('futures_contract') &&
            \Schema::hasColumn('auto_invest_margin_locks', 'auto_invest_order_id') &&
            \Schema::hasColumn('auto_invest_margin_locks', 'source_id') &&
            \Schema::hasColumn('auto_invest_margin_locks', 'source_type') &&
            \Schema::hasColumn('auto_invest_margin_locks', 'currency_id') &&
            \Schema::hasColumn('auto_invest_margin_locks', 'amount') &&
            \Schema::hasColumn('futures_contract', 'id') &&
            \Schema::hasColumn('futures_contract', 'status') &&
            \Schema::hasColumn('futures_contract', 'balance') &&
            \Schema::hasColumn('futures_contract', 'pnl') &&
            \Schema::hasColumn('futures_contract', 'quote_currency_id')
        ) {
            $lockRows = \DB::table('auto_invest_margin_locks')
                ->where('source_type', 'futures')
                ->whereIn('auto_invest_order_id', $displayOrders->pluck('id')->filter()->all())
                ->select([
                    'auto_invest_order_id',
                    'currency_id as lock_currency_id',
                    'amount as lock_amount',
                    'source_id as futures_id',
                ])
                ->get();

            $sourceIds = $lockRows
                ->pluck('futures_id')
                ->filter(function ($value) {
                    return is_string($value) && preg_match('/^[0-9a-fA-F-]{36}$/', $value);
                })
                ->unique()
                ->values();

            $futuresSelect = [
                'id as futures_id',
                'quote_currency_id',
                'balance',
                'pnl',
            ];

            foreach (['entry_fee', 'exit_fee', 'total_funding_fee_paid', 'total_margin_amount', 'auto_invest_margin_amount'] as $optionalColumn) {
                if (\Schema::hasColumn('futures_contract', $optionalColumn)) {
                    $futuresSelect[] = $optionalColumn;
                }
            }

            $futuresById = $sourceIds->isNotEmpty()
                ? \DB::table('futures_contract')
                ->whereIn('id', $sourceIds->all())
                ->whereIn('status', ['closed', 'liquidated'])
                ->select($futuresSelect)
                ->get()
                ->keyBy(function ($row) {
                    return (string) ($row->futures_id ?? '');
                })
                : collect();

            $futuresLockRows = $lockRows
                ->map(function ($row) use ($futuresById) {
                    $future = $futuresById->get((string) ($row->futures_id ?? ''));

                    return $future
                        ? (object) array_merge((array) $row, (array) $future)
                        : null;
                })
                ->filter()
                ->values();

            if ($futuresLockRows->isNotEmpty()) {
                $extraCurrencyIds = $futuresLockRows
                    ->pluck('lock_currency_id')
                    ->merge($futuresLockRows->pluck('quote_currency_id'))
                    ->filter()
                    ->unique()
                    ->diff($currencies->keys())
                    ->values();

                if ($extraCurrencyIds->isNotEmpty()) {
                    $currencies = $currencies->merge(
                        \App\Models\Currency\Currency::whereIn('id', $extraCurrencyIds)->get()->keyBy('id')
                    );
                }

                $currencyUsdPrices = [];
                $getCurrencyUsdPrice = function ($currencyId) use (&$currencyUsdPrices, $currencies, $currencyRepository) {
                    $currencyId = (int) $currencyId;

                    if ($currencyId <= 0) {
                        return 1.0;
                    }

                    if (!array_key_exists($currencyId, $currencyUsdPrices)) {
                        $currency = $currencies->get($currencyId);
                        $price = $currency ? (float) $currencyRepository->currencyPriceInUsd($currency) : 1.0;

                        $currencyUsdPrices[$currencyId] = $price > 0 ? $price : 1.0;
                    }

                    return $currencyUsdPrices[$currencyId];
                };

                $futuresLockUsdTotals = [];
                $futuresMarginBasisUsd = [];
                $futuresRealizedPnlUsd = [];
                $lockRowsWithUsd = [];

                foreach ($futuresLockRows as $row) {
                    $futuresId = (string) ($row->futures_id ?? '');
                    $orderId = (int) ($row->auto_invest_order_id ?? 0);
                    $lockAmount = (float) ($row->lock_amount ?? 0);
                    $lockUsd = $lockAmount * $getCurrencyUsdPrice($row->lock_currency_id ?? 0);

                    if ($futuresId === '' || $orderId <= 0 || $lockUsd <= 0) {
                        continue;
                    }

                    if (!isset($futuresLockUsdTotals[$futuresId])) {
                        $futuresLockUsdTotals[$futuresId] = 0;
                    }

                    $futuresLockUsdTotals[$futuresId] += $lockUsd;
                    $lockRowsWithUsd[] = [
                        'futures_id' => $futuresId,
                        'order_id' => $orderId,
                        'lock_usd' => $lockUsd,
                    ];

                    if (!array_key_exists($futuresId, $futuresRealizedPnlUsd)) {
                        $quoteUsdPrice = $getCurrencyUsdPrice($row->quote_currency_id ?? 0);
                        $balance = (float) ($row->balance ?? 0);
                        $pnlPercent = (float) ($row->pnl ?? 0);
                        $pnlAmount = $balance * ($pnlPercent / 100);
                        $entryFee = (float) ($row->entry_fee ?? 0);
                        $exitFee = (float) ($row->exit_fee ?? 0);
                        $fundingFee = (float) ($row->total_funding_fee_paid ?? 0);
                        $totalMarginAmount = (float) ($row->total_margin_amount ?? 0);
                        $marginBasisAmount = $totalMarginAmount > 0
                            ? $totalMarginAmount
                            : max(0, $balance + $entryFee);

                        /*
                         * futures_contract.pnl 保存的是收益百分比，页面交易详情里的“总收益”
                         * = 平仓收益金额 - 开仓手续费 - 平仓手续费 - 资金费。
                         */
                        $futuresRealizedPnlUsd[$futuresId] = ($pnlAmount - $entryFee - $exitFee - $fundingFee) * $quoteUsdPrice;
                        $futuresMarginBasisUsd[$futuresId] = $marginBasisAmount * $quoteUsdPrice;
                    }
                }

                foreach ($lockRowsWithUsd as $row) {
                    $futuresId = $row['futures_id'];
                    $orderId = $row['order_id'];
                    $totalLockUsd = $futuresLockUsdTotals[$futuresId] ?? 0;
                    $marginBasisUsd = $futuresMarginBasisUsd[$futuresId] ?? 0;

                    if ($totalLockUsd <= 0) {
                        continue;
                    }

                    /*
                     * 这里只按理财保证金在整笔合约本金里的占比分摊合约盈亏。
                     * 之前使用 lock_usd / total_auto_invest_lock_usd，会把一笔混合仓位的
                     * 全部盈亏都分到理财订单上，导致理财订单亏损远大于实际理财保证金。
                     */
                    $allocationBasisUsd = max($marginBasisUsd, $totalLockUsd);

                    if ($allocationBasisUsd <= 0) {
                        continue;
                    }

                    $pnlRatio = max(0, min(1, $row['lock_usd'] / $allocationBasisUsd));
                    $allocatedPnlUsd = ($futuresRealizedPnlUsd[$futuresId] ?? 0) * $pnlRatio;
                    $futuresPnlByOrder[$orderId] = (float) ($futuresPnlByOrder[$orderId] ?? 0) + $allocatedPnlUsd;
                }
            }
        }

        foreach ($displayOrders as $order) {
            $currency = $currencies->get($order->currency_id);

            if (!$currency) {
                continue;
            }

            $assetStatus = strtolower(trim((string) ($order->status ?? 'active')));
            $assetStatus = $assetStatus !== '' ? $assetStatus : 'active';
            $assetIsActive = $assetStatus === 'active';

            $assetAmount = (float) ($order->amount ?? 0);
            $assetOriginalPrincipalAmount = (float) ($order->principal_amount ?? 0);
            $assetRedeemedAmount = (float) ($order->redeemed_amount ?? 0);
            $assetConsumedAmount = 0;

            if ($assetOriginalPrincipalAmount <= 0) {
                $assetConsumedAmount = (float) ($consumedAmountsByOrder[$order->id] ?? 0);
                $assetOriginalPrincipalAmount = $assetAmount + max(0, $assetConsumedAmount);

                if ($assetOriginalPrincipalAmount <= 0 && $assetRedeemedAmount > 0) {
                    $assetOriginalPrincipalAmount = $assetRedeemedAmount;
                }
            }

            $assetUsedMargin = (float) ($order->used_margin ?? 0);
            $assetEarningAmount = max(0, $assetAmount - $assetUsedMargin);

            $priceUsd = (float) $currencyRepository->currencyPriceInUsd($currency);
            $assetPrincipalUsd = $assetAmount * $priceUsd;
            $assetOriginalPrincipalUsd = $assetOriginalPrincipalAmount * $priceUsd;
            $assetUsedMarginUsd = $assetUsedMargin * $priceUsd;
            $assetEarningPrincipalUsd = $assetEarningAmount * $priceUsd;
            $assetRedeemedAmountUsd = $assetRedeemedAmount * $priceUsd;
            $assetDisplayValueUsd = max(
                $assetPrincipalUsd,
                $assetOriginalPrincipalUsd,
                $assetUsedMarginUsd,
                $assetRedeemedAmountUsd
            );

            if ($assetDisplayValueUsd <= 0) {
                continue;
            }

            $assetStartAt = $order->started_at ?? null;
            $assetDays = (int) ($order->days ?: $autoInvestDays);

            if (!in_array($assetDays, [30, 90, 180, 365], true)) {
                $assetDays = $autoInvestDays;
            }

            $assetInvestmentType = $order->investment_type ?: $autoInvestType;

            if (!in_array($assetInvestmentType, ['flexible', 'fixed'])) {
                $assetInvestmentType = 'fixed';
            }

            $assetRateMap = $assetInvestmentType === 'flexible' ? $flexibleRateMap : $fixedRateMap;
            $assetConfiguredRate = (float) ($assetRateMap[$assetDays] ?? 0);
            $assetStoredRate = (float) ($order->rate ?: 0);
            $assetRate = $assetConfiguredRate > 0
                ? $assetConfiguredRate
                : ($assetStoredRate > 0 ? $assetStoredRate : $selectedRate);
            $assetVipLevel = (int) ($order->vip_level ?: $vipLevel);
            $storedAssetVipBoostRate = isset($order->vip_boost_rate) && is_numeric($order->vip_boost_rate)
                ? (float) $order->vip_boost_rate
                : 0;
            $assetVipBoostRate = $storedAssetVipBoostRate > 0
                ? $storedAssetVipBoostRate
                : (float) $vipYieldBoostRate;
            $assetBoostedRate = $assetRate * (1 + ($assetVipBoostRate / 100));

            /**
             * 0 点结算模式：
             * daily_profit_percent 是该订单每天实际加减收益的百分比，可为负数。
             * settled_profit_amount 是该订单已经结算出来的量化累计收益。
             * total_profit 在旧数据里可能混入合约占用/结算后的净结果，不能作为量化收益展示来源。
             */
            $assetDailyProfitPercent = (float) ($order->daily_profit_percent ?? 0);
            $assetSettledProfitAmount = 0;

            if (isset($order->settled_profit_amount) && (float) $order->settled_profit_amount > 0) {
                $assetSettledProfitAmount = (float) $order->settled_profit_amount;
            }

            $assetSettledProfitUsd = $assetSettledProfitAmount * $priceUsd;

            $assetElapsedDays = 0;
            $assetEarningDays = 0;

            $assetBaseEarnedRate = 0;
            $assetEarnedRate = 0;

            $assetBaseCurrentEarningsUsd = 0;
            $assetVipCurrentBonusUsd = 0;
            $assetCurrentEarningsUsd = 0;
            $assetQuantCurrentEarningsUsd = 0;
            $assetFuturesPnlUsd = 0;

            $assetBaseMaturityEarningsUsd = 0;
            $assetVipMaturityBonusUsd = 0;
            $assetMaturityEarningsUsd = 0;

            $assetMaturityTotalUsd = $assetPrincipalUsd;
            $assetMaturityDate = null;
            $assetProgressPercent = 0;
            $assetCurrentTotalProfitPercent = 0;

            $assetIsMatured = false;
            $assetIsRedeemable = false;
            $assetRedeemablePrincipalUsd = 0;
            $assetRedeemableBaseProfitUsd = 0;
            $assetRedeemableVipProfitUsd = 0;
            $assetRedeemableProfitUsd = 0;
            $assetRedeemableAmountUsd = 0;

            if ($assetStartAt && $assetDays > 0) {
                $startDate = \Carbon\Carbon::parse($assetStartAt);
                $now = \Carbon\Carbon::now();

                $elapsedSeconds = max(0, $startDate->diffInSeconds($now, false));
                $assetElapsedDays = (int) floor($elapsedSeconds / 86400);
                $assetEarningDays = min($assetElapsedDays, $assetDays);

                /**
                 * 注意：被合约占用的理财本金不继续计算理财收益。
                 * 未被占用的理财本金继续计算收益。
                 */
                $assetBaseEarnedRate = $assetRate * $assetEarningDays;
                $assetEarnedRate = $assetBaseEarnedRate * (1 + ($assetVipBoostRate / 100));

                $assetBaseCurrentEarningsUsd = max(0, $assetEarningPrincipalUsd * ($assetBaseEarnedRate / 100));
                $assetVipCurrentBonusUsd = max(0, $assetBaseCurrentEarningsUsd * ($assetVipBoostRate / 100));
                $assetCurrentEarningsUsd = $assetBaseCurrentEarningsUsd + $assetVipCurrentBonusUsd;
                $assetQuantCurrentEarningsUsd = $assetCurrentEarningsUsd;

                /*
                 * 已经结算出来的量化收益不能因为本金之后被合约占用而归零。
                 * 量化收益业务上不能为负，settled_profit_amount 为负数时通常是合约亏损
                 * 或旧数据净值混入了合约结果，不能覆盖量化收益展示。
                 */
                if ($assetSettledProfitUsd > 0) {
                    $assetQuantCurrentEarningsUsd = $assetSettledProfitUsd;

                    $boostDivisor = 1 + ($assetVipBoostRate / 100);

                    if (abs($boostDivisor) > 0.00000001) {
                        $assetBaseCurrentEarningsUsd = max(0, $assetSettledProfitUsd / $boostDivisor);
                        $assetVipCurrentBonusUsd = max(0, $assetSettledProfitUsd - $assetBaseCurrentEarningsUsd);
                    } else {
                        $assetBaseCurrentEarningsUsd = max(0, $assetSettledProfitUsd);
                        $assetVipCurrentBonusUsd = 0;
                    }

                    $assetCurrentEarningsUsd = max(0, $assetQuantCurrentEarningsUsd);
                }

                /*
                 * 合约盈亏展示与该理财订单关联的已平仓/爆仓合约实际结算结果。
                 * 如果旧数据没有合约明细，再回退到 consumed_amount，避免无开仓时误反推亏损。
                 */
                $assetRealizedFuturesPnlUsd = (float) ($futuresPnlByOrder[$order->id] ?? 0);
                $assetConsumedFuturesPnlUsd = $assetConsumedAmount > 0
                    ? 0 - ($assetConsumedAmount * $priceUsd)
                    : 0;
                $assetFuturesPnlUsd = $assetRealizedFuturesPnlUsd != 0
                    ? $assetRealizedFuturesPnlUsd
                    : $assetConsumedFuturesPnlUsd;
                $assetCurrentEarningsUsd = $assetQuantCurrentEarningsUsd;

                $assetProfitPercentBaseUsd = $assetEarningPrincipalUsd > 0
                    ? $assetEarningPrincipalUsd
                    : ($assetOriginalPrincipalUsd > 0 ? $assetOriginalPrincipalUsd : $assetPrincipalUsd);

                $assetCurrentTotalProfitPercent = $assetProfitPercentBaseUsd > 0
                    ? ($assetCurrentEarningsUsd / $assetProfitPercentBaseUsd) * 100
                    : 0;

                $assetBaseMaturityEarningsUsd = $assetEarningPrincipalUsd * ($assetRate / 100) * $assetDays;
                $assetVipMaturityBonusUsd = $assetBaseMaturityEarningsUsd * ($assetVipBoostRate / 100);
                $assetMaturityEarningsUsd = $assetBaseMaturityEarningsUsd + $assetVipMaturityBonusUsd;

                $assetMaturityTotalUsd = $assetPrincipalUsd + $assetMaturityEarningsUsd;

                $assetMaturityDate = $startDate->copy()
                    ->addDays($assetDays)
                    ->format('Y-m-d H:i:s');

                if ($assetMaturityEarningsUsd > 0) {
                    $assetProgressPercent = min(100, ($assetCurrentEarningsUsd / $assetMaturityEarningsUsd) * 100);
                } else {
                    $assetProgressPercent = min(100, ($assetEarningDays / $assetDays) * 100);
                }

                $assetIsMatured = $assetElapsedDays >= $assetDays;
                $assetIsRedeemable = $assetInvestmentType === 'flexible' || $assetIsMatured;

                /**
                 * 页面展示用“可兑换金额”：
                 * - 每天 00:00 结算后，K 线只展示每日合并值。
                 * - 可兑换金额按订单规则独立计算。
                 * - 被合约占用的本金不计入可兑换本金。
                 */
                if ($assetIsRedeemable && $assetEarningPrincipalUsd > 0) {
                    $assetRedeemablePrincipalUsd = $assetEarningPrincipalUsd;

                    if ($assetInvestmentType === 'flexible') {
                        if ($assetElapsedDays >= $assetDays) {
                            $assetRedeemableBaseProfitUsd = $assetEarningPrincipalUsd * ($assetRate / 100) * $assetDays;
                        } else {
                            $assetRedeemableBaseProfitUsd = $assetEarningPrincipalUsd * ($assetRate / 100) * $assetEarningDays;
                            $assetRedeemableBaseProfitUsd = $assetRedeemableBaseProfitUsd / 2;
                        }
                    }

                    if ($assetInvestmentType === 'fixed' && $assetIsMatured) {
                        $assetRedeemableBaseProfitUsd = $assetEarningPrincipalUsd * ($assetRate / 100) * $assetDays;
                    }

                    $assetRedeemableVipProfitUsd = $assetRedeemableBaseProfitUsd * ($assetVipBoostRate / 100);
                    $assetRedeemableProfitUsd = $assetRedeemableBaseProfitUsd + $assetRedeemableVipProfitUsd;
                    $assetRedeemableAmountUsd = $assetRedeemablePrincipalUsd + $assetRedeemableProfitUsd;
                }

                if ($assetIsActive && (!$startAt || $startDate->lt(\Carbon\Carbon::parse($startAt)))) {
                    $startAt = $startDate->format('Y-m-d H:i:s');
                }

                if ($assetIsActive && (!$maturityDate || \Carbon\Carbon::parse($assetMaturityDate)->gt(\Carbon\Carbon::parse($maturityDate)))) {
                    $maturityDate = $assetMaturityDate;
                }
            }

            if (!$assetIsActive) {
                $assetIsRedeemable = false;
                $assetRedeemablePrincipalUsd = 0;
                $assetRedeemableBaseProfitUsd = 0;
                $assetRedeemableVipProfitUsd = 0;
                $assetRedeemableProfitUsd = 0;
                $assetRedeemableAmountUsd = 0;
            }

            if ($assetIsActive) {
                $principalUsd += $assetPrincipalUsd;
                $earningPrincipalUsd += $assetEarningPrincipalUsd;
                $usedMarginUsd += $assetUsedMarginUsd;

                $baseCurrentEarningsUsd += $assetBaseCurrentEarningsUsd;
                $vipCurrentBonusUsd += $assetVipCurrentBonusUsd;
                $currentEarningsUsd += $assetCurrentEarningsUsd;
                $quantCurrentEarningsUsd += $assetQuantCurrentEarningsUsd;
                $futuresPnlUsd += $assetFuturesPnlUsd;

                $baseMaturityEarningsUsd += $assetBaseMaturityEarningsUsd;
                $vipMaturityBonusUsd += $assetVipMaturityBonusUsd;
                $maturityEarningsUsd += $assetMaturityEarningsUsd;

                $maturityTotalUsd += $assetMaturityTotalUsd;

                $weightedEarningDaysTotal += $assetPrincipalUsd * $assetEarningDays;
                $weightedElapsedDaysTotal += $assetPrincipalUsd * $assetElapsedDays;

                $activeOrderCount++;

                if ($assetIsMatured) {
                    $maturedOrderCount++;
                }

                if ($assetIsRedeemable && $assetRedeemableAmountUsd > 0) {
                    $redeemableOrderCount++;
                    $redeemablePrincipalUsd += $assetRedeemablePrincipalUsd;
                    $redeemableProfitUsd += $assetRedeemableProfitUsd;
                    $redeemableAmountUsd += $assetRedeemableAmountUsd;
                }
            }

            $assets[] = [
                'order_id' => $order->id,
                'order_no' => $order->order_no ?? null,
                'status' => $assetStatus,
                'is_active' => $assetIsActive,
                'investment_type' => $assetInvestmentType,
                'days' => $assetDays,
                'symbol' => $currency->symbol,
                'amount' => round($assetAmount, 8),
                'principal_amount' => round($assetOriginalPrincipalAmount, 8),
                'futures_consumed_amount' => round($assetConsumedAmount, 8),
                'used_margin_amount' => round($assetUsedMargin, 8),
                'earning_amount' => round($assetEarningAmount, 8),
                'price_usd' => round($priceUsd, 8),
                'principal_usd' => round($assetPrincipalUsd, 8),
                'original_principal_usd' => round($assetOriginalPrincipalUsd, 8),
                'used_margin_usd' => round($assetUsedMarginUsd, 8),
                'earning_principal_usd' => round($assetEarningPrincipalUsd, 8),
                'start_at' => $assetStartAt ? \Carbon\Carbon::parse($assetStartAt)->format('Y-m-d H:i:s') : null,
                'maturity_date' => $assetMaturityDate,
                'elapsed_days' => $assetElapsedDays,
                'earning_days' => $assetEarningDays,

                'base_rate' => round($assetRate, 8),
                'vip_level' => $assetVipLevel,
                'vip_boost_rate' => round($assetVipBoostRate, 8),
                'boosted_rate' => round($assetBoostedRate, 8),

                'base_earned_rate' => round($assetBaseEarnedRate, 8),
                'earned_rate' => round($assetEarnedRate, 8),

                'base_current_earnings_usd' => round($assetBaseCurrentEarningsUsd, 8),
                'vip_current_bonus_usd' => round($assetVipCurrentBonusUsd, 8),
                'current_earnings_usd' => round($assetCurrentEarningsUsd, 8),
                'quant_current_earnings_usd' => round($assetQuantCurrentEarningsUsd, 8),
                'futures_pnl_usd' => round($assetFuturesPnlUsd, 8),
                'futures_total_profit_usd' => round($assetFuturesPnlUsd, 8),

                'base_maturity_earnings_usd' => round($assetBaseMaturityEarningsUsd, 8),
                'vip_maturity_bonus_usd' => round($assetVipMaturityBonusUsd, 8),
                'maturity_earnings_usd' => round($assetMaturityEarningsUsd, 8),

                'maturity_total_usd' => round($assetMaturityTotalUsd, 8),
                'progress_percent' => round($assetProgressPercent, 2),

                /**
                 * 订单收益百分比展示字段：
                 * current_total_profit_percent = 当前累计收益 / 可计息本金 * 100。
                 */
                'daily_profit_percent' => round($assetDailyProfitPercent != 0 ? $assetDailyProfitPercent : $assetBoostedRate, 8),
                'settled_profit_amount_usd' => round($assetSettledProfitUsd, 8),
                'current_total_profit_percent' => round($assetCurrentTotalProfitPercent, 8),
                'current_profit_percent' => round($assetCurrentTotalProfitPercent, 8),
                'total_profit_percent' => round($assetCurrentTotalProfitPercent, 8),

                'is_matured' => $assetIsMatured,
                'is_redeemable' => $assetIsActive && $assetIsRedeemable && $assetRedeemableAmountUsd > 0,
                'redeemable_principal_usd' => round($assetRedeemablePrincipalUsd, 8),
                'redeemable_base_profit_usd' => round($assetRedeemableBaseProfitUsd, 8),
                'redeemable_vip_profit_usd' => round($assetRedeemableVipProfitUsd, 8),
                'redeemable_profit_usd' => round($assetRedeemableProfitUsd, 8),
                'redeemable_amount_usd' => round($assetRedeemableAmountUsd, 8),
            ];
        }

        if ($principalUsd > 0) {
            $elapsedDays = (int) floor($weightedElapsedDaysTotal / $principalUsd);
            $earningDays = (int) floor($weightedEarningDaysTotal / $principalUsd);

            $baseEarnedRate = $selectedRate * $earningDays;
            $earnedRate = $baseEarnedRate * (1 + ($vipYieldBoostRate / 100));

            if ($maturityEarningsUsd > 0) {
                $progressPercent = min(100, ($currentEarningsUsd / $maturityEarningsUsd) * 100);
            }
        }

        $currentProfitPercent = $earningPrincipalUsd > 0
            ? ($currentEarningsUsd / $earningPrincipalUsd) * 100
            : 0;

        /**
         * 每天 00:00 结算后，K 线只需要一笔“每日收益百分比”。
         * 页面不再使用金额作为纵轴基数，而使用 weighted_profit_percent / avg_profit_percent。
         * 如果存在 auto_invest_daily_snapshots，则优先读取快照，保证历史曲线不漂移。
         */
        $dailyPoints = [];

        if (\Schema::hasTable('auto_invest_daily_snapshots')) {
            $snapshotQuery = \DB::table('auto_invest_daily_snapshots')
                ->where('user_id', $user->id)
                ->orderBy('snapshot_date');

            if ($currencyIds->isNotEmpty() && \Schema::hasColumn('auto_invest_daily_snapshots', 'currency_id')) {
                $snapshotQuery->whereIn('currency_id', $currencyIds->all());
            }

            $snapshotRows = $snapshotQuery->get();
            $snapshotMap = [];

            foreach ($snapshotRows as $row) {
                $dateKey = (string) ($row->snapshot_date ?? '');

                if ($dateKey === '') {
                    continue;
                }

                $profitPercent = 0;
                $snapshotPrincipal = (float) ($row->total_principal ?? 0);
                $snapshotProfit = (float) ($row->total_current_profit ?? ($row->total_profit ?? 0));

                if (property_exists($row, 'weighted_profit_percent')) {
                    $profitPercent = (float) ($row->weighted_profit_percent ?? 0);
                } elseif (property_exists($row, 'avg_profit_percent')) {
                    $profitPercent = (float) ($row->avg_profit_percent ?? 0);
                } elseif (property_exists($row, 'profit_percent')) {
                    $profitPercent = (float) ($row->profit_percent ?? 0);
                } elseif (property_exists($row, 'daily_profit_percent')) {
                    $profitPercent = (float) ($row->daily_profit_percent ?? 0);
                } elseif ($snapshotPrincipal > 0) {
                    $profitPercent = ($snapshotProfit / $snapshotPrincipal) * 100;
                }

                if (!isset($snapshotMap[$dateKey])) {
                    $snapshotMap[$dateKey] = [
                        'date' => $dateKey,
                        'weighted_percent_numerator' => 0,
                        'weighted_percent_weight' => 0,
                        'avg_percent_total' => 0,
                        'avg_percent_count' => 0,
                        'principal' => 0,
                        'earnings' => 0,
                        'active_order_count' => 0,
                        'matured_order_count' => 0,
                        'redeemable_order_count' => 0,
                        'redeemable_amount' => 0,
                        'used_margin' => 0,
                    ];
                }

                $snapshotMap[$dateKey]['weighted_percent_numerator'] += $profitPercent * max(0, $snapshotPrincipal);
                $snapshotMap[$dateKey]['weighted_percent_weight'] += max(0, $snapshotPrincipal);
                $snapshotMap[$dateKey]['avg_percent_total'] += $profitPercent;
                $snapshotMap[$dateKey]['avg_percent_count'] += 1;
                $snapshotMap[$dateKey]['principal'] += $snapshotPrincipal;
                $snapshotMap[$dateKey]['earnings'] += $snapshotProfit;
                $snapshotMap[$dateKey]['active_order_count'] += (int) ($row->active_order_count ?? 0);
                $snapshotMap[$dateKey]['matured_order_count'] += (int) ($row->matured_order_count ?? 0);
                $snapshotMap[$dateKey]['redeemable_order_count'] += (int) ($row->redeemable_order_count ?? 0);
                $snapshotMap[$dateKey]['redeemable_amount'] += (float) ($row->redeemable_amount ?? 0);
                $snapshotMap[$dateKey]['used_margin'] += (float) ($row->used_margin ?? 0);
            }

            ksort($snapshotMap);

            foreach ($snapshotMap as $item) {
                $weightedPercent = $item['weighted_percent_weight'] > 0
                    ? $item['weighted_percent_numerator'] / $item['weighted_percent_weight']
                    : ($item['avg_percent_count'] > 0 ? $item['avg_percent_total'] / $item['avg_percent_count'] : 0);
                $avgPercent = $item['avg_percent_count'] > 0
                    ? $item['avg_percent_total'] / $item['avg_percent_count']
                    : $weightedPercent;

                $dailyPoints[] = [
                    'date' => $item['date'],
                    'value' => round((float) $weightedPercent, 8),
                    'yield_percent' => round((float) $weightedPercent, 8),
                    'weighted_profit_percent' => round((float) $weightedPercent, 8),
                    'avg_profit_percent' => round((float) $avgPercent, 8),
                    'principal' => round((float) $item['principal'], 8),
                    'earnings' => round((float) $item['earnings'], 8),
                    'active_order_count' => (int) $item['active_order_count'],
                    'matured_order_count' => (int) $item['matured_order_count'],
                    'redeemable_order_count' => (int) $item['redeemable_order_count'],
                    'redeemable_amount' => round((float) $item['redeemable_amount'], 8),
                    'used_margin' => round((float) $item['used_margin'], 8),
                ];
            }
        }

        /**
         * 没有快照表或还没生成快照时，使用当前订单临时构建每日点位。
         * 这只是兜底展示，正式历史曲线应该由每天 00:00 的快照数据提供。
         */
        if (empty($dailyPoints)) {
            $dailyValues = [];

            foreach ($assets as $asset) {
                if (array_key_exists('is_active', $asset) && !$asset['is_active']) {
                    continue;
                }

                if (empty($asset['start_at'])) {
                    continue;
                }

                $assetStartDate = \Carbon\Carbon::parse($asset['start_at'])->startOfDay();
                $todayDate = \Carbon\Carbon::now()->startOfDay();

                if ($assetStartDate->gt($todayDate)) {
                    continue;
                }

                $visibleDays = max(0, $assetStartDate->diffInDays($todayDate, false));

                $principal = (float) ($asset['principal_usd'] ?? 0);
                $earningPrincipal = (float) ($asset['earning_principal_usd'] ?? $principal);
                $baseRate = (float) ($asset['base_rate'] ?? 0);
                $vipBoost = (float) ($asset['vip_boost_rate'] ?? 0);

                $periodDays = 30;
                if (!empty($asset['maturity_date'])) {
                    $periodDays = max(1, $assetStartDate->diffInDays(\Carbon\Carbon::parse($asset['maturity_date'])->startOfDay(), false));
                }

                if ($principal <= 0 || $periodDays <= 0) {
                    continue;
                }

                for ($i = 0; $i <= $visibleDays; $i++) {
                    $date = $assetStartDate->copy()->addDays($i);
                    $dateKey = $date->format('Y-m-d');
                    $earningDay = min($i, $periodDays);

                    $baseProfit = $earningPrincipal * ($baseRate / 100) * $earningDay;
                    $vipProfit = $baseProfit * ($vipBoost / 100);
                    $profit = $baseProfit + $vipProfit;

                    if (!isset($dailyValues[$dateKey])) {
                        $dailyValues[$dateKey] = [
                            'principal' => 0,
                            'earnings' => 0,
                            'active_order_count' => 0,
                        ];
                    }

                    $dailyValues[$dateKey]['principal'] += $principal;
                    $dailyValues[$dateKey]['earnings'] += $profit;
                    $dailyValues[$dateKey]['active_order_count'] += 1;
                }
            }

            ksort($dailyValues);

            foreach ($dailyValues as $dateKey => $item) {
                $yieldPercent = ((float) $item['principal']) > 0
                    ? ((float) $item['earnings'] / (float) $item['principal']) * 100
                    : 0;

                $dailyPoints[] = [
                    'date' => $dateKey,
                    'value' => round((float) $yieldPercent, 8),
                    'yield_percent' => round((float) $yieldPercent, 8),
                    'weighted_profit_percent' => round((float) $yieldPercent, 8),
                    'avg_profit_percent' => round((float) $yieldPercent, 8),
                    'principal' => round((float) $item['principal'], 8),
                    'earnings' => round((float) $item['earnings'], 8),
                    'active_order_count' => (int) $item['active_order_count'],
                    'matured_order_count' => 0,
                    'redeemable_order_count' => 0,
                    'redeemable_amount' => 0,
                    'used_margin' => 0,
                ];
            }
        }

        $autoInvestEarnings = [
            'enabled' => $autoInvestFunding,
            'symbol' => 'USD',
            'principal' => round($principalUsd, 8),
            'earning_principal' => round($earningPrincipalUsd, 8),
            'used_margin' => round($usedMarginUsd, 8),
            'days' => $autoInvestDays,

            /**
             * rate 保持给前端兼容，已经是 VIP 加成后的收益率。
             */
            'rate' => round($selectedBoostedRate, 8),
            'base_rate' => round($selectedRate, 8),
            'vip_level' => $vipLevel,
            'vip_boost_rate' => round($vipYieldBoostRate, 8),
            'boosted_rate' => round($selectedBoostedRate, 8),

            'start_at' => $startAt,
            'maturity_date' => $maturityDate,
            'elapsed_days' => $elapsedDays,
            'earning_days' => $earningDays,

            'base_earned_rate' => round($baseEarnedRate, 8),
            'earned_rate' => round($earnedRate, 8),

            'base_current_earnings' => round($baseCurrentEarningsUsd, 8),
            'vip_current_bonus' => round($vipCurrentBonusUsd, 8),
            'current_earnings' => round($currentEarningsUsd, 8),
            'quant_current_earnings' => round($quantCurrentEarningsUsd, 8),
            'futures_pnl' => round($futuresPnlUsd, 8),
            'futures_total_profit' => round($futuresPnlUsd, 8),
            'total_current_earnings' => round($quantCurrentEarningsUsd, 8),
            'net_current_earnings' => round($quantCurrentEarningsUsd + $futuresPnlUsd, 8),
            'current_profit_percent' => round($currentProfitPercent, 8),
            'current_total_profit_percent' => round($currentProfitPercent, 8),

            'base_maturity_earnings' => round($baseMaturityEarningsUsd, 8),
            'vip_maturity_bonus' => round($vipMaturityBonusUsd, 8),
            'maturity_earnings' => round($maturityEarningsUsd, 8),

            'maturity_total' => round($maturityTotalUsd, 8),
            'progress_percent' => round($progressPercent, 2),

            'active_order_count' => $activeOrderCount,
            'current_order_count' => $activeOrderCount,
            'matured_order_count' => $maturedOrderCount,
            'redeemable_order_count' => $redeemableOrderCount,
            'redeemable_principal' => round($redeemablePrincipalUsd, 8),
            'redeemable_profit' => round($redeemableProfitUsd, 8),
            'redeemable_amount' => round($redeemableAmountUsd, 8),

            'assets' => $assets,
            'orders' => $assets,

            /**
             * daily_points / chart_points 是新结构：每天只有一笔合并收益百分比。
             * value / yield_percent 使用 weighted_profit_percent，不再使用金额作为 K 线纵轴基数。
             */
            'daily_points' => $dailyPoints,
            'chart_points' => $dailyPoints,
            'candles' => $dailyPoints,
        ];

        return [
            'autoInvestFunding' => $autoInvestFunding,
            'autoInvestDays' => $autoInvestDays,
            'autoInvestType' => $autoInvestType,
            'autoInvestAmount' => $autoInvestAmount,
            'userVipLevel' => $vipLevel,
            'vipYieldBoostRate' => round($vipYieldBoostRate, 8),
            'lcSettings' => $lcSettings,
            'autoInvestEarnings' => $autoInvestEarnings,
        ];
    }


    /**
     * Trading wallets page clone of Wallets page
     */
    public function trading()
    {
        return Inertia::render('Wallet/WalletsTrading');
    }

    public function investfunding()
    {
        return Inertia::render('Wallet/Investfunding');
    }

    /**
     * Trading wallets page clone of Wallets page
     */
    public function tradingLite()
    {
        return Inertia::render('WalletLite/WalletsTrading');
    }

    public function investfundingLite()
    {
        return Inertia::render('WalletLite/Investfunding');
    }

    /**
     * Transfer between Funding and Trading accounts page
     */
    public function transfer()
    {
        $currencyRepository = new CurrencyRepository();
        $currencyCollection = $currencyRepository->all(false);

        $currencies = [];
        foreach ($currencyCollection as $currency) {
            $currencies[$currency->id] = [
                'name' => $currency->symbol,
                'id' => $currency->id,
                'logo' => $currency->logo_path
            ];
        }

        $user = auth()->user();

        return Inertia::render('Wallet/Transfer', [
            'currencies' => $currencies,
            'umiStockTransfers' => \App\Services\Umi\V2\FundedRuntime::schemaReady()
                ? \Illuminate\Support\Facades\DB::table('umi_v2_stock_share_moves')
                    ->where('kind', 'exchange_transfer')->where('exchange_user_id', $user->id)
                    ->orderByDesc('id')->limit(30)->get(['id', 'shares', 'created_at']) : [],
            'transferCommissionPercent' => Setting::get('trade.transfer_commission_percent', 0),
            'userVip' => (int) ($user->vip ?? 0),
        ]);
    }

    public function saveAutoInvest(Request $request)
{
    $request->validate([
        'status' => 'required|boolean',
        'days' => 'nullable|integer',
        'investment_type' => 'nullable|string|in:flexible,fixed',
        'amount' => 'nullable|numeric|min:0',

        /*
         * 前端选择的代币 ID。
         */
        'currency_id' => 'nullable|integer',
        'symbol' => 'nullable|string',
        'currency' => 'nullable|string',
    ]);

    if (!\Schema::hasTable('auto_invest_orders')) {
        throw \Illuminate\Validation\ValidationException::withMessages([
            'amount' => ['Auto invest orders table not found. Please run the migration first.'],
        ]);
    }

    $user = auth()->user();

    /*
     * 这里不再固定 USDT currency_id = 2。
     * 优先使用前端传来的 currency_id。
     * 如果前端没传 currency_id，则尝试用 symbol / currency 去 currencies 表匹配。
     * 最后兜底才使用 2。
     */
    $currencyId = (int) $request->input('currency_id', 0);

    if ($currencyId <= 0) {
        $symbol = strtoupper(trim((string) ($request->input('symbol') ?: $request->input('currency'))));

        if ($symbol !== '') {
            $currencyId = (int) \DB::table('currencies')
                ->whereRaw('UPPER(symbol) = ?', [$symbol])
                ->value('id');
        }
    }

    if ($currencyId <= 0) {
        $currencyId = 2;
    }

    $currency = \DB::table('currencies')
        ->select(['id', 'symbol'])
        ->where('id', $currencyId)
        ->first();

    if (!$currency) {
        return response()->json([
            'message' => 'Invalid currency.',
            'errors' => [
                'currency_id' => ['Invalid currency.'],
            ],
        ], 422);
    }

    $currencySymbol = strtoupper(trim((string) $currency->symbol));

    $status = (int) $request->input('status', 0);
    $days = (int) $request->input('days', 0);
    $investmentType = $request->input('investment_type', 'fixed');
    $amount = (float) $request->input('amount', 0);

    if ($status === 1) {
        if (!in_array($days, [30, 90, 180, 365], true)) {
            return response()->json([
                'message' => 'Invalid invest funding time',
                'errors' => [
                    'days' => ['Invalid invest funding time'],
                ],
            ], 422);
        }

        if (!in_array($investmentType, ['flexible', 'fixed'])) {
            return response()->json([
                'message' => 'Invalid investment mode.',
                'errors' => [
                    'investment_type' => ['Invalid investment mode.'],
                ],
            ], 422);
        }

        if ($amount <= 0) {
            return response()->json([
                'message' => 'Please enter a valid investment amount.',
                'errors' => [
                    'amount' => ['Please enter a valid investment amount.'],
                ],
            ], 422);
        }
    }

    $responseData = [
        'message' => 'Saved successfully',
        'auto_invest_funding' => false,
        'invest_funding_time' => 0,
        'investment_type' => null,
        'amount' => 0,
        'currency_id' => $currencyId,
        'symbol' => $currencySymbol,
    ];

    \DB::transaction(function () use (
        $user,
        $status,
        $days,
        $investmentType,
        $amount,
        $currencyId,
        $currencySymbol,
        &$responseData
    ) {
        $now = now();

        $settingRows = \DB::table('settings')
            ->whereIn('key', [
                'trade.lc30',
                'trade.lc90',
                'trade.lc180',
                'trade.lc365',
                'trade.lc_dq30',
                'trade.lc_dq90',
                'trade.lc_dq180',
                'trade.lc_dq365',
            ])
            ->pluck('value', 'key')
            ->toArray();

        $flexibleRateMap = [
            30  => isset($settingRows['trade.lc30']) ? (float) $settingRows['trade.lc30'] : 0,
            90  => isset($settingRows['trade.lc90']) ? (float) $settingRows['trade.lc90'] : 0,
            180 => isset($settingRows['trade.lc180']) ? (float) $settingRows['trade.lc180'] : 0,
            365 => isset($settingRows['trade.lc365']) ? (float) $settingRows['trade.lc365'] : 0,
        ];

        $fixedRateMap = [
            30  => isset($settingRows['trade.lc_dq30']) ? (float) $settingRows['trade.lc_dq30'] : 0,
            90  => isset($settingRows['trade.lc_dq90']) ? (float) $settingRows['trade.lc_dq90'] : 0,
            180 => isset($settingRows['trade.lc_dq180']) ? (float) $settingRows['trade.lc_dq180'] : 0,
            365 => isset($settingRows['trade.lc_dq365']) ? (float) $settingRows['trade.lc_dq365'] : 0,
        ];

        $rateMap = $investmentType === 'flexible' ? $flexibleRateMap : $fixedRateMap;

        /*
         * 开启自动理财：
         * 使用前端选择的 currency_id，不再固定 USDT。
         */
        if ($status === 1) {
            $wallet = \App\Models\Wallet\Wallet::where('user_id', $user->id)
                ->where('currency_id', $currencyId)
                ->lockForUpdate()
                ->first();

            if (!$wallet) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'amount' => ['Wallet not found.'],
                ]);
            }

            /*
             * 理财扣款来源：
             * 1. 如果虚拟交易账户 balance_in_virtual_trade > 0，本次理财全部使用虚拟交易账户。
             * 2. 虚拟交易账户余额不足时直接提示不足，不混用真实交易账户。
             * 3. 没有虚拟交易余额时，才使用真实交易账户 balance_in_trade。
             */
            $autoInvestDebitSource = $this->getAutoInvestWalletDebitSource($wallet, $amount);

            $rate = (float) ($rateMap[$days] ?? 0);
            $vipLevel = $this->getUserVipLevel($user);
            $vipBoostRate = $this->getVipYieldBoostRate($user);
            $orderNo = function_exists('generate_uuid') ? generate_uuid() : (string) \Illuminate\Support\Str::uuid();

            $user->auto_invest_funding = 1;
            $user->invest_funding_time = $days;
            $user->auto_invest_type = $investmentType;
            $user->auto_invest_amount = $amount;
            $user->save();

            /*
             * 冻结逻辑：
             * 真实账户扣 balance_in_trade。
             * 虚拟账户扣 balance_in_virtual_trade。
             */
            $debitBalanceField = $autoInvestDebitSource['field'];
            $wallet->{$debitBalanceField} = (float) $wallet->{$debitBalanceField} - $amount;
            $wallet->save();

            $orderData = [
                'order_no' => $orderNo,
                'user_id' => $user->id,
                'wallet_id' => $wallet->id,
                'currency_id' => $currencyId,
                'amount' => $amount,
                'used_margin' => 0,
                'redeemed_amount' => 0,
                'base_profit' => 0,
                'vip_profit' => 0,
                'total_profit' => 0,
                'days' => $days,
                'investment_type' => $investmentType,
                'rate' => $rate,
                'daily_profit_percent' => 0,
                'settled_profit_amount' => 0,
                'last_settled_date' => null,
                'last_settled_at' => null,
                'vip_level' => $vipLevel,
                'vip_boost_rate' => $vipBoostRate,
                'status' => 'active',
                'started_at' => $now,
                'matured_at' => $now->copy()->addDays($days),
                'redeemed_at' => null,
                'meta' => json_encode([
                    'source' => 'saveAutoInvest',
                    'old_lc_mode' => false,
                    'currency_id' => $currencyId,
                    'symbol' => $currencySymbol,
                    'source_account_type' => $autoInvestDebitSource['account_type'],
                    'source_balance_field' => $autoInvestDebitSource['field'],
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (\Schema::hasColumn('auto_invest_orders', 'principal_amount')) {
                $orderData['principal_amount'] = $amount;
            }

            $orderId = \DB::table('auto_invest_orders')->insertGetId($orderData);

            $responseData = [
                'message' => 'Saved successfully',
                'auto_invest_funding' => true,
                'invest_funding_time' => $days,
                'investment_type' => $investmentType,
                'amount' => $amount,
                'currency_id' => $currencyId,
                'symbol' => $currencySymbol,
                'order_id' => $orderId,
                'order_no' => $orderNo,
                'rate' => $rate,
                'vip_level' => $vipLevel,
                'vip_boost_rate' => $vipBoostRate,
            ];

            return;
        }

        /*
         * 关闭自动理财 / 赎回：
         * 这里也按前端传入的 currency_id 只处理对应代币的理财订单。
         */
        $orders = \DB::table('auto_invest_orders')
            ->where('user_id', $user->id)
            ->where('currency_id', $currencyId)
            ->where('status', 'active')
            ->orderBy('started_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $wallet = \App\Models\Wallet\Wallet::where('user_id', $user->id)
            ->where('currency_id', $currencyId)
            ->lockForUpdate()
            ->first();

        if (!$wallet || $orders->isEmpty()) {
            $hasAnyActiveOrder = \DB::table('auto_invest_orders')
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->exists();

            if (!$hasAnyActiveOrder) {
                $user->auto_invest_funding = 0;
                $user->invest_funding_time = 0;
                $user->auto_invest_type = null;
                $user->auto_invest_amount = 0;
                $user->save();
            }

            $responseData = [
                'message' => 'Saved successfully',
                'auto_invest_funding' => $hasAnyActiveOrder,
                'invest_funding_time' => $hasAnyActiveOrder ? (int) ($user->invest_funding_time ?: 0) : 0,
                'investment_type' => $hasAnyActiveOrder ? ($user->auto_invest_type ?: null) : null,
                'amount' => $hasAnyActiveOrder ? (float) ($user->auto_invest_amount ?: 0) : 0,
                'currency_id' => $currencyId,
                'symbol' => $currencySymbol,
            ];

            return;
        }

        $redeemedAmountTotal = 0;
        $redeemedAmountByField = [
            'balance_in_trade' => 0,
            'balance_in_virtual_trade' => 0,
        ];

        $principalTotal = 0;
        $baseProfitTotal = 0;
        $vipProfitTotal = 0;
        $profitTotal = 0;
        $maxElapsedDays = 0;
        $maxEarningDays = 0;
        $lastVipLevel = $this->getUserVipLevel($user);
        $lastVipBoostRate = $this->getVipYieldBoostRate($user);

        foreach ($orders as $order) {
            $investDays = (int) ($order->days ?: $user->invest_funding_time ?: 0);
            $savedInvestmentType = $order->investment_type ?: ($user->auto_invest_type ?: 'fixed');

            if (!in_array($savedInvestmentType, ['flexible', 'fixed'])) {
                $savedInvestmentType = 'fixed';
            }

            $orderRateMap = $savedInvestmentType === 'flexible' ? $flexibleRateMap : $fixedRateMap;
            $configuredRate = (float) ($orderRateMap[$investDays] ?? 0);
            $storedRate = (float) ($order->rate ?: 0);
            $rate = $configuredRate > 0
                ? $configuredRate
                : $storedRate;
            $vipLevel = (int) ($order->vip_level ?: $this->getUserVipLevel($user));
            $storedVipBoostRate = isset($order->vip_boost_rate) && is_numeric($order->vip_boost_rate)
                ? (float) $order->vip_boost_rate
                : 0;
            $vipBoostRate = $storedVipBoostRate > 0
                ? $storedVipBoostRate
                : (float) $this->getVipYieldBoostRate($user);

            $lastVipLevel = $vipLevel;
            $lastVipBoostRate = $vipBoostRate;

            $principal = (float) $order->amount;
            $usedMargin = (float) ($order->used_margin ?? 0);

            if ($principal <= 0) {
                continue;
            }

            if ($usedMargin > 0) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'amount' => ['Auto Invest funds are currently used as futures margin. Please close the position before redeeming.'],
                ]);
            }

            $elapsedDays = 0;
            $earningDays = 0;
            $baseProfit = 0;
            $vipProfit = 0;
            $profit = 0;

            if (!empty($order->started_at) && $investDays > 0 && $rate > 0) {
                $startTime = \Carbon\Carbon::parse($order->started_at);
                $nowTime = \Carbon\Carbon::now();

                /*
                 * 满 24 小时才算 1 天。
                 */
                $elapsedSeconds = max(0, $startTime->diffInSeconds($nowTime, false));
                $elapsedDays = (int) floor($elapsedSeconds / 86400);
                $earningDays = min($elapsedDays, $investDays);

                if ($savedInvestmentType === 'fixed' && $elapsedDays < $investDays) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'investment_type' => ['Fixed assets cannot be redeemed before maturity.'],
                    ]);
                }

                if ($savedInvestmentType === 'flexible') {
                    if ($elapsedDays >= $investDays) {
                        $baseProfit = $principal * ($rate / 100) * $investDays;
                    } else {
                        $baseProfit = $principal * ($rate / 100) * $earningDays;
                        $baseProfit = $baseProfit / 2;
                    }
                }

                if ($savedInvestmentType === 'fixed') {
                    $baseProfit = $principal * ($rate / 100) * $investDays;
                }

                $boostResult = $this->applyVipYieldBoost($baseProfit, $vipBoostRate);

                $baseProfit = $boostResult['base_profit'];
                $vipProfit = $boostResult['vip_profit'];
                $profit = $boostResult['total_profit'];
            }

            $returnAmount = $principal + $profit;
            $returnBalanceField = $this->getAutoInvestOrderReturnBalanceField($order);

            if (!isset($redeemedAmountByField[$returnBalanceField])) {
                $redeemedAmountByField[$returnBalanceField] = 0;
            }

            $redeemedAmountByField[$returnBalanceField] += $returnAmount;

            \DB::table('auto_invest_orders')
                ->where('id', $order->id)
                ->update([
                    'redeemed_amount' => $returnAmount,
                    'base_profit' => $baseProfit,
                    'vip_profit' => $vipProfit,
                    'total_profit' => $profit,
                    'status' => 'redeemed',
                    'redeemed_at' => now(),
                    'updated_at' => now(),
                ]);

            $redeemedAmountTotal += $returnAmount;
            $principalTotal += $principal;
            $baseProfitTotal += $baseProfit;
            $vipProfitTotal += $vipProfit;
            $profitTotal += $profit;
            $maxElapsedDays = max($maxElapsedDays, $elapsedDays);
            $maxEarningDays = max($maxEarningDays, $earningDays);
        }

        /*
         * 本金 + 收益返回原来源交易账户。
         */
        foreach ($redeemedAmountByField as $field => $fieldAmount) {
            if ($fieldAmount <= 0) {
                continue;
            }

            $field = $this->normalizeAutoInvestBalanceField($field);
            $wallet->{$field} = (float) $wallet->{$field} + (float) $fieldAmount;
        }

        if ($redeemedAmountTotal > 0) {
            $wallet->save();
        }

        $hasActiveOrder = \DB::table('auto_invest_orders')
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();

        if (!$hasActiveOrder) {
            $user->auto_invest_funding = 0;
            $user->invest_funding_time = 0;
            $user->auto_invest_type = null;
            $user->auto_invest_amount = 0;
            $user->save();
        }

        $responseData = [
            'message' => 'Saved successfully',
            'auto_invest_funding' => $hasActiveOrder,
            'invest_funding_time' => $hasActiveOrder ? (int) ($user->invest_funding_time ?: 0) : 0,
            'investment_type' => $hasActiveOrder ? ($user->auto_invest_type ?: null) : null,
            'amount' => $hasActiveOrder ? (float) ($user->auto_invest_amount ?: 0) : 0,
            'currency_id' => $currencyId,
            'symbol' => $currencySymbol,
            'redeemed_amount' => $redeemedAmountTotal,
            'principal' => $principalTotal,
            'profit' => $profitTotal,
            'base_profit' => $baseProfitTotal,
            'vip_level' => $lastVipLevel,
            'vip_boost_rate' => $lastVipBoostRate,
            'vip_bonus_profit' => $vipProfitTotal,
            'elapsed_days' => $maxElapsedDays,
            'earning_days' => $maxEarningDays,
        ];
    });

    return response()->json($responseData);
}


    /**
     * Display deposit screen
     *
     * @return \Illuminate\Http\Response
     */
    public function depositCrypto($symbol = '')
    {
        if (!$symbol) {
            abort(404);
        }

        $currency = $this->currencyService->getCurrencyBySymbol($symbol, 'coin', true, ['networks', 'file']);

        $currencyCollection = (new CurrencyRepository())->all(false, false, ['file', 'networks'], 'coin');

        if (!$currency) {
            abort(404);
        }

        if (!filter_var($currency->deposit_status, FILTER_VALIDATE_BOOLEAN)) {
            abort(404);
        }

        $currencyCollection = $currencyCollection->filter(function ($item) {
            return filter_var($item->deposit_status, FILTER_VALIDATE_BOOLEAN);
        })->values();

        $networks = [];

        foreach ($currency->networks as $network) {
            if ($network->id == NETWORK_COINPAYMENTS) {
                $network->name = $currency->coinpayments_description;
            }
            $networks[$network->id] = $network->name;
        }

        $currencies = new CurrencyCollection($currencyCollection);

        return Inertia::render('Wallet/Deposit/DepositCrypto', [
            'symbol' => $symbol,
            'currency' => new Currency($currency),
            'currencies' => $currencies->response()->getData(true)['data'],
        ]);
    }

    /**
     * Display withdraw screen
     *
     * @return \Illuminate\Http\Response
     */
    public function withdrawCrypto($symbol = '')
    {
        if (!$symbol) {
            abort(404);
        }

        $currency = $this->currencyService->getCurrencyBySymbol($symbol, 'coin', true, ['networks', 'file']);

        if (!$currency) {
            abort(404);
        }

        if (!filter_var($currency->withdraw_status, FILTER_VALIDATE_BOOLEAN)) {
            abort(404);
        }

        $currencyCollection = (new CurrencyRepository())->all(false, false, ['file', 'networks'], 'coin')
            ->filter(function ($item) {
                return filter_var($item->withdraw_status, FILTER_VALIDATE_BOOLEAN);
            })
            ->values();
        $currencies = new CurrencyCollection($currencyCollection);

        $limit = $this->currencyService->getDailyAvailableWithdrawal($currency, auth()->user());

        $networks = Network::where('withdraw_status', false)->pluck('id')->toArray();

        $disabledNetworks = array_merge($networks, $currency->disabled_withdrawal_networks);

        $user = auth()->user();

        /*
         * 内部提现显示限制：
         *
         * 内部转账资格与 API 使用同一后台配置
         */
        $internalWithdrawMinVip = max(0, (int) \Setting::get('wallet.internal_withdraw_min_vip', 0));

        $userVipRaw = $user->vip ?? 0;

        if (is_numeric($userVipRaw)) {
            $userVip = (int) $userVipRaw;
        } else {
            preg_match('/\d+/', (string) $userVipRaw, $matches);
            $userVip = isset($matches[0]) ? (int) $matches[0] : 0;
        }

        $canInternalWithdraw = $userVip >= $internalWithdrawMinVip;

        return Inertia::render('Wallet/Withdraw/WithdrawCrypto', [
            'limit' => $limit,
            'symbol' => $symbol,
            'currency' => new Currency($currency),
            'currencies' => $currencies->response()->getData(true)['data'],
            'disabledNetworks' => $disabledNetworks,

            /*
             * 内部提现显示控制
             */
            'userVip' => $userVip,
            'internalWithdrawMinVip' => $internalWithdrawMinVip,
            'canInternalWithdraw' => $canInternalWithdraw,
        ]);
    }

    /**
     * Display deposit screen
     *
     * @return \Illuminate\Http\Response
     */
    public function depositFiat($symbol = '')
    {
        if (!$symbol) {
            abort(404);
        }

        $currency = $this->currencyService->getCurrencyBySymbol($symbol, 'fiat', true, ['networks', 'bankAccount.country']);

        if (!$currency) {
            abort(404);
        }

        if (!filter_var($currency->deposit_status, FILTER_VALIDATE_BOOLEAN)) {
            abort(404);
        }

        $siteLang = app()->getLocale() . '_' . strtoupper(app()->getLocale());

        return Inertia::render('Wallet/Deposit/DepositFiat', [
            'symbol' => $symbol,
            'networks' => $currency->networks->pluck('slug'),
            'currency' => new FiatCurrency($currency),
            'stripe' => setting('stripe.public_key'),
            'siteName' => config('app.name'),
            'siteLang' => $siteLang,
            'accountId' => config('perfectmoney.account_id')
        ]);
    }

    /**
     * Display deposit screen
     *
     * @return \Illuminate\Http\Response
     */
    public function depositFiatSuccess()
    {
        return Inertia::render('Wallet/Deposit/Fiat/DepositFiatSuccess');
    }

    public function depositFiatCancel($symbol = '')
    {
        return redirect()->route('wallets.deposit.fiat', $symbol);
    }

    /**
     * Display deposit screen
     *
     * @return \Illuminate\Http\Response
     */
    public function depositFiatFailed()
    {
        return Inertia::render('Wallet/Deposit/Fiat/DepositFiatFailed');
    }

    /**
     * Display deposit screen
     *
     * @return \Illuminate\Http\Response
     */
    public function depositPaymentSuccess()
    {
        return Inertia::render('Wallet/Deposit/Crypto/DepositSuccessCallback');
    }

    public function depositPaymentCancel()
    {
        return Inertia::render('Wallet/Deposit/Crypto/DepositCancelCallback');
    }

    public function depositPaymentFailed()
    {
        return Inertia::render('Wallet/Deposit/Crypto/DepositFailedCallback');
    }

    /**
     * Display deposit screen
     *
     * @return \Illuminate\Http\Response
     */
    public function withdrawFiatSuccess()
    {
        return Inertia::render('Wallet/Withdraw/Fiat/WithdrawFiatSuccess');
    }

    /**
     * Display deposit screen
     *
     * @return \Illuminate\Http\Response
     */
    public function withdrawCryptoSuccess()
    {
        return Inertia::render('Wallet/Withdraw/Coin/WithdrawCryptoSuccess');
    }

    /**
     * Display withdraw screen
     *
     * @return \Illuminate\Http\Response
     */
    public function withdrawFiat($symbol = '')
    {
        $currency = $this->currencyService->getCurrencyBySymbol($symbol, 'fiat', true, ['networks']);

        if (!$currency) {
            abort(404);
        }

        if (!filter_var($currency->withdraw_status, FILTER_VALIDATE_BOOLEAN)) {
            abort(404);
        }

        $limit = $this->currencyService->getDailyAvailableWithdrawal($currency, auth()->user());

        $countries = new CountryCollection((new CountryRepository())->get());

        return Inertia::render('Wallet/Withdraw/WithdrawFiat', [
            'networks' => $currency->networks->pluck('slug'),
            'limit' => $limit,
            'symbol' => $symbol,
            'currency' => new FiatCurrency($currency),
            'countries' => $countries
        ]);
    }

    /**
     * Store the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function depositStore(FiatDepositFormRequest $request)
    {
        $data = $request->only([
            'amount',
            'currency_id',
            'receipt_id',
            'note'
        ]);

        $currency = (new CurrencyRepository())->get($request->get('currency_id'), false, false, []);

        if ($currency->deposit_fee > 0) {
            $fee = math_percentage($request->get('amount'), $currency->deposit_fee);
        } else {
            $fee = $currency->deposit_fee_fixed;
        }

        $data['user_id'] = auth()->user()->getAuthIdentifier();
        $data['status'] = FIAT_DEPOSIT_PENDING;
        $data['type'] = 'bank';
        $data['deposit_id'] = generate_uuid();
        $data['fee'] = $fee;

        (new FiatDepositRepository())->store($data);

        /**
         * Admin Email Notification
         */
        $adminEmail = Setting::get('notification.admin_email', false);
        $notificationAllowed = Setting::get('notification.fiat_deposits', false);

        if ($adminEmail && $notificationAllowed) {
            $route = route('admin.reports.deposits.fiat') . "?search=" . $data['deposit_id'];
            Mail::to($adminEmail)->queue(new AdminDepositReceived(math_formatter($request->get('amount'), $currency->decimals), $currency->symbol, $route));
        }

        return Redirect::route('wallets.deposit.fiat', $currency->symbol);
    }

    /**
     * Store the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function withdrawStore(FiatWithdrawFormRequest $request)
    {
        $data = $request->only([
            'name',
            'iban',
            'swift',
            'ifsc',
            'address',
            'account_holder_name',
            'account_holder_address',
            'country_id',
            'amount',
            'currency_id',
        ]);

        $currency = (new CurrencyRepository())->get($request->get('currency_id'), false, false, []);

        $networks = $currency->networks->pluck('slug')->toArray();

        if (in_array(NETWORK_PAYEER_SLUG, $networks)) {
            $data['type'] = NETWORK_PAYEER_SLUG;
        } elseif (in_array(NETWORK_PERFECT_MONEY_SLUG, $networks)) {
            $data['type'] = NETWORK_PERFECT_MONEY_SLUG;
        }

        $fee = (new WithdrawalFeeService())->calculateFiatFee(
            $currency,
            $request->get('amount')
        );

        $data['user_id'] = auth()->user()->getAuthIdentifier();
        $data['status'] = FIAT_WITHDRAWAL_PENDING;
        $data['withdrawal_id'] = generate_uuid();
        $data['fee'] = $fee;
        $data['inusd'] = math_formatter(math_multiply($data['amount'], (new CurrencyRepository())->currencyPriceInUsd($currency)), 3);

        (new FiatWithdrawalRepository())->processWithdraw($data, $currency);

        return Redirect::route('wallets.withdraw.fiat', $currency->symbol);
    }
}
