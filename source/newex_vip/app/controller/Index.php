<?php

namespace app\controller;

use app\BaseController;
use think\facade\Db;
use think\facade\Log;
class Index extends BaseController
{
    public function index()
{
    $userId = 1;
    $sql = "
        WITH RECURSIVE user_tree AS (
            SELECT id, referral_id, vip, 1 AS level
            FROM users
            WHERE referral_id = :user_id
    
            UNION ALL
    
            SELECT u.id, u.referral_id, u.vip, ut.level + 1
            FROM users u
            INNER JOIN user_tree ut ON u.referral_id = ut.id
        )
        SELECT id, vip, level FROM user_tree
    ";

    $users = Db::query($sql, ['user_id' => $userId]);

    $teamIds = [];
    $directIds = [];
    $teamVip = [];
    
    foreach ($users as $user) {
    
        // 所有团队成员 id
        $teamIds[] = $user['id'];
    
        // 记录 VIP
        $teamVip[$user['id']] = $user['vip'];
    
        // 直属成员
        if ($user['level'] == 1) {
            $directIds[] = $user['id'];
        }
    }
    $vipsum = 0;
    foreach ($teamVip as $key => $value) {
        if($value >= 2){
            $vipsum+=1;
        }
    }
    $directCount = count($directIds);
    $teamCount   = count($teamIds);
    $deposits = Db::name('deposits')
        ->whereIn('user_id', $teamIds)
        ->where('currency_id', 2)
        ->sum('amount');
    $vip = 1;
    if($directCount >= 2 && $teamCount >= 5 && $deposits >= 300){
       $vip = 1; 
    }elseif($directCount >= 4 && $teamCount >= 12 && $deposits >= 300){
       $vip = 2; 
    }elseif($directCount >= 6 && $teamCount >= 40 && $deposits >= 5000 && $vipsum>=2){
       $vip = 3;
    }elseif($directCount >= 8 && $teamCount >= 100 && $deposits >= 5000 && $vipsum>=3){
       $vip = 4;
    }elseif($directCount >= 10 && $teamCount >= 250 && $deposits >= 50000 && $vipsum>=6){
       $vip = 5;
    }elseif($directCount >= 10 && $teamCount >= 600 && $deposits >= 300000 && $vipsum>=8){
       $vip = 6;
    }elseif($directCount >= 12 && $teamCount >= 1500 && $deposits >= 1000000 && $vipsum>=10){
       $vip = 7;
    }elseif($directCount >= 12 && $teamCount >= 3500 && $deposits >= 2000000 && $vipsum>=12){
       $vip = 8;
    }
    Db::name('users')->where('id', $userId)->update([
        'vip' => $vip
    ]);

}

