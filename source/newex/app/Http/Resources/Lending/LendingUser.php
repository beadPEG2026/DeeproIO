<?php

namespace App\Http\Resources\Lending;

use App\Repositories\Currency\CurrencyRepository;
use Illuminate\Http\Resources\Json\JsonResource;

class LendingUser extends JsonResource
{
    public static $wrap = null;
    public $preserveKeys = true;

    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        $currencyRepository = new CurrencyRepository();

        if($this->status == "active") {

            $sourceCurrencyPrice = math_multiply($currencyRepository->currencyPriceInUsd($this->currency), $this->remaining_amount);
            $collateralCurrencyPrice = math_multiply($currencyRepository->currencyPriceInUsd($this->collateral), $this->collateral_amount);

            $ltv = 0;

            if($collateralCurrencyPrice > 0) {
                $ltv = math_multiply(math_divide($sourceCurrencyPrice, $collateralCurrencyPrice), 100);
            }
        } else {
            $ltv = 0;
        }

        return [
            'id' => $this->id,
            'currency' => $this->currency->name,
            'currency_symbol' => $this->currency->symbol,
            'collateral_symbol' => $this->collateral->symbol,
            'currency_logo' => url($this->currency->logo_path),
            'amount' => $this->amount,
            'ltv' => math_formatter($ltv, 2),
            'remaining_amount' => $this->remaining_amount,
            'status' => $this->status,
            'created_at' => $this->created_at->format('d-m-Y H:i'),
        ];
    }
}
