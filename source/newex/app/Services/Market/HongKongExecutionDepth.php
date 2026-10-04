<?php
namespace App\Services\Market;

use App\Models\Market\Market;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/** Unit adapter into StockLiquidity. It never supplies inventory or increases credit. */
class HongKongExecutionDepth
{
    public function fetch(Market $market): array
    {
        $url=config('hk-price-products.execution_depth.url');
        if (!config('hk-price-products.execution_depth.permission_confirmed') || !$url)
            throw new \RuntimeException('hk_execution_depth_not_configured');
        if (parse_url($url,PHP_URL_SCHEME)!=='https' || parse_url($url,PHP_URL_USER) || parse_url($url,PHP_URL_PASS))
            throw new \RuntimeException('hk_execution_depth_url_invalid');
        $asset=AssetProfile::presentation($market->baseCurrency);
        $response=Http::connectTimeout(3)->timeout(8)->withOptions(['allow_redirects'=>false])->get($url,['symbol'=>$asset['securityCode']]);
        if (!$response->successful() || !is_array($response->json())) throw new \RuntimeException('hk_execution_depth_unavailable');
        return $this->normalize($market,$response->json(),app(HongKongMarketData::class)->fx());
    }

    /** The endpoint must provide native HKD/share levels, genuine source time, and declared delay. */
    public function normalize(Market $market,array $source,array $fx): array
    {
        $asset=AssetProfile::presentation($market->baseCurrency);
        $definition=app(HongKongPriceProduct::class)->definition($asset);
        if ($market->name!==$asset['symbol'].'-USDT' || $market->quoteCurrency->symbol!=='USDT'
            || ($source['security_code']??null)!==$asset['securityCode'] || ($source['currency']??null)!=='HKD'
            || ($source['quantity_unit']??null)!=='share' || ($source['delayed']??null)!==false
            || ($source['market_status']??null)!=='open' || ($source['is_demo']??false)!==false
            || !is_string($source['source']??null) || trim($source['source'])===''
            || !preg_match('/^\d{1,30}$/D',(string)($source['snapshot_id']??'')))
            throw new \RuntimeException('hk_execution_depth_identity_invalid');
        $this->fresh($source['source_time']??null,20);
        $this->fresh($fx['eventTime']??null,(int)config('hk-price-products.fx_max_age'));
        $rate=$this->positive($fx['hkdPerUsdt']??null);
        $ratio=$this->positive($asset['unitRatio']);
        if (!app(HongKongPriceProduct::class)->sessionOpen() || ($definition['suspended']??false))
            throw new \RuntimeException('hk_execution_session_closed');
        $result=['symbol'=>$asset['symbol'],'source'=>'hk-authorized-depth','source_name'=>$source['source'],
            'security_code'=>$asset['securityCode'],'quote_currency'=>'USDT','quantity_unit'=>'product_unit',
            'denomination'=>1,'mul_point'=>1,'unit_ratio'=>$ratio,'native_currency'=>'HKD','native_quantity_unit'=>'share',
            'hkd_per_usdt'=>$rate,'fx_event_time'=>$fx['eventTime'],'fx_source'=>$fx['source']??null,
            'received_at'=>CarbonImmutable::now()->toIso8601String(),'event_time'=>$source['source_time']*1000,
            'last_update_id'=>(string)$source['snapshot_id'],'delayed'=>false,'native_book'=>[], 'bids'=>[], 'asks'=>[]];
        foreach (['bids','asks'] as $side) {
            if (!is_array($source[$side]??null) || count($source[$side])>20) throw new \RuntimeException('hk_execution_depth_invalid');
            foreach ($source[$side] as $level) {
                if (!is_array($level) || count($level)!==2) throw new \RuntimeException('hk_execution_depth_invalid');
                [$price,$quantity]=array_map(fn($v)=>$this->positive($v),array_values($level));
                // Source quantities are shares, never lots: do not multiply by lot_size.
                $result['native_book'][$side][]=[$price,$quantity];
                $result[$side][]=[bcmul(bcdiv($price,$rate,18),$ratio,18),bcdiv($quantity,$ratio,18)];
            }
            $result['native_book'][$side]??=[];
        }
        return $result;
    }

    public function validate(Market $market,array $book): void
    {
        if (!is_int($book['event_time']??null) || $book['event_time']<=0 || $book['event_time']%1000!==0)
            throw new \RuntimeException('hk_execution_depth_expired');
        $native=['security_code'=>$book['security_code']??null,'currency'=>$book['native_currency']??null,
            'quantity_unit'=>$book['native_quantity_unit']??null,'delayed'=>$book['delayed']??null,'market_status'=>'open',
            'source'=>$book['source_name']??null,'source_time'=>isset($book['event_time'])?intdiv((int)$book['event_time'],1000):null,
            'snapshot_id'=>$book['last_update_id']??null,'bids'=>$book['native_book']['bids']??null,'asks'=>$book['native_book']['asks']??null];
        $expected=$this->normalize($market,$native,['hkdPerUsdt'=>$book['hkd_per_usdt']??null,'eventTime'=>$book['fx_event_time']??null,'source'=>$book['fx_source']??null]);
        foreach (['symbol','source','security_code','quote_currency','quantity_unit','denomination','mul_point','unit_ratio','bids','asks'] as $key)
            if (($book[$key]??null)!==$expected[$key]) throw new \RuntimeException('hk_execution_depth_conversion_invalid');
    }

    private function fresh($timestamp,int $maxAge): void
    {
        $now=CarbonImmutable::now()->timestamp;
        if (!is_int($timestamp) || $timestamp<=0 || $timestamp>$now+5 || $now-$timestamp>$maxAge)
            throw new \RuntimeException('hk_execution_depth_expired');
    }
    private function positive($value): string
    {
        if (!is_string($value) || !preg_match('/^\d{1,16}(?:\.\d{1,18})?$/D',$value) || bccomp($value,'0',18)<=0)
            throw new \RuntimeException('hk_execution_depth_number_invalid');
        return $value;
    }
}