    public function make()
    {
        $list = Db::name('markets')->where('custom_liquidity_t',1)->select();
        foreach ($list as $key => $value) {
            $time = str_replace('T', ' ', $value['custom_liquidity_stop_time']) . ':00';
            $timestamp = strtotime($time);
            if(time() >= $timestamp){
                $data = [
                    'sc_price_floor' => $value['bot_price_floor'],
                    'sc_price_ceiling' => $value['bot_price_ceiling'],
                    'sc_custom_liquidity_start_time' => $value['custom_liquidity_start_time'],
                    'sc_custom_liquidity_stop_time' => $value['custom_liquidity_stop_time'],
                    'sc_bot_trend_direction' => $value['bot_trend_direction'],
                    'custom_liquidity_t' => 0,
                    'bot_price_floor' => '',
                    'bot_price_ceiling' => '',
                    'custom_liquidity_start_time' => '',
                    'custom_liquidity_stop_time' => '',
                ];
                Db::name('markets')->where('id',$value['id'])->update($data);
            }
        }
        
    }

public function lc()
{
    $today = date('Y-m-d');
    $now = date('Y-m-d H:i:s');

    /**
     * 今天已经生成过快照，说明今天收益已经结算过。
     * 防止定时任务重复执行，把收益重复加上去。
     */
    $todaySnapshotExists = Db::name('auto_invest_daily_snapshots')
        ->where('snapshot_date', $today)
        ->count();

    if ($todaySnapshotExists > 0) {
        return json([
            'code' => 0,
            'msg' => '今日理财收益已结算，跳过执行',
            'date' => $today,
        ]);
    }

    Db::startTrans();

    try {
        $orders = Db::name('auto_invest_orders')
            ->where('status', 'active')
            ->whereNotNull('started_at')
            ->order('id', 'asc')
            ->lock(true)
            ->select()
            ->toArray();

        if (empty($orders)) {
            Db::commit();

            return json([
                'code' => 0,
                'msg' => '没有需要结算的理财订单',
                'date' => $today,
            ]);
        }

        $settledCount = 0;
        $settledLogs = [];

        foreach ($orders as $order) {
            $orderId = (int) $order['id'];

            /**
             * 单笔订单今天已经结算过，也跳过。
             * 这里是双保险，避免快照缺失时重复处理某一笔订单。
             */
            if (!empty($order['last_settled_date']) && $order['last_settled_date'] >= $today) {
                continue;
            }

            $startedAt = $order['started_at'] ?? null;

            if (!$startedAt) {
                continue;
            }

            $startDate = date('Y-m-d', strtotime($startedAt));

            /**
             * 下一个自然日 00:00 才开始结算。
             */
            $firstSettleDate = date('Y-m-d', strtotime($startDate . ' +1 day'));

            if ($today < $firstSettleDate) {
                continue;
            }

            $days = (int) ($order['days'] ?? 30);

            if (!in_array($days, [30, 90, 180])) {
                $days = 30;
            }

            $maturityDate = date('Y-m-d', strtotime($startDate . ' +' . $days . ' day'));

            /**
             * 已经过了到期日的订单，不继续做日收益。
             */
            if ($today > $maturityDate) {
                continue;
            }

            /**
             * 根据订单类型读取不同的日收益设置。
             *
             * flexible:
             *   trade.lc30
             *   trade.lc90
             *   trade.lc180
             *
             * fixed:
             *   trade.lc_dq30
             *   trade.lc_dq90
             *   trade.lc_dq180
             */
            $investmentType = strtolower((string) ($order['investment_type'] ?? 'fixed'));
            $investmentType = $investmentType === 'flexible' ? 'flexible' : 'fixed';

            $settingKey = $investmentType === 'flexible'
                ? 'trade.lc' . $days
                : 'trade.lc_dq' . $days;

            $settingValue = $this->lcGetSettingValue($settingKey, '0');

            /**
             * 从配置的最低-最高之间随机取今日收益率。
             *
             * 例如：
             * 0.1-0.3  => 0.1% 到 0.3% 中间随机
             * -3--2.5 => -3% 到 -2.5% 中间随机
             */
            [$minDailyPercent, $maxDailyPercent] = $this->lcParsePercentRange($settingValue, 0, 0);
            $dailyPercent = $this->lcRandomPercent($minDailyPercent, $maxDailyPercent, 8);

            /**
             * principal_amount 是原始本金。
             * 因为 amount 每天会变化，所以必须保留一份原始本金用于计算总收益百分比。
             */
            $principalAmount = isset($order['principal_amount']) ? (float) $order['principal_amount'] : 0;
            $currentAmount = isset($order['amount']) ? (float) $order['amount'] : 0;
            $usedMargin = isset($order['used_margin']) ? (float) $order['used_margin'] : 0;

            if ($currentAmount <= 0) {
                continue;
            }

            if ($principalAmount <= 0) {
                $principalAmount = $currentAmount;

                Db::name('auto_invest_orders')
                    ->where('id', $orderId)
                    ->update([
                        'principal_amount' => $this->lcDecimal($principalAmount),
                    ]);
            }

            /**
             * 当前可参与理财收益计算的金额。
             *
             * amount 是订单当前总金额。
             * used_margin 是已经被合约占用的金额。
             *
             * 被合约占用的资金不参与当日理财收益。
             */
            $earningAmount = max(0, $currentAmount - $usedMargin);

            /**
             * 如果整笔订单都被合约占用了，也要标记今天已处理，
             * 避免同一天重复扫描，同时保持收益为 0。
             */
            if ($earningAmount <= 0) {
                $settledProfitAmount = $currentAmount - $principalAmount;
                $currentTotalProfitPercent = $principalAmount > 0
                    ? ($settledProfitAmount / $principalAmount) * 100
                    : 0;

                Db::name('auto_invest_orders')
                    ->where('id', $orderId)
                    ->update([
                        'daily_profit_percent' => $this->lcDecimal(0, 8),
                        'settled_profit_amount' => $this->lcDecimal($settledProfitAmount),
                        'current_total_profit_percent' => $this->lcDecimal($currentTotalProfitPercent, 8),
                        'total_profit' => $this->lcDecimal($settledProfitAmount),
                        'last_settled_date' => $today,
                        'last_settled_at' => $now,
                        'updated_at' => $now,
                    ]);

                $settledCount++;

                $settledLogs[] = [
                    'order_id' => $orderId,
                    'user_id' => (int) $order['user_id'],
                    'currency_id' => (int) $order['currency_id'],
                    'principal_amount' => $principalAmount,
                    'old_amount' => $currentAmount,
                    'new_amount' => $currentAmount,
                    'profit_amount' => 0,
                    'base_profit_amount' => 0,
                    'vip_profit_amount' => 0,
                    'vip_boost_percent' => $this->lcGetUserVipBoostPercent((int) $order['user_id']),
                    'daily_profit_percent' => 0,
                    'base_daily_profit_percent' => 0,
                    'current_total_profit_percent' => $currentTotalProfitPercent,
                    'investment_type' => $investmentType,
                    'setting_key' => $settingKey,
                    'setting_value' => $settingValue,
                ];

                continue;
            }

            /**
             * VIP 收益加成。
             *
             * 规则：
             * VIP1 = 基础正收益 +10%
             * VIP2 = 基础正收益 +20%
             * ...
             * VIP8 = 基础正收益 +80%
             *
             * 注意：
             * 1. 只对正收益加成。
             * 2. 如果今日基础收益是负数，不放大亏损。
             */
            $vipBoostPercent = $this->lcGetUserVipBoostPercent((int) $order['user_id']);

            /**
             * 基础收益金额。
             * 注意：这里按未被合约占用的金额 earningAmount 做百分比变动。
             */
            $baseProfitAmount = $earningAmount * ($dailyPercent / 100);

            /**
             * VIP 加成收益金额。
             */
            $vipProfitAmount = 0;

            if ($baseProfitAmount > 0 && $vipBoostPercent > 0) {
                $vipProfitAmount = $baseProfitAmount * ($vipBoostPercent / 100);
            }

            /**
             * 今日最终收益金额 = 基础收益 + VIP 加成收益。
             */
            $profitAmount = $baseProfitAmount + $vipProfitAmount;

            /**
             * 最终日收益百分比。
             * 例如：基础 0.2%，VIP1 +10%，最终为 0.22%。
             */
            $finalDailyPercent = $earningAmount > 0
                ? ($profitAmount / $earningAmount) * 100
                : 0;

            $newAmount = $currentAmount + $profitAmount;

            if ($newAmount < 0) {
                $newAmount = 0;
                $profitAmount = 0 - $currentAmount;
                $baseProfitAmount = $profitAmount;
                $vipProfitAmount = 0;
                $finalDailyPercent = $earningAmount > 0
                    ? ($profitAmount / $earningAmount) * 100
                    : 0;
            }

            /**
             * 当前累计收益金额。
             * 因为 amount 会每天变化，所以累计收益 = 当前 amount - 原始本金。
             */
            $settledProfitAmount = $newAmount - $principalAmount;

            /**
             * 当前总收益百分比。
             * 下方订单列表展示这个字段。
             */
            $currentTotalProfitPercent = $principalAmount > 0
                ? ($settledProfitAmount / $principalAmount) * 100
                : 0;

            Db::name('auto_invest_orders')
                ->where('id', $orderId)
                ->update([
                    'amount' => $this->lcDecimal($newAmount),
                    'daily_profit_percent' => $this->lcDecimal($finalDailyPercent, 8),
                    'settled_profit_amount' => $this->lcDecimal($settledProfitAmount),
                    'current_total_profit_percent' => $this->lcDecimal($currentTotalProfitPercent, 8),
                    'total_profit' => $this->lcDecimal($settledProfitAmount),
                    'last_settled_date' => $today,
                    'last_settled_at' => $now,
                    'updated_at' => $now,
                ]);

            $settledCount++;

            $settledLogs[] = [
                'order_id' => $orderId,
                'user_id' => (int) $order['user_id'],
                'currency_id' => (int) $order['currency_id'],
                'principal_amount' => $principalAmount,
                'old_amount' => $currentAmount,
                'new_amount' => $newAmount,
                'profit_amount' => $profitAmount,
                'base_profit_amount' => $baseProfitAmount,
                'vip_profit_amount' => $vipProfitAmount,
                'vip_boost_percent' => $vipBoostPercent,
                'daily_profit_percent' => $finalDailyPercent,
                'base_daily_profit_percent' => $dailyPercent,
                'current_total_profit_percent' => $currentTotalProfitPercent,
                'investment_type' => $investmentType,
                'setting_key' => $settingKey,
                'setting_value' => $settingValue,
            ];
        }

        /**
         * 没有任何订单需要结算，也不生成当天快照。
         */
        if ($settledCount <= 0) {
            Db::commit();

            return json([
                'code' => 0,
                'msg' => '今日没有需要新增收益的理财订单',
                'date' => $today,
            ]);
        }

        /**
         * 结算完成后，按 user_id + currency_id 生成当天快照。
         * 前端百分比 K 线使用 weighted_profit_percent。
         */
        $groups = [];

        $activeOrders = Db::name('auto_invest_orders')
            ->where('status', 'active')
            ->select()
            ->toArray();

        foreach ($activeOrders as $order) {
            $userId = (int) $order['user_id'];
            $currencyId = (int) $order['currency_id'];
            $groupKey = $userId . '_' . $currencyId;

            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'user_id' => $userId,
                    'currency_id' => $currencyId,
                    'total_amount' => 0,
                    'total_principal' => 0,
                    'total_current_profit' => 0,
                    'active_order_count' => 0,
                    'matured_order_count' => 0,
                    'redeemable_order_count' => 0,
                    'redeemable_amount' => 0,
                    'fixed_order_count' => 0,
                    'flexible_order_count' => 0,
                    'used_margin' => 0,
                    'available_margin' => 0,
                    'sum_percent' => 0,
                    'weighted_percent_numerator' => 0,
                    'weighted_percent_denominator' => 0,
                    'daily_profit_amount' => 0,
                    'daily_settled_order_count' => 0,
                ];
            }

            $amount = (float) ($order['amount'] ?? 0);
            $principalAmount = (float) ($order['principal_amount'] ?? 0);
            $usedMargin = (float) ($order['used_margin'] ?? 0);
            $investmentType = strtolower((string) ($order['investment_type'] ?? 'fixed'));
            $investmentType = $investmentType === 'flexible' ? 'flexible' : 'fixed';

            $orderDays = (int) ($order['days'] ?? 30);
            $orderStartedAt = $order['started_at'] ?? null;

            if ($principalAmount <= 0) {
                $principalAmount = $amount;
            }

            $currentProfit = $amount - $principalAmount;
            $currentTotalProfitPercent = $principalAmount > 0
                ? ($currentProfit / $principalAmount) * 100
                : 0;

            $isMatured = false;

            if ($orderStartedAt) {
                $orderStartDate = date('Y-m-d', strtotime($orderStartedAt));
                $orderMaturityDate = date('Y-m-d', strtotime($orderStartDate . ' +' . $orderDays . ' day'));
                $isMatured = $today >= $orderMaturityDate;
            }

            $isRedeemable = $investmentType === 'flexible' || $isMatured;

            $groups[$groupKey]['total_amount'] += $amount;
            $groups[$groupKey]['total_principal'] += $principalAmount;
            $groups[$groupKey]['total_current_profit'] += $currentProfit;
            $groups[$groupKey]['active_order_count'] += 1;
            $groups[$groupKey]['used_margin'] += $usedMargin;
            $groups[$groupKey]['available_margin'] += max(0, $amount - $usedMargin);

            if ($isMatured) {
                $groups[$groupKey]['matured_order_count'] += 1;
            }

            if ($isRedeemable) {
                $groups[$groupKey]['redeemable_order_count'] += 1;
                $groups[$groupKey]['redeemable_amount'] += max(0, $amount - $usedMargin);
            }

            if ($investmentType === 'flexible') {
                $groups[$groupKey]['flexible_order_count'] += 1;
            } else {
                $groups[$groupKey]['fixed_order_count'] += 1;
            }

            $groups[$groupKey]['sum_percent'] += $currentTotalProfitPercent;
            $groups[$groupKey]['weighted_percent_numerator'] += $principalAmount * $currentTotalProfitPercent;
            $groups[$groupKey]['weighted_percent_denominator'] += $principalAmount;
        }

