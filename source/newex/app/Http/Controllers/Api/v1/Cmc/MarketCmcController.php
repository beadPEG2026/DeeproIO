<?php
/*
 *  Copyright 2021. Crypto Smart Solutions, LLC
 *  Protected with Proprietary License
 */

namespace App\Http\Controllers\Api\v1\Cmc;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Market\MarketCmcDataRequest;
use App\Http\Resources\Market\Cmc\AssetCmc;
use App\Http\Resources\Market\Cmc\MarketCmc;
use App\Http\Resources\Market\Cmc\TickerCmc;
use App\Http\Resources\Market\Cmc\TransactionCmc;
use App\Models\Market\Market;
use App\Models\Order\Order;
use App\Repositories\Currency\CurrencyRepository;
use App\Repositories\Order\OrderRepository;
use App\Services\Market\MarketService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/*
 * This controller used to provide with the required API endpoints for CoinMarketCap
 */

class MarketCmcController extends Controller
{
    /**
     * @var marketService
     */
    protected $marketService;

    /**
     * MarketCMCController Constructor
     *
     * @param MarketService $marketService
     *
     */
    public function __construct(MarketService $marketService)
    {
        $this->marketService = $marketService;
    }

    /**
     * Display a listing of the markets.
     *
     * @return \Illuminate\Http\Response
     */
    public function markets(Request $request)
    {
        return MarketCmc::collection($this->marketService->getMarkets(false));
    }

    /**
     * Display a listing of the ticker per each market.
     *
     * @return \Illuminate\Http\Response
     */
    public function ticker(Request $request)
    {
        return TickerCmc::collection($this->marketService->getMarkets(false));
    }

    /**
     * Display a listing of bids/asks per marker orderbook.
     *
     * @return \Illuminate\Http\Response
     */
    public function orderbook(MarketCmcDataRequest $request) {

        $market = $request->get('market_pair');

        $model = Market::whereName($market)->first();

        $orderRepository = new OrderRepository();

        $asks = collect($orderRepository
            ->get($market, Order::SIDE_SELL))
            ->map(function ($item) use ($model) {
                return [
                    'price' => math_formatter($item->price, $model->quote_precision),
                    'quantity' => $item->quantity
                ];
            })
            ->merge(app(\App\Services\Market\FundedLiquidity::class)->levels($model)['asks'])
            ->sortBy('price')
            ->groupBy(['price'])
            ->map(function ($item) use ($model) {
                return [
                    math_formatter($item->first()['price'], $model->quote_precision),
                    $item->sum('quantity')
                ];
        })->values();

        $bids = collect($orderRepository
            ->get($market, Order::SIDE_BUY))
            ->map(function ($item) use ($model) {
                return [
                    'price' => math_formatter($item->price, $model->quote_precision),
                    'quantity' => $item->quantity
                ];
            })
            ->merge(app(\App\Services\Market\FundedLiquidity::class)->levels($model)['bids'])
            ->sortByDesc('price')->groupBy(['price'])
            ->map(function ($item) use ($model) {
            return [
                math_formatter($item->first()['price'], $model->quote_precision),
                $item->sum('quantity')
            ];
        })->values();

        return [
            'timestamp' => Carbon::now()->getTimestampMs(),
            'bids' => $bids,
            'asks' => $asks
        ];
    }


    public function trades(MarketCmcDataRequest $request)
    {
        $market = $request->get('market_pair');
        $limit = $request->get('limit');

        return TransactionCmc::collection($this->marketService->getTrades($market, $limit, false, true));
    }

    /**
     * Display a listing of market assets
     *
     * @return \Illuminate\Http\Response
     */
    public function assets(Request $request)
    {
        $currencyRepository = new CurrencyRepository();

        return AssetCmc::collection($currencyRepository->all(false));
    }


    public function index()
    {
        return response()->json(null, STATUS_NOT_FOUND);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        return response()->json(null, STATUS_NOT_FOUND);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        return response()->json(null, STATUS_NOT_FOUND);
    }
}
