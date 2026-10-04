<?php

namespace App\Http\Requests\Api\Order;

use App\Models\Market\Market;
use App\Models\Wallet\Wallet;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class OrderStoreRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    /**
     * 在正式验证 rules 之前执行。
     *
     * 用途：
     * 下单前先根据交易对创建当前用户对应的 base / quote 钱包。
     *
     * 例如：
     * market = USDC-USDT
     * 会自动确保当前用户存在：
     * 1. USDC 钱包
     * 2. USDT 钱包
     */
    protected function prepareForValidation()
    {
        $this->ensureWalletsForOrderMarket();
    }

    public function rules()
    {
        $marketBuy = order_is_buy_market($this->input('type'),$this->input('side'));
        $market = order_is_market($this->input('type'));
        $rules = [
            'market'=>['bail','required','string',new Rules\OrderMarketRule()],
            'type'=>['bail','required','string',new Rules\OrderTypeRule()],
            'side'=>['bail','required','in:buy,sell'],
            'price'=>['bail',\Illuminate\Validation\Rule::requiredIf(!$market),'nullable','numeric',new Rules\OrderPriceRule()],
            'quantity'=>['bail',\Illuminate\Validation\Rule::requiredIf(!$marketBuy),'nullable','numeric',new Rules\OrderQuantityRule()],
            'quoteQuantity'=>['bail',\Illuminate\Validation\Rule::requiredIf($marketBuy),'nullable','numeric',new Rules\OrderQuoteQuantityRule()],
            'trigger_price'=>['bail',\Illuminate\Validation\Rule::requiredIf($this->input('type') === 'stop_limit'),'nullable','numeric','gt:0',new Rules\OrderPriceRule()],
            'trigger_condition'=>['nullable','in:up,down'],
            'client_order_id'=>['nullable','string','max:128','regex:/^[A-Za-z0-9_.:-]+$/D'],
            'swap'=>['nullable','boolean'],
        ];
        if ($this->input('type') !== 'stop_limit') unset($rules['trigger_price'], $rules['trigger_condition']);
        return $rules;
    }

    public function messages()
    {
        return [
            'market.required' => __('Market is required'),
            'type.required' => __('Order type is required'),
            'side.required' => __('Order side is required'),
            'side.in' => __('Invalid order side'),

            'price.numeric' => __('Price must be numeric'),
            'quantity.numeric' => __('Quantity must be numeric'),
            'quoteQuantity.numeric' => __('Quote quantity must be numeric'),
            'total.numeric' => __('Total must be numeric'),

            'trigger_price.numeric' => __('Trigger price must be numeric'),
            'trigger_price.gt' => __('Trigger price must be greater than zero'),
            'trigger_price.required' => __('Trigger price is required'),
            'take_profit_price.numeric' => __('Take profit price must be numeric'),
            'stop_loss_price.numeric' => __('Stop loss price must be numeric'),
        ];
    }

    private function ensureWalletsForOrderMarket(): void
    {
        try {
            $user = $this->user();

            if (!$user) {
                return;
            }

            $marketValue = trim((string) $this->input('market'));

            if ($marketValue === '') {
                return;
            }

            $market = Market::query()
                ->where('name', $marketValue)
                ->orWhere(function ($query) use ($marketValue) {
                    if (is_numeric($marketValue)) {
                        $query->where('id', (int) $marketValue);
                    }
                })
                ->first();

            if (!$market) {
                return;
            }

            $currencyIds = array_filter([
                $market->base_currency_id,
                $market->quote_currency_id,
            ]);

            foreach ($currencyIds as $currencyId) {
                $this->createWalletIfMissing((int) $user->id, (int) $currencyId);
            }
        } catch (\Throwable $e) {
            Log::error('Create wallets before order validation failed', [
                'user_id' => optional($this->user())->id,
                'market' => $this->input('market'),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function createWalletIfMissing(int $userId, int $currencyId): void
    {
        if ($userId <= 0 || $currencyId <= 0) {
            return;
        }

        $exists = Wallet::query()
            ->where('user_id', $userId)
            ->where('currency_id', $currencyId)
            ->exists();

        if ($exists) {
            return;
        }

        $wallet = new Wallet();
        $wallet->user_id = $userId;
        $wallet->currency_id = $currencyId;

        $this->setWalletZeroValue($wallet, 'balance_in_wallet');
        $this->setWalletZeroValue($wallet, 'balance_in_trade');
        $this->setWalletZeroValue($wallet, 'balance_in_order');
        $this->setWalletZeroValue($wallet, 'balance_in_withdraw');
        $this->setWalletZeroValue($wallet, 'balance_in_lc');

        $this->setWalletZeroValue($wallet, 'balance_in_virtual_wallet');
        $this->setWalletZeroValue($wallet, 'balance_in_virtual_trade');
        $this->setWalletZeroValue($wallet, 'balance_in_virtual_order');

        $wallet->save();
    }

    private function setWalletZeroValue(Wallet $wallet, string $field): void
    {
        try {
            if (Schema::hasColumn($wallet->getTable(), $field)) {
                $wallet->{$field} = 0;
            }
        } catch (\Throwable $e) {
            /*
             * 字段不存在或 Schema 检查失败时忽略。
             * 不影响基础钱包创建。
             */
        }
    }
}