        /**
         * 只统计今天真正结算过的订单收益金额。
         */
        foreach ($settledLogs as $log) {
            $groupKey = $log['user_id'] . '_' . $log['currency_id'];

            if (!isset($groups[$groupKey])) {
                continue;
            }

            $groups[$groupKey]['daily_profit_amount'] += $log['profit_amount'];
            $groups[$groupKey]['daily_settled_order_count'] += 1;
        }

        foreach ($groups as $group) {
            $activeOrderCount = max(1, (int) $group['active_order_count']);

            /**
             * 普通平均收益率。
             */
            $avgProfitPercent = $group['sum_percent'] / $activeOrderCount;

            /**
             * 加权平均收益率。
             */
            $weightedProfitPercent = $group['weighted_percent_denominator'] > 0
                ? $group['weighted_percent_numerator'] / $group['weighted_percent_denominator']
                : 0;

            $data = [
                'user_id' => $group['user_id'],
                'currency_id' => $group['currency_id'],
                'snapshot_date' => $today,

                'total_amount' => $this->lcDecimal($group['total_amount']),
                'total_principal' => $this->lcDecimal($group['total_principal']),
                'total_current_profit' => $this->lcDecimal($group['total_current_profit']),
                'total_maturity_profit' => 0,

                'active_order_count' => (int) $group['active_order_count'],
                'matured_order_count' => (int) $group['matured_order_count'],
                'redeemable_order_count' => (int) $group['redeemable_order_count'],

                'redeemable_principal' => $this->lcDecimal($group['redeemable_amount']),
                'redeemable_profit' => 0,
                'redeemable_amount' => $this->lcDecimal($group['redeemable_amount']),

                'fixed_order_count' => (int) $group['fixed_order_count'],
                'flexible_order_count' => (int) $group['flexible_order_count'],

                'used_margin' => $this->lcDecimal($group['used_margin']),
                'available_margin' => $this->lcDecimal($group['available_margin']),

                'avg_profit_percent' => $this->lcDecimal($avgProfitPercent, 8),
                'weighted_profit_percent' => $this->lcDecimal($weightedProfitPercent, 8),
                'daily_profit_amount' => $this->lcDecimal($group['daily_profit_amount']),

                'meta' => json_encode([
                    'settled_order_count' => $group['daily_settled_order_count'],
                    'settled_at' => $now,
                ], JSON_UNESCAPED_UNICODE),

                'created_at' => $now,
                'updated_at' => $now,
            ];

            $exists = Db::name('auto_invest_daily_snapshots')
                ->where('user_id', $group['user_id'])
                ->where('currency_id', $group['currency_id'])
                ->where('snapshot_date', $today)
                ->find();

            if ($exists) {
                unset($data['created_at']);

                Db::name('auto_invest_daily_snapshots')
                    ->where('id', $exists['id'])
                    ->update($data);
            } else {
                Db::name('auto_invest_daily_snapshots')->insert($data);
            }
        }

