<?php

namespace App\Repositories\Lending;

use App\Models\Lending\Lending;
use App\Models\Lending\LendingCurrencies;
use App\Repositories\Currency\CurrencyRepository;
use Auth;

class LendingRepository
{
    /**
     * @var Lending
     */
    protected $lending;

    /**
     * LendingRepository constructor.
     *
     */
    public function __construct()
    {
        $this->lending = new Lending();
    }

    public function get($isAdmin = true) {

        $lendings = Lending::query();

        if(!$isAdmin) {
            $lendings->visible();
        }

        $lendings->has('currency');

        $lendings->with('currency.file');

        $lendings->orderBy('id', 'desc');

        return $lendings->paginate(50)->withQueryString();
    }

    public function getLendingById($id) {
        return Lending::find($id);
    }

    public function getLendingByCurrency($id) {
        return Lending::where('currency_id',$id)->active()->first();
    }

    public function store($data) {

        $lending = $this->lending->create($data);

        return $lending->fresh();
    }

    public function storeCollateral($data) {

        $currencies = new LendingCurrencies();

        $currency = $currencies->create($data);

        return $currency->fresh();
    }

    public function update($id, $data) {
        $lending = Lending::find($id);
        $lending->update($data);
        return $lending->fresh();
    }

    public function collateralUpdate($id, $data) {
        $lending = LendingCurrencies::find($id);
        $lending->update($data);
        return $lending->fresh();
    }

    public function delete($id) {

        $lending = Lending::find($id);
        $lending->delete();

        return true;
    }

    public function collateralDelete($id) {

        $lending = LendingCurrencies::find($id);
        $lending->delete();

        return true;
    }

    public function collaterals($lending) {
        return LendingCurrencies::where('lending_id', $lending)->with('currency.file')->get();
    }

    public function getCollateralById($currency) {
        return LendingCurrencies::where('id', $currency)->with('currency')->first();
    }

    public function getCollateralByCurrency($lending, $currency) {
        return LendingCurrencies::where('lending_id', $lending)->where('currency_id', $currency)->with('currency')->first();
    }

    public function getCollateralRequiredAmount($type, $amount, $lending, $collateralCurrency) {

        $amount = math_formatter($amount, 8);

        $liquidationLtv = 0;
        $initialLtv = 0;

        if($type == "flexible") {
            $initialLtv = $collateralCurrency->flex_initial_ltv;
            $liquidationLtv = $collateralCurrency->flex_liquidation_ltv;
        } elseif($type == "weekly") {
            $initialLtv = $collateralCurrency->weekly_initial_ltv;
            $liquidationLtv = $collateralCurrency->weekly_liquidation_ltv;
        } elseif($type == "monthly") {
            $initialLtv = $collateralCurrency->monthly_initial_ltv;
            $liquidationLtv = $collateralCurrency->monthly_liquidation_ltv;
        }

        $currencyRepository = new CurrencyRepository();

        $collateralAmountRequired = (100 / $initialLtv) * $amount;

        $sourceCurrencyPrice = $currencyRepository->currencyPriceInUsd($lending->currency);
        $collateralCurrencyPrice = $currencyRepository->currencyPriceInUsd($collateralCurrency->currency);

        if($collateralCurrencyPrice == 0) {
            $collateralAmountRequired = 0;
        } else {
            $collateralAmountRequired = ($sourceCurrencyPrice * $collateralAmountRequired) / $collateralCurrencyPrice;
        }

        return [
            'amount' => $collateralAmountRequired,
            'initial_ltv' => $initialLtv,
            'liquidation_ltv' => $liquidationLtv
        ];
    }
}
