<?php

namespace App\Support;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WalletBalanceLogContext
{
    public static function shouldTrackRequest(Request $request): bool
    {
        return in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    public static function forRequest(Request $request): void
    {
        if (!self::shouldTrackRequest($request)) {
            return;
        }

        $route = $request->route();
        $routeName = $route ? $route->getName() : null;
        $method = $request->method();
        $path = '/'.ltrim($request->path(), '/');
        $actor = $request->user() ?: auth()->user();

        if (!$actor) {
            try {
                $actor = auth('sanctum')->user();
            } catch (\Throwable $e) {
                $actor = null;
            }
        }

        self::set([
            'operation' => self::operationName($routeName, $method, $path),
            'source' => 'http',
            'source_id' => $method.' '.$path,
            'actor_id' => $actor ? $actor->id : null,
            'actor_email' => $actor ? $actor->email : null,
            'route' => $routeName,
            'request_method' => $method,
            'request_path' => $path,
            'ip' => $request->ip(),
            'remark' => self::requestRemark($routeName, $method, $path),
        ]);
    }

    public static function forConsole(CommandStarting $event): void
    {
        $command = $event->command ?: 'console';

        self::set([
            'operation' => '系统命令：'.$command,
            'source' => 'console',
            'source_id' => $command,
            'actor_id' => null,
            'actor_email' => null,
            'route' => null,
            'request_method' => null,
            'request_path' => null,
            'ip' => null,
            'remark' => 'php artisan '.$command,
        ]);
    }

    public static function clear(): void
    {
        self::set([
            'operation' => null,
            'source' => null,
            'source_id' => null,
            'actor_id' => null,
            'actor_email' => null,
            'route' => null,
            'request_method' => null,
            'request_path' => null,
            'ip' => null,
            'remark' => null,
        ]);
    }

    public static function set(array $context): void
    {
        if (self::driverName() !== 'pgsql') {
            return;
        }

        $settings = [
            'app.wallet_balance_operation' => self::limit($context['operation'] ?? '', 255),
            'app.wallet_balance_source' => self::limit($context['source'] ?? '', 100),
            'app.wallet_balance_source_id' => self::limit($context['source_id'] ?? '', 100),
            'app.wallet_balance_actor_id' => self::limit($context['actor_id'] ?? '', 30),
            'app.wallet_balance_actor_email' => self::limit($context['actor_email'] ?? '', 255),
            'app.wallet_balance_route' => self::limit($context['route'] ?? '', 255),
            'app.wallet_balance_request_method' => self::limit($context['request_method'] ?? '', 16),
            'app.wallet_balance_request_path' => self::limit($context['request_path'] ?? '', 500),
            'app.wallet_balance_ip' => self::limit($context['ip'] ?? '', 64),
            'app.wallet_balance_remark' => self::limit($context['remark'] ?? '', 1000),
        ];

        $sqlParts = [];
        $bindings = [];

        foreach ($settings as $key => $value) {
            $sqlParts[] = 'set_config(?, ?, false)';
            $bindings[] = $key;
            $bindings[] = $value;
        }

        try {
            DB::statement('select '.implode(', ', $sqlParts), $bindings);
        } catch (\Throwable $e) {
            return;
        }
    }

    private static function driverName(): ?string
    {
        try {
            return DB::getDriverName();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function requestRemark(?string $routeName, string $method, string $path): string
    {
        $parts = array_filter([
            $routeName ? 'route='.$routeName : null,
            'request='.$method.' '.$path,
        ]);

        return implode(' | ', $parts);
    }

    private static function operationName(?string $routeName, string $method, string $path): string
    {
        $labels = [
            'admin.reports.wallets.fund' => '后台钱包划转/调整',
            'admin.reports.deposits.confirm-pending' => '后台确认充值',
            'admin.reports.deposits.resync' => '后台同步充值',
            'admin.reports.deposits.recheck' => '后台重查充值',
            'admin.reports.withdrawals.moderate' => '后台审核提现',
            'admin.reports.withdrawals.fiat.moderate' => '后台审核法币提现',
            'admin.reports.options.close' => '后台关闭期权订单',
            'admin.reports.futures.force-liquidate' => '后台强平开仓订单',
            'admin.reports.auto-invest-orders.close' => '后台关闭量化订单',
            'admin.reports.auto-invest-orders.release-margin' => '后台释放量化保证金',
            'wallets.api.transfer' => '用户钱包划转',
            'wallets.api.withdraw' => '用户提现',
            'wallets.api.deposit.fiat.stripe.validate' => '用户法币充值确认',
            'wallets.deposit.store.bank' => '用户提交法币充值',
            'wallets.withdraw.store.fiat' => '用户提交法币提现',
            'wallets.saveAutoInvest' => '用户购买量化',
            'wallets.redeemAutoInvestOrder' => '用户赎回量化',
            'staking.api.submit' => '用户申购质押',
            'staking.api.redeem' => '用户赎回质押',
            'staking.submit' => '用户申购质押',
            'staking.redeem' => '用户赎回质押',
            'launchpads.api.submit' => '用户申购 Launchpad',
            'launchpad.submit' => '用户申购 Launchpad',
            'lending.borrow' => '用户借贷借款',
            'lending.repay' => '用户借贷还款',
            'lending.collateral.add' => '用户追加抵押',
            'bank_account.deposit' => '用户银行卡充值',
            'voucher.redeem' => '用户兑换卡券',
            'orders.store' => '用户下单',
            'orders.update' => '用户更新订单',
            'orders.destroy' => '用户删除订单',
            'orders.api.cancel' => '用户撤销订单',
            'futures.store' => '用户开仓',
            'futures.update' => '用户更新合约订单',
            'futures.destroy' => '用户删除合约订单',
            'orders.api.futures.cancel' => '用户撤销合约订单',
            'options.store' => '用户购买期权',
            'options.update' => '用户更新期权订单',
            'options.destroy' => '用户删除期权订单',
            'unlimit.checkout' => '用户法币买入',
            'unlimit.payout' => '用户法币卖出',
            'unlimit.offramp.checkout' => '用户法币卖出',
        ];

        if ($routeName && isset($labels[$routeName])) {
            return $labels[$routeName];
        }

        if ($routeName && Str::startsWith($routeName, 'admin.')) {
            return '后台操作：'.$routeName;
        }

        if (Str::contains($path, '/wallets/transfer')) {
            return '用户钱包划转';
        }

        if (Str::contains($path, '/wallets/withdraw')) {
            return '用户提现';
        }

        if (Str::contains($path, '/orders') && $method !== 'GET') {
            return '用户订单操作';
        }

        if (Str::contains($path, '/futures') && $method !== 'GET') {
            return '用户合约操作';
        }

        if (Str::contains($path, '/options') && $method !== 'GET') {
            return '用户期权操作';
        }

        if ($routeName) {
            return $routeName;
        }

        return $method.' '.$path;
    }

    private static function limit($value, int $limit): string
    {
        if ($value === null) {
            return '';
        }

        return mb_substr((string) $value, 0, $limit);
    }
}
