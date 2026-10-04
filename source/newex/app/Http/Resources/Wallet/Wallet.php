<?php

namespace App\Http\Resources\Wallet;

use App\Repositories\Currency\CurrencyRepository;
use Illuminate\Http\Resources\Json\JsonResource;

class Wallet extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        $currencyRepository = new CurrencyRepository();

        /*
         * 真实账户余额
         */
        $realWallet = $this->formatAmount4($this->balance_in_wallet ?? 0);
        $realTrade = $this->formatAmount4($this->balance_in_trade ?? 0);
        $realOrder = $this->formatAmount4($this->balance_in_order ?? 0);
        $realWithdraw = $this->formatAmount4($this->balance_in_withdraw ?? 0);
        $realLc = $this->formatAmount4($this->balance_in_lc ?? 0);

        /*
         * 虚拟账户余额
         */
        $virtualWallet = $this->formatAmount4($this->balance_in_virtual_wallet ?? 0);
        $virtualTrade = $this->formatAmount4($this->balance_in_virtual_trade ?? 0);
        $virtualOrder = $this->formatAmount4($this->balance_in_virtual_order ?? 0);
        $virtualWithdraw = $this->formatAmount4($this->balance_in_virtual_withdraw ?? 0);
        $virtualAvailableForWithdraw = $virtualWallet;

        /*
         * 真实 + 虚拟合计
         */
        $totalWallet = $this->formatAmount4(math_sum($realWallet, $virtualWallet));
        $totalTrade = $this->formatAmount4(math_sum($realTrade, $virtualTrade));
        $totalOrder = $this->formatAmount4(math_sum($realOrder, $virtualOrder));

        /*
         * USD 估值
         */
        $currencyUsdRate = $currencyRepository->currencyPriceInUsd($this->currency);
        $toUsd = function ($amount) use ($currencyUsdRate) {
            if($currencyUsdRate == 0) {
                return 0;
            }

            return math_formatter(math_multiply((string) $amount, (string) $currencyUsdRate), 2);
        };

        $usdBalanceAvailable = $toUsd($realWallet);
        $usdBalanceTrade = $toUsd($realTrade);
        $usdBalanceOrder = $toUsd($realOrder);
        $usdBalanceWithdraw = $toUsd($realWithdraw);
        $usdBalanceLc = $toUsd($realLc);

        $usdVirtualWallet = $toUsd($virtualWallet);
        $usdVirtualTrade = $toUsd($virtualTrade);
        $usdVirtualOrder = $toUsd($virtualOrder);
        $usdVirtualWithdraw = $toUsd($virtualWithdraw);
        $usdVirtualAvailableForWithdraw = $toUsd($virtualAvailableForWithdraw);

        $usdTotalWallet = $toUsd($totalWallet);
        $usdTotalTrade = $toUsd($totalTrade);
        $usdTotalOrder = $toUsd($totalOrder);

        return [
            'currency' => $this->currency->name,
            'symbol' => $this->currency->symbol,
            'logo' => url($this->currency->logo_path),
            'type' => $this->currency->type,
            'is_token' => $this->currency->is_token,
            'asset_category' => $this->currency->asset_category ?: 'crypto',
            'usd_rate' => $this->positiveOrZero($currencyUsdRate),
            'currency_usd_rate' => $this->positiveOrZero($currencyUsdRate),
            'deposit_status' => filter_var($this->currency->deposit_status, FILTER_VALIDATE_BOOLEAN),
            'withdraw_status' => filter_var($this->currency->withdraw_status, FILTER_VALIDATE_BOOLEAN),
            'address' => $this->address ? $this->address->address : null,
            'payment_id' => $this->address ? $this->address->payment_id : null,

            /*
             * 真实账户余额，兼容旧前端字段
             */
            'balance_in_wallet' => $this->positiveOrZero($realWallet),
            'balance_in_wallet_usd' => $this->positiveOrZero($usdBalanceAvailable),

            'balance_in_trade' => $this->positiveOrZero($realTrade),
            'balance_in_trade_usd' => $this->positiveOrZero($usdBalanceTrade),

            'balance_in_lc' => $this->positiveOrZero($realLc),
            'balance_in_lc_usd' => $this->positiveOrZero($usdBalanceLc),
            'auto_invest_locked_amount' => $this->positiveOrZero($this->auto_invest_locked_amount ?? 0),
            'staking_locked_amount' => $this->positiveOrZero($this->staking_locked_amount ?? 0),
            'custody_locked_amount' => $this->positiveOrZero($this->custody_locked_amount ?? 0),

            'balance_in_order' => $this->positiveOrZero($realOrder),
            'balance_in_order_usd' => $this->positiveOrZero($usdBalanceOrder),

            'balance_in_withdraw' => $this->positiveOrZero($realWithdraw),
            'balance_in_withdraw_usd' => $this->positiveOrZero($usdBalanceWithdraw),

            /*
             * 虚拟账户余额
             */
            'balance_in_virtual_wallet' => $this->positiveOrZero($virtualWallet),
            'balance_in_virtual_wallet_usd' => $this->positiveOrZero($usdVirtualWallet),

            'balance_in_virtual_trade' => $this->positiveOrZero($virtualTrade),
            'balance_in_virtual_trade_usd' => $this->positiveOrZero($usdVirtualTrade),

            'balance_in_virtual_order' => $this->positiveOrZero($virtualOrder),
            'balance_in_virtual_order_usd' => $this->positiveOrZero($usdVirtualOrder),

            'balance_in_virtual_withdraw' => $this->positiveOrZero($virtualWithdraw),
            'balance_in_virtual_withdraw_usd' => $this->positiveOrZero($usdVirtualWithdraw),

            'virtual_available_for_withdraw' => $this->positiveOrZero($virtualAvailableForWithdraw),
            'virtual_available_for_withdraw_usd' => $this->positiveOrZero($usdVirtualAvailableForWithdraw),

            /*
             * 合计余额：真实账户 + 虚拟账户
             */
            'total_balance_in_wallet' => $this->positiveOrZero($totalWallet),
            'total_balance_in_wallet_usd' => $this->positiveOrZero($usdTotalWallet),

            'total_balance_in_trade' => $this->positiveOrZero($totalTrade),
            'total_balance_in_trade_usd' => $this->positiveOrZero($usdTotalTrade),

            'total_balance_in_order' => $this->positiveOrZero($totalOrder),
            'total_balance_in_order_usd' => $this->positiveOrZero($usdTotalOrder),

            /*
             * 资产页汇总：理财、质押、托管和总资产。
             */
            'auto_invest_total_usdt' => $this->positiveOrZero($this->auto_invest_total_usdt ?? 0),
            'earn_total_usdt' => $this->positiveOrZero($this->earn_total_usdt ?? 0),
            'auto_invest_real_usdt' => $this->positiveOrZero($this->auto_invest_real_usdt ?? 0),
            'auto_invest_virtual_usdt' => $this->positiveOrZero($this->auto_invest_virtual_usdt ?? 0),
            'auto_invest_available_total_usdt' => $this->positiveOrZero($this->auto_invest_available_total_usdt ?? 0),
            'auto_invest_available_real_usdt' => $this->positiveOrZero($this->auto_invest_available_real_usdt ?? 0),
            'auto_invest_available_virtual_usdt' => $this->positiveOrZero($this->auto_invest_available_virtual_usdt ?? 0),
            'staking_total_usdt' => $this->positiveOrZero($this->staking_total_usdt ?? 0),
            'custody_total_usdt' => $this->positiveOrZero($this->custody_total_usdt ?? 0),
            'wallets_real_total_usdt' => $this->positiveOrZero($this->wallets_real_total_usdt ?? 0),
            'wallets_virtual_total_usdt' => $this->positiveOrZero($this->wallets_virtual_total_usdt ?? 0),
            'wallets_total_usdt' => $this->positiveOrZero($this->wallets_total_usdt ?? 0),
            'all_assets_total_usdt' => $this->positiveOrZero($this->all_assets_total_usdt ?? 0),
            'all_real_assets_total_usdt' => $this->positiveOrZero($this->all_real_assets_total_usdt ?? 0),
            'all_virtual_assets_total_usdt' => $this->positiveOrZero($this->all_virtual_assets_total_usdt ?? 0),
            'converted_all_tokens_to_usdt' => $this->positiveOrZero($this->converted_all_tokens_to_usdt ?? 0),
            'converted_real_tokens_to_usdt' => $this->positiveOrZero($this->converted_real_tokens_to_usdt ?? 0),
            'converted_virtual_tokens_to_usdt' => $this->positiveOrZero($this->converted_virtual_tokens_to_usdt ?? 0),
            'total_usdt_balance' => $this->positiveOrZero($this->total_usdt_balance ?? 0),
            'display_usdt_balance' => $this->positiveOrZero($this->display_usdt_balance ?? 0),

            /*
             * 嵌套结构，方便新前端直接区分真实/虚拟/合计
             */
            'balances' => [
                'real' => [
                    'wallet' => $this->positiveOrZero($realWallet),
                    'trade' => $this->positiveOrZero($realTrade),
                    'order' => $this->positiveOrZero($realOrder),
                    'withdraw' => $this->positiveOrZero($realWithdraw),
                    'lc' => $this->positiveOrZero($realLc),
                ],
                'virtual' => [
                    'wallet' => $this->positiveOrZero($virtualWallet),
                    'trade' => $this->positiveOrZero($virtualTrade),
                    'order' => $this->positiveOrZero($virtualOrder),
                    'withdraw' => $this->positiveOrZero($virtualWithdraw),
                    'available_for_withdraw' => $this->positiveOrZero($virtualAvailableForWithdraw),
                ],
                'total' => [
                    'wallet' => $this->positiveOrZero($totalWallet),
                    'trade' => $this->positiveOrZero($totalTrade),
                    'order' => $this->positiveOrZero($totalOrder),
                ],
            ],
        ];
    }

    private function formatAmount4($value)
    {
        if ($value === null || $value === '') {
            $value = 0;
        }

        if (is_string($value)) {
            $value = trim(str_replace(',', '', $value));
        }

        if (!is_numeric($value)) {
            $value = 0;
        }

        return math_formatter((string) $value, 4, false, true);
    }

    private function positiveOrZero($value)
    {
        if ($value === null || $value === '') {
            return 0;
        }

        if (is_string($value)) {
            $value = trim(str_replace(',', '', $value));
        }

        if (!is_numeric($value)) {
            return 0;
        }

        return math_compare((string) $value, '0') > 0 ? $value : 0;
    }
}