        Db::commit();

        return json([
            'code' => 1,
            'msg' => '理财收益结算成功',
            'date' => $today,
            'settled_count' => $settledCount,
        ]);
    } catch (\Throwable $e) {
        Db::rollback();

        Log::error('理财收益结算失败：' . $e->getMessage());

        return json([
            'code' => 0,
            'msg' => '理财收益结算失败：' . $e->getMessage(),
        ]);
    }
}
/**
 * 读取设置值。
 *
 * 优先读取：
 * trade.lc30
 *
 * 如果你的 settings 表里存的是 trade 这个 JSON，也会自动兼容：
 * {"lc30":"0.1-0.3"}
 */
private function lcGetSettingValue(string $key, string $default = '0'): string
{
    $value = Db::name('settings')
        ->where('key', $key)
        ->value('value');

    if ($value !== null && $value !== '') {
        return (string) $value;
    }

    if (strpos($key, 'trade.') === 0) {
        $shortKey = substr($key, strlen('trade.'));

        $value = Db::name('settings')
            ->where('key', $shortKey)
            ->value('value');

        if ($value !== null && $value !== '') {
            return (string) $value;
        }

        $tradeValue = Db::name('settings')
            ->where('key', 'trade')
            ->value('value');

        if ($tradeValue !== null && $tradeValue !== '') {
            $decoded = json_decode((string) $tradeValue, true);

            if (is_array($decoded) && array_key_exists($shortKey, $decoded)) {
                return (string) $decoded[$shortKey];
            }
        }
    }

    return $default;
}

