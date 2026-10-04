<?php
namespace App\Services\Market;
use Illuminate\Support\Facades\Http;
/** Additive market-data reader. Does not route orders or replace wallet providers. */
final class StockDataClient {
    public function call(string $path,array $input=[]):array {
        if(!in_array($path,['/v1/stocks/quotes','/v1/stocks/candles','/v1/stocks/depth','/v1/stocks/inspect','/v1/stocks/trades'],true))throw new \InvalidArgumentException('unsupported_stock_request');
        $input += $path === '/v1/stocks/quotes' ? ['assets'=>StockAssets::all()] : ['asset'=>StockAssets::find($input['symbol'] ?? '')];
        if ($path === '/v1/stocks/quotes') {
            $hk=array_values(array_filter($input['assets'],fn($a)=>HongKongPriceProduct::isAsset($a)));
            if ($hk) {
                if (count($hk)>1) app(HongKongMarketData::class)->warmQuotes($hk);
                $input['assets']=array_values(array_filter($input['assets'],fn($a)=>!HongKongPriceProduct::isAsset($a)));
                try {$data=$input['assets'] ? $this->remote($path,$input,4) : ['data'=>[]];}
                catch (\Throwable $e) {$data=['data'=>array_map(fn($a)=>['symbol'=>$a['symbol'],'unavailable'=>true],$input['assets'])];}
                foreach ($hk as $asset) {
                    try {$data['data'][]=app(HongKongPriceProduct::class)->quote($asset,!($input['native_only'] ?? false));}
                    catch (\Throwable $e) {$data['data'][]=['symbol'=>$asset['symbol'],'unavailable'=>true];}
                }
                return $data;
            }
        }
        if (HongKongPriceProduct::isAsset($input['asset'] ?? [])) {
            app(HongKongPriceProduct::class)->definition($input['asset']);
            if ($path === '/v1/stocks/candles') return app(HongKongMarketData::class)->candles($input['asset'],$input['interval']??'1d',$input['limit']??120,$input['to']??null);
            if ($path === '/v1/stocks/depth') return ['data'=>['bids'=>[],'asks'=>[],'executable'=>false,'source'=>'deepro-local-orders']];
            if ($path === '/v1/stocks/trades') return ['data'=>[],'source'=>'deepro','reference_only'=>false];
            throw new \InvalidArgumentException('unsupported_hk_reference_request');
        }
        return $this->remote($path,$input);
    }
    private function remote(string $path,array $input,int $timeout=5):array {
        $response=Http::connectTimeout(3)->timeout($timeout)->withOptions(['allow_redirects'=>false])->post(rtrim(config('stock-tokens.data_url'),'/').$path,$input);
        $data=$response->json();
        if(!$response->successful()||!is_array($data)||!isset($data['data']))throw new \RuntimeException($data['error'] ?? 'stock_data_unavailable');
        return $data;
    }
}
