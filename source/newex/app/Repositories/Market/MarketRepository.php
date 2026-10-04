<?php

namespace App\Repositories\Market;

use App\Events\MarketStatsUpdated;
use App\Interfaces\Market\MarketRepositoryInterface;
use App\Models\Market\Market;
use App\Models\Market\MarketAdmin;
use App\Models\Order\Order;
use App\Models\Transaction\Transaction;
use Auth;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class MarketRepository implements MarketRepositoryInterface
{
    /**
     * @var Market
     */
    protected $market;

    /**
     * MarketRepository constructor.
     *
     */
    public function __construct()
    {
        $this->market = new Market();
    }

    /**
     * @param $market
     * @param false $trashed
     * @param false $dashboard
     * @return mixed
     */
    public function get($market, $trashed = false, $dashboard = false, $prefix = false) {

        if(is_numeric($market)) {
            $model = Market::whereId($market);
        } elseif($prefix) {
            $model = Market::where(function ($query) use ($market) {
                $query->whereName($market)
                    ->orWhere('name', str_replace('_', '-', $market))
                    ->orWhere('name', str_replace('_', '', $market));
            });
        } else {
            $model = Market::whereName($market);
        }

        if($trashed) {
            $model->withTrashed();
        }

        if(!$dashboard) {
            $model->active();
        }

        return $model->first();
    }

    /**
     * @param $paginate
     * @param false $dashboard
     * @return Illuminate\Database\Eloquent\Collection
     */
    public function all($paginate, $dashboard = false) {

        $market = Market::query();

        if(!$dashboard) {
            $market->active();
        }

        if($paginate) {
            return $market->has('baseCurrency')->has('quoteCurrency')->with(['baseCurrency', 'quoteCurrency'])
                ->filter(request()->only(['search', 'trashed']))
                ->orderByLatest()
                ->paginate(30)
                ->withQueryString();
        }

        if (empty(array_filter(request()->only(['search', 'trashed'])))) {
            $cacheKey = 'markets:all:active:' . ($dashboard ? 'dashboard' : 'public');

            return Cache::remember($cacheKey, now()->addSeconds(60), function () use ($market) {
                return $market->with(['baseCurrency.file', 'quoteCurrency.file'])
                    ->has('baseCurrency')
                    ->has('quoteCurrency')
                    ->get();
            });
        }

        return $market->with(['baseCurrency.file', 'quoteCurrency.file'])->has('baseCurrency')->has('quoteCurrency')->get();
    }

    public function count() {
        $market = Market::query();
        return $market->count();
    }

    /**
     * @param $data
     * @return Market
     */
    public function store($data) {

        $market = $this->market->create($data);

        market_set_stats($market->id, 'last', $market->last);

        return $this->market->fresh();
    }

    /**
     * @param $id
     * @param $data
     * @return Market
     */
    public function update($id, $data) {

        $market = MarketAdmin::withTrashed()->find($id);

        $market->update($data);

        market_set_stats($id, 'last', $data['last']);

        $market = $market->fresh();

        if($market->status) {
            event(new MarketStatsUpdated($market));
        }

        return $market;
    }

    /**
     * @param $id
     * @return bool
     */
    public function delete($id) {

        $market = Market::find($id);
        $market->delete();

        return true;
    }

    /**
     * @param $id
     * @return bool
     */
    public function restore($id) {

        $market = Market::withTrashed()->find($id);
        $market->restore();

        return true;
    }

    /**
     * @param $market
     * @return Illuminate\Database\Eloquent\Collection
     */
    public function getTrades($market, $limit = 20, $side = false, $takerOnly = false) {

        $model = Market::where(function ($query) use ($market) {
            $query->whereName($market)
                ->orWhere('name', str_replace('_', '-', $market))
                ->orWhere('name', str_replace('_', '', $market));
        })->first();

        $trades = Transaction::query();

        if($side == Order::SIDE_BUY) {
            $trades->bids();
        } elseif($side == Order::SIDE_SELL) {
            $trades->asks();
        }

        $trades->where('market_id', $model->id);

        $trades->where('is_volume', 0);

        if($takerOnly) {
            $trades->where(function ($query) {
                $query->where('is_maker', false);
            });
        } else {
            $trades->where(function ($query) {
                $query->where('is_maker', false);
                $query->orWhereNull('order_id');
            });
        }

        if($limit > 1000 || $limit == 0) {
            $limit = 1000;
        }

        return $trades->orderByLatest()->limit($limit)->get();
    }

    /**
     * @param $market
     * @return Illuminate\Database\Eloquent\Collection
     */
    public function getCandles($market) {

        $from = intval(request()->get('from'));
        $to = intval(request()->get('to'));

        $rangeInSeconds = $from - $to;

        if($rangeInSeconds > 3000000) {
            $to = $from;
        }

        $resolution = request()->get('resolution', '5');

        $interval = MARKET_RESOLUTION_ASSOC[$resolution] ?? false;

        if(!$interval) return false;

        $transaction = Transaction::query();

        // Use ARRAY_AGG with ORDER BY for reliable open/close price calculation
        // This ensures close price is always from the latest transaction in the candle
        $transaction->selectRaw("to_char(MIN(created_at), '%d-%m-%Y %H:%i:00') as date2")
            ->selectRaw('(floor(extract(epoch from created_at) / ?) * ?) AS date', [$interval, $interval])
            ->selectRaw('SUM(quote_currency) as volume')
            ->selectRaw('MAX(price) as high')
            ->selectRaw('MIN(price) as low')
            ->selectRaw("(ARRAY_AGG(price ORDER BY created_at DESC))[1] as close")
            ->selectRaw("(ARRAY_AGG(price ORDER BY created_at ASC))[1] as open");

        if($market) {
            $transaction->where('market_id', $market->id);
        }

        // Use inclusive boundaries (>= and <=) to capture edge transactions
        $transaction->whereRaw('extract(epoch from created_at)::integer >= ?', [$from])
            ->whereRaw('extract(epoch from created_at)::integer <= ?', [$to])
            ->maker()
            ->groupByRaw('date')
            ->orderByRaw('date ASC');

        return $transaction->get();
    }

    /**
     * @param $market
     * @return int
     */
    public function getCapitalization($market) {
        return DB::table('orders')->where('market_id', $market)->sum('quantity');
    }
}
