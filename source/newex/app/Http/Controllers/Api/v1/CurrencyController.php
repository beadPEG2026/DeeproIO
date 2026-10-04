<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Currency\CurrencyCollection;
use App\Models\Market\Market;
use App\Repositories\Currency\CurrencyRepository;
use App\Repositories\Market\MarketRepository;
use App\Repositories\Wallet\WalletRepository;
use Dedoc\Scramble\Attributes\ExcludeRouteFromDocs;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * @tags Currencies
 */
class CurrencyController extends Controller
{

    /**
     * List All Currencies
     *
     * Retrieves all available currencies on the platform.
     * Includes both cryptocurrencies and fiat currencies with their details.
     *
     * @operationId listCurrencies
     *
     * @response 200 scenario="Success" {
     *   "data": [
     *     {
     *       "id": 1,
     *       "name": "Bitcoin",
     *       "symbol": "BTC",
     *       "type": "coin",
     *       "logo": "/storage/currencies/btc.png",
     *       "decimals": 8,
     *       "status": true,
     *       "deposit_enabled": true,
     *       "withdrawal_enabled": true,
     *       "min_withdrawal": "0.001",
     *       "max_withdrawal": "100",
     *       "withdrawal_fee": "0.0005"
     *     },
     *     {
     *       "id": 2,
     *       "name": "Tether",
     *       "symbol": "USDT",
     *       "type": "coin",
     *       "logo": "/storage/currencies/usdt.png",
     *       "decimals": 6,
     *       "status": true,
     *       "deposit_enabled": true,
     *       "withdrawal_enabled": true,
     *       "min_withdrawal": "10",
     *       "max_withdrawal": "100000",
     *       "withdrawal_fee": "1"
     *     }
     *   ]
     * }
     *
     * @return CurrencyCollection
     */
    public function index()
    {
        $currencyRepository = new CurrencyRepository();

        return new CurrencyCollection($currencyRepository->all(false));
    }

    /**
     * Get Currency Rates and Portfolio Balance
     *
     * Retrieves USD exchange rates for all currencies.
     * For authenticated users, also returns total portfolio balance.
     *
     * @operationId getCurrencyRates
     *
     * @return \Illuminate\Http\JsonResponse
     */
    #[QueryParameter('wallet', description: 'Balance type: "account", "trade", or "total"', type: 'string', example: 'total')]
    public function rates(Request $request) {

        $type = $request->get('wallet', 'account');

        $currencyRepository = new CurrencyRepository();
        $usdt = $currencyRepository->getCurrencyBySymbol('USDT');
        $usd = $currencyRepository->getCurrencyBySymbol('USD');
        $btc = $currencyRepository->getCurrencyBySymbol('BTC');

        $marketRepository = new MarketRepository();
        $walletRepository = new WalletRepository();
        $user = auth()->user();

        $wallets = false;

        if($user) {
            $wallets = $walletRepository->getWallets($user->id);
        }

        $markets = $marketRepository->all(false, false)->pluck('name', 'id');

        $markets = $markets->map(function($market, $key){
            $sanitizedMarket = market_sanitize($market);
            return [$key] = $sanitizedMarket;
        });

        $flippedMarket = $markets->flip()->toArray();

        $currencies = $currencyRepository->all(false, false, [])->map(function($currency) use ($currencyRepository, $usdt, $usd, $flippedMarket) {

            $rateMarket = $flippedMarket[$currency->symbol . 'USDT'] ?? false;

            if(!$rateMarket) {
                $rateMarket = $flippedMarket[$currency->symbol . 'USD'] ?? false;
            }

            return [
                'id' => $currency->id,
                'name' => $currency->name,
                'symbol' => $currency->symbol,
                'rate' => $currencyRepository->currencyPriceInUsd($currency, $usdt, $usd, $rateMarket)
            ];
        });

        $totalBalance = 0;

        if($wallets) {

            foreach ($wallets as $wallet) {

                $currency = $currencies->filter(function($c) use ($wallet) {
                    return $c['id'] == $wallet->currency_id;
                })->first();

                if ($currency) {
    $rate = math_formatter($currency['rate'], 18, '.', '');

    if ($type == "total") {
        $totalBalance = math_sum(
            $totalBalance,
            math_multiply(
                $wallet->balance_in_wallet + $wallet->balance_in_trade + $wallet->balance_in_lc,
                $rate
            )
        );
    } elseif ($type == "account") {
        $totalBalance = math_sum(
            $totalBalance,
            math_multiply($wallet->balance_in_wallet, $rate)
        );
    } elseif ($type == "trade") {
        $totalBalance = math_sum(
            $totalBalance,
            math_multiply($wallet->balance_in_trade, $rate)
        );
    } elseif ($type == "lc") {
        $totalBalance = math_sum(
            $totalBalance,
            math_multiply($wallet->balance_in_lc, $rate)
        );
    } else {
        $totalBalance = math_sum(
            $totalBalance,
            math_multiply($wallet->balance_in_wallet, $rate)
        );
    }
}
            }

//            $totalBalance = $wallets->sum(function ($wallet) use ($currencies) {
//
//                $currency = $currencies->filter(function($c) use ($wallet) {
//                    return $c['id'] == $wallet->currency_id;
//                })->first();
//
//                return math_multiply(math_sum($wallet->balance_in_wallet, $wallet->balance_in_order), math_formatter($currency['rate'], 18, '.', ''));
//            });
        }

        $btcMarket = false;
        $btcPrice = 0;

        if($btc && $usdt) {
            $btcMarket = Market::where('base_currency_id', $btc->id)->where('quote_currency_id', $usdt->id)->pluck('id');
        } elseif($btc && $usd) {
            $btcMarket = Market::where('base_currency_id', $btc->id)->where('quote_currency_id', $usdt->id)->pluck('id');
        }

        if(isset($btcMarket[0])) {
            $btcPrice = market_get_stats($btcMarket[0], 'last');
        }

        $response = [
            'rates' => $currencies
        ];

        if($user) {
            $response['totatUsdBalance'] = math_formatter($totalBalance, 2);
            $response['totalBtcBalance'] = $btcPrice > 0 ? math_formatter(math_divide($totalBalance, $btcPrice), 8) : 0;
        }

        return response()->json($response);
    }

    #[ExcludeRouteFromDocs]
    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $id
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request, $id)
    {
        return response()->json(null, STATUS_NOT_FOUND);
    }

    #[ExcludeRouteFromDocs]
    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $id
     * @return \Illuminate\Http\Response
     */
    public function create(Request $request, $id)
    {
        return response()->json(null, STATUS_NOT_FOUND);
    }

    #[ExcludeRouteFromDocs]
    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $id
     * @return \Illuminate\Http\Response
     */
    public function show(Request $request, $id)
    {
        return response()->json(null, STATUS_NOT_FOUND);
    }

    #[ExcludeRouteFromDocs]
    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $id
     * @return \Illuminate\Http\Response
     */
    public function edit(Request $request, $id)
    {
        return response()->json(null, STATUS_NOT_FOUND);
    }
}