/**
 * 解析收益率范围。
 *
 * 支持：
 * 0.1-0.3
 * 0.1% - 0.3%
 * -3--2.5
 * 0.3
 */
private function lcParsePercentRange($value, float $defaultMin = 0, float $defaultMax = 0): array
{
    $text = trim((string) $value);

    if ($text === '') {
        return [$defaultMin, $defaultMax];
    }

    $text = str_replace(
        ['％', '%', '～', '~', '—', '–', '－', '至', '到', ' '],
        ['', '', '-', '-', '-', '-', '-', '-', '-', ''],
        $text
    );

    /**
     * 支持格式：
     *
     * 0.1-0.3      => 0.1 到 0.3
     * -3-2.5       => -3 到 2.5
     * -3--2.5      => -3 到 -2.5
     * 0.3          => 0.3 到 0.3
     */
    $separatorPos = false;

    if (substr($text, 0, 1) === '-') {
        /**
         * 如果第一个字符是负号，从第二位之后找范围分隔符。
         */
        $separatorPos = strpos($text, '-', 1);
    } else {
        $separatorPos = strpos($text, '-');
    }

    if ($separatorPos !== false) {
        $left = substr($text, 0, $separatorPos);
        $right = substr($text, $separatorPos + 1);

        /**
         * 处理 -3--2.5
         * separator 后面还有一个负号，需要保留为负数。
         */
        if ($right !== '' && substr($right, 0, 1) === '-') {
            $right = '-' . ltrim($right, '-');
        }

        if (is_numeric($left) && is_numeric($right)) {
            $min = (float) $left;
            $max = (float) $right;

            if ($min > $max) {
                [$min, $max] = [$max, $min];
            }

            return [$min, $max];
        }
    }

    if (is_numeric($text)) {
        $number = (float) $text;
        return [$number, $number];
    }

    preg_match_all('/-?\d+(?:\.\d+)?/', $text, $matches);

    $numbers = $matches[0] ?? [];

    if (count($numbers) >= 2) {
        $min = (float) $numbers[0];
        $max = (float) $numbers[1];

        if ($min > $max) {
            [$min, $max] = [$max, $min];
        }

        return [$min, $max];
    }

    if (count($numbers) === 1) {
        $number = (float) $numbers[0];
        return [$number, $number];
    }

    return [$defaultMin, $defaultMax];
}

