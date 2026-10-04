<?php

namespace App\Services\Market;

use App\Events\MarketStatsUpdated;
use App\Jobs\Market\MarketCapCalculationJob;
use App\Models\Market\Market;
use App\Repositories\Market\MarketRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MarketService {

    private $marketRepository;

    public function __construct(?MarketRepository $marketRepository = null)
    {
        $this->marketRepository = $marketRepository ?? new MarketRepository();
    }

    public function getMarkets($paginate = true, $dashboard = false) {
        return $this->marketRepository->all($paginate, $dashboard);
    }

    public function getMarket($id, $trashed = false, $dashboard = false) {
        return $this->marketRepository->get($id, $trashed, $dashboard);
    }

    public function storeMarket() {
        return $this->marketRepository->store(request()->all());
    }

    public function updateMarket($id) {
        return $this->marketRepository->update($id, request()->all());
    }

    public function deleteMarket($id) {
        return $this->marketRepository->delete($id);
    }

    public function restoreMarket($id) {
        return $this->marketRepository->restore($id);
    }

    public function getTrades($market, $limit = false, $side = false, $takerOnly = false) {
        return $this->marketRepository->getTrades($market, $limit, $side, $takerOnly);
    }

    public function getCandles($market = null) {
        return $this->marketRepository->getCandles($market);
    }

    public function updateStats($market_id, $price, $volume, $quoteVolume) {
        market_set_stats($market_id, 'last', $price);
        market_set_stats($market_id, 'high', $price);
        market_set_stats($market_id, 'low', $price);
        market_set_stats($market_id, 'volume', $volume);
        market_set_stats($market_id, 'volumeQuote', $quoteVolume);
    }

    public function updateStatsForce($market_id, $type, $value) {
        market_set_stats_force($market_id, $type, $value);
    }

    public function calculateMarketCapitalization(Market $market) {

        $capitalization = $this->marketRepository->getCapitalization($market->id);

        $bidsTotal = Cache::get("markets_liquidity.$market->name.bids_total", 0);
        $asksTotal = Cache::get("markets_liquidity.$market->name.asks_total", 0);

        market_set_stats($market->id, 'capitalization', ($capitalization + $bidsTotal + $asksTotal));

        event(new MarketStatsUpdated($market));
    }

    public function parseLiquidity($market) {

        $model = Market::whereName($market)->first();

        if(!$model) return;

        $marketName = market_sanitize($market);

        $response = Http::get("https://api.binance.com/api/v3/depth?symbol=$marketName&limit=100");

        if ($response->successful()) {

            $orderbook = $response->json();


            // Asks
            if (isset($orderbook['asks'])) {

                $asks = new Collection($orderbook['asks']);
                $ratio = $model->discount * 0.01;

                $asks = $asks->map(function ($item, $key) use ($asks, $ratio) {
                    return [
                        'price' => $asks[$key][0] + ($asks[$key][0] * $ratio),
                        'quantity' => $asks[$key][1],
                    ];
                });

                $limitedAsks = $asks->slice(0, 14);

                Cache::put("markets_liquidity.$market.asks", $limitedAsks);
                Cache::put("markets_liquidity.$market.asks_total", $limitedAsks->sum('quantity'));
            }

            // Bids
            if (isset($orderbook['bids'])) {

                $bids = new Collection($orderbook['bids']);
                $ratio = $model->discount_bid * 0.01;

                $bids = $bids->map(function ($item, $key) use ($bids, $ratio) {
                    return [
                        'price' => $bids[$key][0] + ($bids[$key][0] * $ratio),
                        'quantity' => $bids[$key][1],
                    ];
                });

                $limitedBids = $bids->slice(0, 14);
                Cache::put("markets_liquidity.$market.bids_total", $limitedBids->sum('quantity'));
                Cache::put("markets_liquidity.$market.bids", $limitedBids);
            }

        } else {

            $result = $response->json();

            if(isset($result['code']) && $result['code'] == '-1121') {
                Log::info("Market $market not found on Binance");
            }

        }

    }

    public function updateMarketsInfoCache() {

        $markets = Market::get();
        $marketsCache = [];

        foreach ($markets as $market) {
            $marketsCache[market_sanitize($market->name)] = $market->id;
        }

        Cache::put('marketPairs', $marketsCache);
    }
}
