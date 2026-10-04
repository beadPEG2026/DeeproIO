<?php
namespace App\Http\Controllers\Web\Client;

use App\Http\Controllers\Controller;
use App\Models\Market\Market;
use App\Services\Market\{GlobalMarketOverview, StockCatalog};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

final class MarketDiscoveryController extends Controller
{
    public function overview(GlobalMarketOverview $data) { return response()->json($data->snapshot()); }
    public function news(Request $request, \App\Services\Market\StockNews $news)
    {
        $input=$request->validate(['region'=>'required|in:US,HK']);
        return response()->json($news->snapshot($input['region']));
    }
    public function catalog(StockCatalog $catalog) { return response()->json(['assets'=>$catalog->assets(),'presentation'=>app(\App\Services\Market\MarketPresentation::class)->get()]); }
    public function sparklines(Request $request)
    {
        $input = $request->validate(['markets'=>'required|array|min:1|max:2', 'markets.*'=>'required|string|max:60|regex:/^[A-Za-z0-9]+-[A-Za-z0-9]+$/']);
        $allowed = Market::query()->active()->whereIn('name', $input['markets'])->get()->keyBy('name');
        $stocks = collect(app(StockCatalog::class)->assets())->keyBy(fn($a)=>$a['symbol'].'-USDT');
        $rows=[];
        foreach (array_unique($input['markets']) as $name) {
            $m=$allowed->get($name);
            if (!$m || (\App\Services\Market\StockAssets::supports($name) && !$stocks->has($name))) continue;
            // Includes chart mapping/version. Reuse the detail chart's BS/multiplier path.
            $key='market-overview.spark.v2.'.hash('sha256', $name.'|'.json_encode(array_diff_key($m->getAttributes(),array_flip(['last','high','low','volume','qVolume','change','updated_at']))).'|'.floor(time()/300));
            if (Cache::has($key)) { $rows[$name]=Cache::get($key); continue; }
            $value=(function () use ($name, $m) {
                $lock=Cache::lock('market-overview.spark-lock.'.$name, 60);
                if (!$lock->get()) return ['points'=>[], 'unavailable'=>true, 'retryable'=>true];
                try {
                    $daily=\App\Services\Market\HongKongPriceProduct::isMarket($m);
                    $to=time(); $from=$to-($daily ? 90*86400 : 86400);
                    $result=app(ChartController::class)->history(Request::create('/', 'GET', ['symbol'=>$name,'from'=>$from,'to'=>$to,'resolution'=>$daily?'1D':'60','countback'=>$daily?30:25]))->getData(true);
                    $points=[];
                    if (($result['s']??null)==='ok') foreach (($result['t']??[]) as $i=>$t) {
                        $v=$result['c'][$i]??null;
                        if (is_numeric($t) && $t >= $from && $t <= $to && is_numeric($v) && (float)$v>0 && is_finite((float)$v)) $points[]=['time'=>(int)$t,'value'=>(float)$v];
                    }
                    usort($points,fn($a,$b)=>$a['time']<=>$b['time']);
                    return ['points'=>array_slice($points,-25), 'unavailable'=>count($points)<2, 'window'=>$daily?'trading_days':'24h', 'resolution'=>$daily?'1D':'60', 'currency'=>$daily?'HKD':$m->quoteCurrency->symbol, 'receivedAt'=>now()->toIso8601String()];
                } catch (\Throwable $e) { return ['points'=>[], 'unavailable'=>true]; }
                finally { $lock->release(); }
            })();
            if (!($value['retryable']??false)) Cache::put($key,$value,empty($value['unavailable'])?300:60);
            $rows[$name]=$value;
        }
        return response()->json(['data'=>$rows]);
    }
}