/**
 * 在最低-最高之间随机一个收益率。
 */
private function lcRandomPercent(float $min, float $max, int $scale = 8): float
{
    if ($min > $max) {
        [$min, $max] = [$max, $min];
    }

    if ($scale < 0) {
        $scale = 0;
    }

    if ($scale > 8) {
        $scale = 8;
    }

    $factor = pow(10, $scale);

    $minInt = (int) round($min * $factor);
    $maxInt = (int) round($max * $factor);

    if ($minInt === $maxInt) {
        return $min;
    }

    return mt_rand($minInt, $maxInt) / $factor;
}
/**
 * 智能生成每日收益百分比。
 *
 * 默认随机范围：-3% 到 +2.5%。
 * 同时会根据剩余天数动态缩小随机范围，确保到期金额能达到产品总收益目标。
 */
private function lcSmartDailyPercent(
    float $currentAmount,
    float $targetAmount,
    int $remainingDays,
    float $minPercent = -3,
    float $maxPercent = 2.5
): float {
    if ($currentAmount <= 0) {
        return 0;
    }

    $remainingDays = max(1, $remainingDays);

    /**
     * 最后一天直接修正到目标金额。
     */
    if ($remainingDays === 1) {
        return (($targetAmount / $currentAmount) - 1) * 100;
    }

    $remainingAfterToday = $remainingDays - 1;

    $maxRate = 1 + ($maxPercent / 100);
    $minRate = 1 + ($minPercent / 100);

    /**
     * 今天最低要涨/跌多少，才能保证后面每天都按最大涨幅时还能到目标。
     */
    $minAllowedToday = (($targetAmount / ($currentAmount * pow($maxRate, $remainingAfterToday))) - 1) * 100;

    /**
     * 今天最高能涨/跌多少，才能保证后面每天都按最大跌幅时还能回到目标。
     */
    $maxAllowedToday = (($targetAmount / ($currentAmount * pow($minRate, $remainingAfterToday))) - 1) * 100;

    $lower = max($minPercent, $minAllowedToday);
    $upper = min($maxPercent, $maxAllowedToday);

    /**
     * 如果产品总收益配置太高或太低，导致 -3% 到 +2.5% 范围内无法完成目标，
     * 这里用平均所需百分比兜底，优先保证到期达标。
     */
    if ($lower > $upper) {
        return (pow($targetAmount / $currentAmount, 1 / $remainingDays) - 1) * 100;
    }

    return $this->lcRandomFloat($lower, $upper, 8);
}

