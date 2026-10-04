<?php

namespace App\Services\Currency;

use App\Models\Currency\Currency;
use App\Models\User\User;
use App\Repositories\Currency\CurrencyRepository;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CurrencyService {

    private $currencyRepository;

    public function __construct()
    {
        $this->currencyRepository = new CurrencyRepository();
    }

    public function getCurrencies($paginate = true, $dashboard = false) {
        return $this->currencyRepository->all($paginate, $dashboard);
    }

    public function getCurrency($id, $trashed, $dashboard = false) {
        return $this->currencyRepository->get($id, $trashed, $dashboard);
    }

    public function getCurrencyBySymbol($symbol, $type = false, $active = true, $relations = null) {
        return $this->currencyRepository->getCurrencyBySymbol($symbol, $type, $active, $relations);
    }

    public function storeCurrency() {
        return $this->currencyRepository->store(request()->all());
    }

    public function updateCurrency($id) {
        return $this->currencyRepository->update(
            $id,
            request()->all()
        );
    }

    public function deleteCurrency($id) {
        return $this->currencyRepository->delete($id);
    }

    public function restoreCurrency($id) {
        return $this->currencyRepository->restore($id);
    }

    public function calculateSystemFee($network, $model, $amount) {

        if($network == "erc20") {
            $feeAmount = $model->deposit_fee_erc == 0 ? $model->deposit_fee_erc_fixed : math_percentage($amount, $model->deposit_fee_erc);
        } elseif($network == "bep20") {
            $feeAmount = $model->deposit_fee_bep == 0 ? $model->deposit_fee_bep_fixed : math_percentage($amount, $model->deposit_fee_bep);
        } elseif($network == "trc20") {
            $feeAmount = $model->deposit_fee_trc == 0 ? $model->deposit_fee_trc_fixed : math_percentage($amount, $model->deposit_fee_trc);
        } elseif($network == "matic20") {
            $feeAmount = $model->deposit_fee_matic == 0 ? $model->deposit_fee_matic_fixed : math_percentage($amount, $model->deposit_fee_matic);
        } elseif($network == "solspl") {
            $feeAmount = $model->deposit_fee_sol == 0 ? $model->deposit_fee_sol_fixed : math_percentage($amount, $model->deposit_fee_sol);
        } else {
            $feeAmount = $model->deposit_fee == 0 ? $model->deposit_fee_fixed : math_percentage($amount, $model->deposit_fee);
        }

        return $feeAmount;
    }

    public function getDailyWithdrawalLimit() {
        return $this->currencyRepository->getDailyWithdrawalLimit();
    }

    public function getDailyAvailableWithdrawal(Currency $currency, User $user) {
        return $this->currencyRepository->getDailyAvailableWithdrawal($currency, $user);
    }

    public function increase(Currency $currency, $quantity) {
        DB::table('currencies')
            ->where('id', $currency->id)
            ->update([
                'wallet_balance' => DB::raw("wallet_balance + $quantity"),
                'wallet_balance_updated_at' => Carbon::now(),
            ]);
    }

    public function decrease(Currency $currency, $quantity) {
        DB::table('currencies')
            ->where('id', $currency->id)
            ->update([
                'wallet_balance' => DB::raw("wallet_balance - $quantity"),
                'wallet_balance_updated_at' => Carbon::now(),
            ]);
    }

    public function updateCurrenciesInfoCache() {

        $currencies = Currency::get();
        $currenciesCache = [];

        foreach ($currencies as $currency) {
            $currenciesCache[$currency->symbol] = $currency->id;
        }

        Cache::put('currencies', $currenciesCache);
    }
}
