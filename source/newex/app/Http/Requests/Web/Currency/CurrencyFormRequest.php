<?php

namespace App\Http\Requests\Web\Currency;

use App\Http\Requests\Web\Currency\Rules\CurrencyAltSymbolRule;
use App\Http\Requests\Web\Currency\Rules\CurrencyBankAccountRule;
use App\Http\Requests\Web\Currency\Rules\CurrencyBankStatusRule;
use App\Http\Requests\Web\Currency\Rules\CurrencyBepContractRule;
use App\Http\Requests\Web\Currency\Rules\CurrencyCustomContractRule;
use App\Http\Requests\Web\Currency\Rules\CurrencyMaticContractRule;
use App\Http\Requests\Web\Currency\Rules\CurrencyTrcContractRule;
use App\Http\Requests\Web\Currency\Rules\CurrencyCcExchangeRateRule;
use App\Http\Requests\Web\Currency\Rules\CurrencyCcStatusRule;
use App\Http\Requests\Web\Currency\Rules\CurrencyContractRule;
use App\Http\Requests\Web\Currency\Rules\CurrencyNetworkRule;
use App\Http\Requests\Web\Currency\Rules\CurrencyStatusRule;
use App\Http\Requests\Web\Currency\Rules\CurrencySymbolRule;
use App\Http\Requests\Web\Currency\Rules\CurrencyTypeRule;
use Illuminate\Foundation\Http\FormRequest;
use Auth;
use Illuminate\Validation\Rule;

class CurrencyFormRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'asset_category' => ['sometimes', 'required', 'in:crypto,stock,etf'],
            'asset_issuer' => ['sometimes', 'nullable', 'string', 'max:120'],
            'asset_unit' => ['sometimes', 'required', 'string', 'max:32'],
            'asset_display_enabled' => ['sometimes', 'required', 'boolean'],
            'asset_chart_interval' => ['sometimes', 'required', Rule::in(config('stock-tokens.intervals'))],
            'id' => ['sometimes', 'required', 'exists:currencies'],
            'name' => ['bail', 'required', 'max:80'],
            'decimals' => ['bail', 'required', 'integer', 'numeric', 'gte:0', 'max:18'],
            'symbol' => ['bail', 'required', 'max:20', new CurrencySymbolRule()],
            'alt_symbol' => ['bail', 'max:30', new CurrencyAltSymbolRule()],
            'type' => ['bail', 'required', 'in:coin,fiat', 'max:10', new CurrencyTypeRule()],
            'networks' => ['bail', 'required', new CurrencyNetworkRule()],
            'status' => ['required', 'boolean', new CurrencyStatusRule()],
            'bank_account' => ['bail', 'exclude_if:type,coin', 'required_if:bank_status,true', new CurrencyBankAccountRule()],
            'bank_status' => ['bail', 'exclude_if:type,coin', 'required_if:cc_status,false', 'nullable', 'boolean', new CurrencyBankStatusRule()],
            'cc_status' => ['bail', 'exclude_if:type,coin', 'required_if:bank_status,false', 'nullable', 'boolean', new CurrencyCcStatusRule()],
            'cc_exchange_rate' => ['bail', 'exclude_if:type,coin', 'required_if:cc_status,true', 'nullable', 'numeric', 'max:999999', new CurrencyCcExchangeRateRule()],
            'file_id' => ['nullable', 'numeric', 'exists:file_uploads,id'],
            'deposit_status' => ['required', 'boolean'],
            'withdraw_status' => ['required', 'boolean'],


            'deposit_fee' => ['required', 'numeric', 'gte:0', 'max:100'],
            'deposit_fee_bep' => ['required', 'numeric', 'gte:0', 'max:100'],
            'deposit_fee_erc' => ['required', 'numeric', 'gte:0', 'max:100'],
            'deposit_fee_trc' => ['required', 'numeric', 'gte:0', 'max:100'],
            'deposit_fee_sol' => ['required', 'numeric', 'gte:0', 'max:100'],
            'deposit_fee_matic' => ['required', 'numeric', 'gte:0', 'max:100'],
            'deposit_fee_xlayer' => ['sometimes', 'numeric', 'gte:0', 'max:100'],

            'deposit_fee_fixed' => ['required', 'numeric', 'gte:0', 'max:1000000'],
            'deposit_fee_bep_fixed' => ['required', 'numeric', 'gte:0', 'max:1000000'],
            'deposit_fee_erc_fixed' => ['required', 'numeric', 'gte:0', 'max:1000000'],
            'deposit_fee_trc_fixed' => ['required', 'numeric', 'gte:0', 'max:1000000'],
            'deposit_fee_sol_fixed' => ['required', 'numeric', 'gte:0', 'max:1000000'],
            'deposit_fee_matic_fixed' => ['required', 'numeric', 'gte:0', 'max:1000000'],
            'deposit_fee_xlayer_fixed' => ['sometimes', 'numeric', 'gte:0', 'max:1000000'],

            'withdraw_fee' => ['required', 'numeric', 'gte:0', 'max:1000000'],
            'withdraw_fee_bep' => ['required', 'numeric', 'gte:0', 'max:1000000'],
            'withdraw_fee_erc' => ['required', 'numeric', 'gte:0', 'max:1000000'],
            'withdraw_fee_trc' => ['required', 'numeric', 'gte:0', 'max:1000000'],
            'withdraw_fee_sol' => ['required', 'numeric', 'gte:0', 'max:1000000'],
            'withdraw_fee_matic' => ['required', 'numeric', 'gte:0', 'max:1000000'],
            'withdraw_fee_xlayer' => ['sometimes', 'numeric', 'gte:0', 'max:1000000'],

            'withdraw_fee_fixed' => ['required', 'numeric', 'gte:0', 'max:1000000'],
            'withdraw_fee_bep_fixed' => ['required', 'numeric', 'gte:0', 'max:1000000'],
            'withdraw_fee_erc_fixed' => ['required', 'numeric', 'gte:0', 'max:1000000'],
            'withdraw_fee_trc_fixed' => ['required', 'numeric', 'gte:0', 'max:1000000'],
            'withdraw_fee_sol_fixed' => ['required', 'numeric', 'gte:0', 'max:1000000'],
            'withdraw_fee_matic_fixed' => ['required', 'numeric', 'gte:0', 'max:1000000'],
            'withdraw_fee_xlayer_fixed' => ['sometimes', 'numeric', 'gte:0', 'max:1000000'],



            'min_deposit' => ['required', 'numeric', 'gte:0', 'max:99999999999999999'],
            'max_deposit' => ['required', 'numeric', 'gte:0', 'max:99999999999999999'],
            'min_withdraw' => ['required', 'numeric', 'gte:0', 'max:99999999999999999'],
            'max_withdraw' => ['required', 'numeric', 'gte:0', 'max:99999999999999999'],
            'min_deposit_confirmation' => ['required', 'numeric', 'min:0', 'max:10000'],
            'contract' => ['bail', new CurrencyContractRule()],
            'bep_contract' => ['bail', new CurrencyBepContractRule()],
            'trc_contract' => ['bail', new CurrencyTrcContractRule()],
            'matic_contract' => ['bail', new CurrencyMaticContractRule()],
            'xlayer_contract' => ['nullable', \Illuminate\Validation\Rule::requiredIf(fn() => in_array(25, (array)$this->input('networks', []))), 'regex:/^0x[0-9a-fA-F]{40}$/D'],
            'custom_contract' => ['bail', new CurrencyCustomContractRule()],

            // Merchant Acquiring Settings
            'is_merchant' => ['nullable', 'boolean'],
            'merchant_fee_percent' => ['nullable', 'numeric', 'gte:0', 'max:100'],
            'merchant_min_amount_usd' => ['nullable', 'numeric', 'gte:0', 'max:99999999'],
            'merchant_max_amount_usd' => ['nullable', 'numeric', 'gte:0', 'max:99999999'],
            'merchant_confirmations' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'merchant_enabled_networks' => ['sometimes', 'nullable'],
            'merchant_enabled_networks.*' => ['nullable', 'integer', 'exists:networks,id'],

            // Unlimit buy settings
            'allowed_buy_fiats' => ['sometimes', 'nullable'],
            'allowed_buy_fiats.*' => ['string', 'max:10'],
            'allowed_buy_fiat' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Ensure merchant_enabled_networks is always an array or null
        $networks = $this->input('merchant_enabled_networks');

        if (is_null($networks) || $networks === '' || $networks === false || (is_array($networks) && empty($networks))) {
            $this->merge(['merchant_enabled_networks' => null]);
        } elseif (is_string($networks)) {
            // Handle JSON string
            $decoded = json_decode($networks, true);
            if (is_array($decoded)) {
                $this->merge(['merchant_enabled_networks' => array_map('intval', $decoded)]);
            } else {
                $this->merge(['merchant_enabled_networks' => [intval($networks)]]);
            }
        } elseif (is_array($networks)) {
            // Ensure all values are integers
            $this->merge(['merchant_enabled_networks' => array_map('intval', array_filter($networks, fn($v) => $v !== null && $v !== ''))]);
        }

        // Normalize allowed_buy_fiats as array of uppercase symbols or null
        $allowedBuyFiats = $this->input('allowed_buy_fiats');
        if ($allowedBuyFiats === null || $allowedBuyFiats === '' || $allowedBuyFiats === false) {
            $this->merge(['allowed_buy_fiats' => null]);
        } elseif (is_string($allowedBuyFiats)) {
            $decoded = json_decode($allowedBuyFiats, true);
            if (is_array($decoded)) {
                $this->merge(['allowed_buy_fiats' => array_values(array_unique(array_map(fn($v) => strtoupper(trim($v)), $decoded)))]);
            } else {
                $this->merge(['allowed_buy_fiats' => [strtoupper(trim($allowedBuyFiats))]]);
            }
        } elseif (is_array($allowedBuyFiats)) {
            $this->merge(['allowed_buy_fiats' => array_values(array_unique(array_map(fn($v) => strtoupper(trim($v)), array_filter($allowedBuyFiats, fn($v) => $v !== null && $v !== ''))))]);
        }

        // Normalize allowed_sell_fiats as array of uppercase symbols or null
        $allowedSellFiats = $this->input('allowed_sell_fiats');
        if ($allowedSellFiats === null || $allowedSellFiats === '' || $allowedSellFiats === false) {
            $this->merge(['allowed_sell_fiats' => null]);
        } elseif (is_string($allowedSellFiats)) {
            $decoded = json_decode($allowedSellFiats, true);
            if (is_array($decoded)) {
                $this->merge(['allowed_sell_fiats' => array_values(array_unique(array_map(fn($v) => strtoupper(trim($v)), $decoded)))]);
            } else {
                $this->merge(['allowed_sell_fiats' => [strtoupper(trim($allowedSellFiats))]]);
            }
        } elseif (is_array($allowedSellFiats)) {
            $this->merge(['allowed_sell_fiats' => array_values(array_unique(array_map(fn($v) => strtoupper(trim($v)), array_filter($allowedSellFiats, fn($v) => $v !== null && $v !== ''))))]);
        }
    }
}
