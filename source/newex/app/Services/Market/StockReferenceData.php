<?php
namespace App\Services\Market;
use App\Models\Market\Market;
use App\Services\Market\StockDataClient;
use Illuminate\Support\Facades\Cache;

final class StockReferenceData
{
    public static function supports(string $name): bool
    {
        return in_array($name, array_map(fn($a) => $a['symbol'].'-USDT', \App\Services\Market\StockAssets::all()), true);
    }
    public static function quote(string $symbol): ?array
    {
        $row = collect(Cache::get('deepro.stock-quotes', [])['data'] ?? [])->firstWhere('symbol', $symbol);
        $time = $row ? strtotime($row['receivedAt'] ?? '') : false;
        if (!$row || ($row['unavailable'] ?? false) || !$time || $time > time()+5 || time()-$time > 90 || (float)($row['price'] ?? 0) <= 0) return null;
        return $row + ['currency'=>'USD'];
    }
    public function refresh(): void
    {
        // Separate display-only cache. Never overwrite the execution price or local turnover.
        try {
        $quotes = app(StockDataClient::class)->call('/v1/stocks/quotes');
        Cache::put('deepro.stock-quotes', $quotes, 90);
        } finally {
        foreach (Market::whereIn('name',array_map(fn($a)=>$a['symbol'].'-USDT',\App\Services\Market\StockAssets::all()))->get() as $market) {
            broadcast(new \App\Events\MarketStatsLiteUpdated((new \App\Http\Resources\Market\Market($market))->resolve()));
        }
        }
    }
    public function history(Market $market, int $from, int $to, string $resolution, ?int $countback=null): array
    {
        $intervals=['1'=>'1m','5'=>'5m','15'=>'15m','60'=>'1h','240'=>'4h','D'=>'1d','1D'=>'1d'];
        if (!self::supports($market->name) || !isset($intervals[$resolution]) || $from<0 || $to<$from) throw new \InvalidArgumentException('invalid_stock_range');
        $hk=HongKongPriceProduct::isMarket($market);
        $input=['symbol'=>$market->baseCurrency->symbol,'interval'=>$intervals[$resolution],'limit'=>300,'to'=>$hk?max(0,$to-1):$to];
        $result=$hk ? app(StockDataClient::class)->call('/v1/stocks/candles',$input)
            : Cache::remember('deepro.stock-candles.'.hash('sha256',json_encode($input)),15,fn()=>app(StockDataClient::class)->call('/v1/stocks/candles',$input));
        $bars=array_values(array_filter($result['data'],fn($r)=>($hk&&$countback!==null || $r['time']/1000 >= $from) && ($hk?$r['time']/1000<$to:$r['time']/1000<=$to)));
        if ($hk && $countback!==null) {
            // TradingView asks for a number of trading bars, not calendar days. Real older bars may precede `from`.
            $target=min(300,max(1,$countback));
            if (count($bars)<$target && $bars && count($result['data'])+(int)($result['rejected_bars']??0)>=300) {
                $older=$input; $older['to']=(int)($bars[0]['time']/1000)-1;
                try {
                    $extra=app(StockDataClient::class)->call('/v1/stocks/candles',$older);
                    $bars=array_merge(array_filter($extra['data'],fn($r)=>$r['time']<$bars[0]['time']),$bars);
                    $result['rejected_bars']=(int)($result['rejected_bars']??0)+(int)($extra['rejected_bars']??0);
                    if ($extra['source']!==$result['source']) $result['source']=$result['source'].' + '.$extra['source'];
                } catch (\Throwable $e) { $result['partial_history']=true; }
            }
            $bars=array_slice($bars,-$target);
        }
        $response=['s'=>$bars?'ok':'no_data','t'=>[],'o'=>[],'h'=>[],'l'=>[],'c'=>[],'source'=>'binance-wallet-ondo-reference','currency'=>'USD'];
        if (HongKongPriceProduct::isMarket($market)) {
            $response['source']=$result['source']; $response['currency']='HKD';
            $response['delayed']=true; $response['history_limited']=$result['history_limited']??false;
            $response['reference_only']=true; $response['adjustment']='unadjusted'; $response['volume_unit']='share';
            $response['rejected_bars']=$result['rejected_bars'] ?? 0;
            if ($result['partial_history']??false) $response['partial_history']=true;
            $response['v']=array_map(fn($r)=>(float)$r['volume'],$bars);
        }
        foreach($bars as $r) { $response['t'][]=(int)($r['time']/1000); foreach(['o'=>'open','h'=>'high','l'=>'low','c'=>'close'] as $key=>$field)$response[$key][]=(float)$r[$field]; }
        // Provider does not supply token turnover; omit volume instead of fabricating it.
        return $response;
    }
}
