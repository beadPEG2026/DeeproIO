<?php

namespace App\Http\Resources\Currency;

use App\Http\Resources\BankAccount\BankAccount;
use App\Services\Withdrawal\WithdrawalFeeService;
use Illuminate\Http\Resources\Json\JsonResource;

class FiatCurrency extends JsonResource
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

        return [
            'id' => $this->id,
            'name' => $this->name,
            'symbol' => $this->symbol,
            'decimals' => $this->decimals,
            'type' => $this->type,
            'status' => $this->status,
            'deposit_status' => $this->deposit_status,
            'withdraw_status' => $this->withdraw_status,
            'deposit_fee' => $this->deposit_fee,
            'deposit_fee_fixed' => $this->deposit_fee_fixed,
            'withdraw_fee' => $withdrawalFeeService->applyIncrease($this->withdraw_fee),
            'withdraw_fee_fixed' => $withdrawalFeeService->applyIncrease($this->withdraw_fee_fixed),
            'min_deposit' => math_formatter($this->min_deposit, $this->decimals),
            'max_deposit' => math_formatter($this->max_deposit, $this->decimals),
            'min_withdraw' => math_formatter($this->min_withdraw, $this->decimals),
            'max_withdraw' => math_formatter($this->max_withdraw, $this->decimals),
            'bank_account' => $this->bankAccount ? new BankAccount($this->bankAccount) : null,
            'bank_status' => $this->bank_status,
            'cc_status' => $this->cc_status
        ];
    }
}
