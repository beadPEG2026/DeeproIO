<?php
namespace App\Services\Market;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\{Cache, Http};

/** Official daily reference rates for estimates; never an execution or settlement feed. */
final class DisplayExchangeRates
{
    public const CODES=['USDT','USD','CNY','JPY','HKD','EUR'];
    public function refresh(): void
    {
        $response=Http::connectTimeout(3)->timeout(20)->get('https://www.ecb.europa.eu/stats/eurofxref/eurofxref-hist.xml');
        if (!$response->successful() || strlen($response->body())>15000000) throw new \RuntimeException('display_fx_source_unavailable');
        $rates=$this->parseEcb($response->body());
        $usdt=Http::connectTimeout(3)->timeout(8)->get('https://api.kraken.com/0/public/OHLC',['pair'=>'USDTUSD','interval'=>1440]);
        if (!$usdt->successful()) throw new \RuntimeException('display_usdt_source_unavailable');
        $daily=$this->parseUsdt($usdt->json()??[]);
        Cache::put('display.fx.history.v1',['ecb'=>$rates,'usdt'=>$daily,'received_at'=>time()],172800);
    }
    public function parseEcb(string $xml): array
    {
        if (stripos($xml,'<!DOCTYPE')!==false || stripos($xml,'<!ENTITY')!==false) throw new \RuntimeException('display_fx_xml_invalid');
        $body=simplexml_load_string($xml,'SimpleXMLElement',LIBXML_NONET);
        if (!$body) throw new \RuntimeException('display_fx_xml_invalid');
        $result=[];
        foreach ($body->xpath('//*[local-name()="Cube"][@time]') as $day) {
            $date=(string)$day['time'];
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date) || $date>gmdate('Y-m-d')) continue;
            $row=['EUR'=>1.0];
            foreach($day->children() as $c) {
                $code=(string)$c['currency'];$rate=(string)$c['rate'];
                if (in_array($code,self::CODES,true) && is_numeric($rate) && is_finite((float)$rate) && (float)$rate>0) $row[$code]=(float)$rate;
            }
            if (count(array_intersect(['USD','CNY','JPY','HKD','EUR'],array_keys($row)))===5) $result[$date]=$row;
        }
        if (!$result) throw new \RuntimeException('display_fx_empty');
        ksort($result);return $result;
    }
    public function parseUsdt(array $body): array
    {
        if (($body['error']??null)!==[] || !is_array($body['result']['USDTZUSD']??null)) throw new \RuntimeException('display_usdt_identity_invalid');
        $daily=[];
        foreach($body['result']['USDTZUSD'] as $r) {
            // Only closed daily observations. No hindsight from today's incomplete candle.
            if (!is_array($r) || count($r)<8 || !is_numeric($r[0]) || $r[0]%86400!==0 || $r[0]+86400>time()
                || !is_numeric($r[4]) || !is_finite((float)$r[4]) || (float)$r[4]<=0) continue;
            $daily[gmdate('Y-m-d',(int)$r[0])]=(float)$r[4];
        }
        if (!$daily) throw new \RuntimeException('display_usdt_empty');
        ksort($daily);return $daily;
    }
    public function snapshot(): ?array
    {
        $r=Cache::get('display.fx.history.v1');
        return is_array($r) && ($r['received_at']??0)<=time()+5 && time()-($r['received_at']??0)<172800 ? $r : null;
    }
    public function options(): array
    {
        $snapshot=$this->snapshot();$rates=null;$date=null;
        if ($snapshot) {
            $date=array_key_last($snapshot['ecb']);$usdtDate=array_key_last($snapshot['usdt']);
            if (strtotime($date)>=time()-7*86400 && strtotime($usdtDate)>=time()-3*86400) {
                $ecb=$snapshot['ecb'][$date];$rates=['USDT'=>1];
                foreach(['USD','CNY','JPY','HKD','EUR'] as $code) $rates[$code]=$ecb[$code]/$ecb['USD']*$snapshot['usdt'][$usdtDate];
            }
        }
        return array_map(fn($code)=>['symbol'=>$code,'name'=>$code,'rate'=>$code==='USDT'?1:($rates[$code]??null),'as_of'=>$date,'source'=>$code==='USDT'?null:'ECB / Kraken USDT-USD'],self::CODES);
    }
    public function historicalFactor(int $barTime,array $snapshot): ?float
    {
        // Daily fixes published after the HK close are only available to the next session.
        $date=gmdate('Y-m-d',$barTime);
        $ecbDate=null;$usdtDate=null;
        foreach(array_keys($snapshot['ecb']) as $d) if($d<$date)$ecbDate=$d;else break;
        foreach(array_keys($snapshot['usdt']) as $d) if($d<$date)$usdtDate=$d;else break;
        if (!$ecbDate || !$usdtDate || $barTime-strtotime($ecbDate)>7*86400 || $barTime-strtotime($usdtDate)>3*86400) return null;
        $e=$snapshot['ecb'][$ecbDate];return $e['USD']/$e['HKD']/$snapshot['usdt'][$usdtDate];
    }
    public function convertHongKongHistory(array $history,string $ratio='1'): array
    {
        $snapshot=$this->snapshot();
        if (!$snapshot) throw new \RuntimeException('hk_historical_fx_unavailable');
        $out=$history;foreach(['t','o','h','l','c','v'] as $key)$out[$key]=[];
        foreach($history['t'] as $i=>$time) {
            $factor=$this->historicalFactor($time,$snapshot);
            if ($factor===null) continue;
            $out['t'][]=$time;foreach(['o','h','l','c'] as $key)$out[$key][]=$history[$key][$i]*$factor*(float)$ratio;
            $out['v'][]=$history['v'][$i]??0;
        }
        if ($history['t'] && !$out['t']) throw new \RuntimeException('hk_historical_fx_unavailable');
        $out['s']=$out['t']?'ok':'no_data';$out['currency']='USDT';$out['native_currency']='HKD';
        $out['valuation']='previous_available_daily_fx';$out['fx_source']='ECB daily / Kraken USDT-USD daily';
        $out['history_limited']=count($out['t'])<count($history['t'])||($history['history_limited']??false);
        return $out;
    }
}
