<?php

namespace App\Http\Controllers\Web\Client;

use App\Http\Controllers\Controller;
use App\Repositories\Currency\CurrencyRepository;
use App\Repositories\Market\MarketRepository;
use App\Services\Withdrawal\WithdrawalFeeService;
use Inertia\Inertia;
use Setting;
use App\Helpers\Order\OrderHelper;

class CurrencyController extends Controller
{
    public function fees()
    {
        $repo = new CurrencyRepository();
        // Get all active coin currencies (no pagination to keep a single table)
        $currencies = $repo->all(false, false, ['file','networks'], 'coin');

        // This page receives Eloquent models directly rather than the API
        // currency resource, so apply the original configured fees here.
        $feeService = new WithdrawalFeeService();
        $withdrawalFeeFields = [
            'withdraw_fee',
            'withdraw_fee_fixed',
            'withdraw_fee_bep',
            'withdraw_fee_erc',
            'withdraw_fee_trc',
            'withdraw_fee_sol',
            'withdraw_fee_matic',
            'withdraw_fee_bep_fixed',
            'withdraw_fee_erc_fixed',
            'withdraw_fee_trc_fixed',
            'withdraw_fee_sol_fixed',
            'withdraw_fee_matic_fixed',
        ];

        $currencies = $currencies->map(function ($currency) use ($feeService, $withdrawalFeeFields) {
            // CurrencyRepository may return a cached collection; never mutate
            // the cached model instance in place.
            $currency = clone $currency;
            // Use the same identity-checked icon as wallets and market pages.
            $currency->append('logo_path');

            foreach ($withdrawalFeeFields as $field) {
                $currency->setAttribute($field, $feeService->applyIncrease($currency->{$field}));
            }

            return $currency;
        });

        return Inertia::render('Fees', [
            'currencies' => $currencies,
            'futuresMakerFee'=>(float)Setting::get('futures.maker_fee',INITIAL_FUTURES_MAKER_FEE),
            'futuresTakerFee'=>(float)Setting::get('futures.taker_fee',INITIAL_FUTURES_TAKER_FEE),
            'makerFee'=>(float)Setting::get('trade.maker_fee',INITIAL_TRADE_MAKER_FEE),
            'takerFee'=>(float)Setting::get('trade.taker_fee',INITIAL_TRADE_TAKER_FEE),
        ]);
    }

    public function tradingRules()
    {
        $marketRepo = new MarketRepository();
        $markets = $marketRepo->all(false, false); // all active markets with relations

        $makerFee = Setting::get('trade.maker_fee', INITIAL_TRADE_MAKER_FEE);
        $takerFee = Setting::get('trade.taker_fee', INITIAL_TRADE_TAKER_FEE);

        return Inertia::render('TradingRules', [
            'markets' => $markets,
            'introduction'=>app(\App\Services\Content\SitePresentation::class)->get('help')['rules_intro'],
            'makerFee' => (float)$makerFee,
            'takerFee' => (float)$takerFee,
        ]);
    }
}
