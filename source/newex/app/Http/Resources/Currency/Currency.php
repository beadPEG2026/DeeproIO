<?php

namespace App\Http\Resources\Currency;

use App\Http\Resources\Network\NetworkCollection;
use App\Repositories\Currency\CurrencyRepository;
use App\Services\Withdrawal\WithdrawalFeeService;
use Illuminate\Http\Resources\Json\JsonResource;

class Currency extends JsonResource
{
    public static $wrap = null;

    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        $withdrawalFeeService = new WithdrawalFeeService();

        $data = [
            'name' => $this->name,
            'symbol' => $this->symbol,
            'asset_category' => $this->asset_category ?: 'crypto',
            'fullname' => $this->name . ' (' . $this->symbol . ')',
            'logo' => $this->resource->logo_path,
            'full_logo_path' => url($this->resource->logo_path),
            'decimals' => $this->decimals,
            'type' => $this->type,
            'status' => $this->status,
            'min_deposit_confirmation' => $this->min_deposit_confirmation,
            'deposit_status' => $this->deposit_status,
            'withdraw_status' => $this->withdraw_status,

            'deposit_fee' => $this->deposit_fee,
            'deposit_fee_fixed' => $this->deposit_fee_fixed,

            'deposit_fee_erc' => $this->deposit_fee_erc,
            'deposit_fee_bep' => $this->deposit_fee_bep,
            'deposit_fee_trc' => $this->deposit_fee_trc,
            'deposit_fee_sol' => $this->deposit_fee_sol,
            'deposit_fee_matic' => $this->deposit_fee_matic,

            'deposit_fee_erc_fixed' => $this->deposit_fee_erc_fixed,
            'deposit_fee_bep_fixed' => $this->deposit_fee_bep_fixed,
            'deposit_fee_trc_fixed' => $this->deposit_fee_trc_fixed,
            'deposit_fee_sol_fixed' => $this->deposit_fee_sol_fixed,
            'deposit_fee_matic_fixed' => $this->deposit_fee_matic_fixed,

            'withdraw_fee' => $withdrawalFeeService->applyIncrease($this->withdraw_fee),
            'withdraw_fee_fixed' => $withdrawalFeeService->applyIncrease($this->withdraw_fee_fixed),
            'withdraw_fee_erc' => $withdrawalFeeService->applyIncrease($this->withdraw_fee_erc),
            'withdraw_fee_bep' => $withdrawalFeeService->applyIncrease($this->withdraw_fee_bep),
            'withdraw_fee_trc' => $withdrawalFeeService->applyIncrease($this->withdraw_fee_trc),
            'withdraw_fee_sol' => $withdrawalFeeService->applyIncrease($this->withdraw_fee_sol),
            'withdraw_fee_matic' => $withdrawalFeeService->applyIncrease($this->withdraw_fee_matic),
            'withdraw_fee_erc_fixed' => $withdrawalFeeService->applyIncrease($this->withdraw_fee_erc_fixed),
            'withdraw_fee_bep_fixed' => $withdrawalFeeService->applyIncrease($this->withdraw_fee_bep_fixed),
            'withdraw_fee_sol_fixed' => $withdrawalFeeService->applyIncrease($this->withdraw_fee_sol_fixed),
            'withdraw_fee_trc_fixed' => $withdrawalFeeService->applyIncrease($this->withdraw_fee_trc_fixed),
            'withdraw_fee_matic_fixed' => $withdrawalFeeService->applyIncrease($this->withdraw_fee_matic_fixed),
            'withdrawal_fee_rates' => $this->withdrawalFeeRates($withdrawalFeeService),

            'min_deposit' => math_formatter($this->min_deposit, $this->decimals),
            'max_deposit' => math_formatter($this->max_deposit, $this->decimals),
            'min_withdraw' => math_formatter($this->min_withdraw, $this->decimals),
            'max_withdraw' => math_formatter($this->max_withdraw, $this->decimals),
            'has_payment_id' => $this->has_payment_id,
            'networks' => new NetworkCollection($this->networks),
        ];

        if (config('app.fees_in_usd')) {
            foreach ([
                'withdraw_fee_fixed',
                'withdraw_fee_erc_fixed',
                'withdraw_fee_bep_fixed',
                'withdraw_fee_trc_fixed',
                'withdraw_fee_sol_fixed',
                'withdraw_fee_matic_fixed',
            ] as $field) {
                if (math_compare((string) $data[$field], '0') > 0) {
                    $data[$field] = (new CurrencyRepository())->calculateAmountInUsd($this, $data[$field]);
                }
            }
        }

        return $data;
    }

    /** Coin-denominated rates for withdrawal confirmation, independent of legacy USD display. */
    private function withdrawalFeeRates(WithdrawalFeeService $service): array
    {
        $rates = [];
        foreach ([
            'default' => '',
            'erc20' => '_erc',
            'bep20' => '_bep',
            'trc20' => '_trc',
            'matic20' => '_matic',
            'solspl' => '_sol',
            'xlayer20' => '_xlayer',
        ] as $network => $suffix) {
            $percent = $service->applyIncrease($this->resource->{'withdraw_fee' . $suffix} ?? 0);
            $fixed = $service->applyIncrease($this->resource->{'withdraw_fee' . $suffix . '_fixed'} ?? 0);
            // WithdrawalFeeService applies a positive percentage first, otherwise a positive fixed fee.
            $rates[$network] = [
                'percent' => math_compare($percent, '0') > 0 ? $percent : '0',
                'fixed' => math_compare($fixed, '0') > 0 ? $fixed : '0',
            ];
        }
        $rates['internal'] = ['percent' => '0', 'fixed' => '0'];

        return $rates;
    }
}