/**
 * 生成指定范围随机小数。
 */
private function lcRandomFloat(float $min, float $max, int $scale = 8): float
{
    if ($min > $max) {
        [$min, $max] = [$max, $min];
    }

    $base = pow(10, $scale);

    return mt_rand((int) round($min * $base), (int) round($max * $base)) / $base;
}

/**
 * 获取用户 VIP 理财收益加成百分比。
 *
 * 优先读取后台设置：
 * trade.lc_vip_1_boost_percent
 * trade.lc_vip_2_boost_percent
 * ...
 * trade.lc_vip_8_boost_percent
 *
 * 默认规则：
 * VIP1 = 10
 * VIP2 = 20
 * ...
 * VIP8 = 80
 */
private function lcGetUserVipBoostPercent(int $userId): float
{
    $vipLevel = $this->lcGetUserVipLevel($userId);

    if ($vipLevel <= 0) {
        return 0;
    }

    if ($vipLevel > 8) {
        $vipLevel = 8;
    }

    $defaultPercent = $vipLevel * 10;
    $settingKey = 'trade.lc_vip_' . $vipLevel . '_boost_percent';
    $settingValue = $this->lcGetSettingValue($settingKey, (string) $defaultPercent);

    $percent = $this->lcParseSinglePercent($settingValue, $defaultPercent);

    return max(0, $percent);
}

/**
 * 解析单个百分比值。
 *
 * 支持：
 * 10
 * 10%
 * １０％
 */
private function lcParseSinglePercent($value, float $default = 0): float
{
    $text = trim((string) $value);

    if ($text === '') {
        return $default;
    }

    $text = str_replace(['％', '%', ' '], ['', '', ''], $text);

    if (is_numeric($text)) {
        return (float) $text;
    }

    preg_match('/-?\d+(?:\.\d+)?/', $text, $matches);

    if (!empty($matches[0])) {
        return (float) $matches[0];
    }

    return $default;
}

/**
 * 获取用户 VIP 等级。
 *
 * 兼容字段：
 * vip
 * vip_level
 * vipLevel
 * member_level
 * membership_level
 * level
 * rank
 * grade
 */
private function lcGetUserVipLevel(int $userId): int
{
    static $cache = [];

    if (isset($cache[$userId])) {
        return $cache[$userId];
    }

    $user = Db::name('users')
        ->where('id', $userId)
        ->find();

    if (!$user) {
        try {
            $user = Db::name('user')
                ->where('id', $userId)
                ->find();
        } catch (\Throwable $e) {
            $user = null;
        }
    }

    if (!$user) {
        $cache[$userId] = 0;
        return 0;
    }

    $candidateFields = [
        'vip',
        'vip_level',
        'vipLevel',
        'current_vip_level',
        'currentVipLevel',
        'member_level',
        'memberLevel',
        'membership_level',
        'membershipLevel',
        'level',
        'rank',
        'grade',
    ];

    foreach ($candidateFields as $field) {
        if (!array_key_exists($field, $user)) {
            continue;
        }

        $level = $this->lcParseVipLevel($user[$field]);

        if ($level >= 1 && $level <= 8) {
            $cache[$userId] = $level;
            return $level;
        }
    }

    $cache[$userId] = 0;
    return 0;
}

/**
 * 解析 VIP 等级。
 *
 * 支持：
 * 1
 * VIP1
 * vip_1
 * level 1
 */
private function lcParseVipLevel($value): int
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

/**
 * 格式化 decimal，避免科学计数法进数据库。
 */
private function lcDecimal($value, int $scale = 18): string
{
    $number = (float) $value;

    if (!is_finite($number)) {
        $number = 0;
    }

    return number_format($number, $scale, '.', '');
}

}
