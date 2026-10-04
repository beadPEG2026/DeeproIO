<?php
namespace App\Services\Market;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\{Cache,Http};

/** Native, delayed HKD bars. Never used for executable platform quotes. */
final class HongKongIntraday
{
    public function candles(array $asset,string $interval,int $limit,?int $to): array
    {
        $sourceInterval=$interval==='1h'?'5m':$interval;
        if (!in_array($sourceInterval,['1m','5m','15m'],true)) throw new \InvalidArgumentException('hk_intraday_interval');
        $symbol=str_pad((string)(int)$asset['securityCode'],4,'0',STR_PAD_LEFT).'.HK';
        $now=CarbonImmutable::now()->timestamp;$end=min($to===null?$now:$to+1,$now);
        // Public history is bounded. Missing old windows must not fall back to daily bars.
        $earliest=$now-($sourceInterval==='1m'?6:59)*86400;
        $empty=['data'=>[],'symbol'=>$asset['symbol'],'currency'=>'HKD','interval'=>$interval,
            'source'=>'Yahoo Finance delayed','reference_only'=>true,'delayed'=>true,
            'adjustment'=>'unadjusted','volume_unit'=>'share','history_limited'=>true];
        if ($end<=$earliest) return $empty;
        $start=max($earliest,$end-($sourceInterval==='1m'?6:30)*86400);
        $key='hk-intraday.v1.'.hash('sha256',json_encode([$symbol,$sourceInterval,(int)($end/60)]));
        $body=Cache::remember($key,60,function()use($symbol,$sourceInterval,$start,$end){
            $r=Http::connectTimeout(3)->timeout(6)->withOptions(['allow_redirects'=>false])
                ->withHeaders(['User-Agent'=>'Mozilla/5.0 DeeproMarketData/1.0'])
                ->get('https://query1.finance.yahoo.com/v8/finance/chart/'.$symbol,
                    ['interval'=>$sourceInterval,'period1'=>$start,'period2'=>$end,'includePrePost'=>'false']);
            $r->throw();$body=$r->json();
            if (!is_array($body)) throw new \RuntimeException('hk_intraday_unavailable');
            $this->normalize($body,$symbol,$sourceInterval);
            return $body;
        });
        $rows=$this->normalize($body,$symbol,$sourceInterval);
        if ($interval==='1h') $rows=$this->aggregateHours($rows);
        $rows=array_values(array_filter($rows,fn($r)=>$r['time']/1000<$end));
        return array_replace($empty,['data'=>array_slice($rows,-$limit),'receivedAt'=>CarbonImmutable::now()->toIso8601String()]);
    }

    public function normalize(array $body,string $symbol,string $interval): array
    {
        $result=$body['chart']['result'][0]??[];$meta=$result['meta']??[];
        if (($body['chart']['error']??null)!==null || ($meta['symbol']??null)!==$symbol
            || ($meta['currency']??null)!=='HKD' || ($meta['exchangeName']??null)!=='HKG'
            || ($meta['exchangeTimezoneName']??null)!=='Asia/Hong_Kong' || ($meta['dataGranularity']??null)!==$interval)
            throw new \RuntimeException('hk_intraday_identity_mismatch');
        $minutes=['1m'=>1,'5m'=>5,'15m'=>15][$interval]??0;
        $times=$result['timestamp']??[];$quote=$result['indicators']['quote'][0]??[];
        if (!$minutes || !is_array($times) || count($times)>30000) throw new \RuntimeException('hk_intraday_invalid');
        // A valid symbol with no trades in the requested window returns quote: [{}].
        if ($times===[] && $quote===[]) return [];
        foreach (['open','high','low','close','volume'] as $field) {
            if (!is_array($quote[$field]??null) || count($quote[$field])!==count($times)) throw new \RuntimeException('hk_intraday_shape');
        }
        $rows=[];
        foreach ($times as $i=>$time) {
            if (!is_int($time) || $time<=0 || $time>CarbonImmutable::now()->timestamp) continue;
            $date=CarbonImmutable::createFromTimestampUTC($time)->setTimezone('Asia/Hong_Kong');
            $minute=$date->hour*60+$date->minute;
            // Closing auction included; lunch, weekends and null placeholders excluded.
            if ($date->isWeekend() || !(($minute>=570&&$minute<720)||($minute>=780&&$minute<970)) || $date->second || $minute%$minutes) continue;
            $row=['time'=>$time*1000];$valid=true;
            foreach (['open','high','low','close','volume'] as $field) {
                $v=$quote[$field][$i];
                if (!is_numeric($v)||!is_finite((float)$v)||($field==='volume'?$v<0:$v<=0)) {$valid=false;break;}
                $row[$field]=(float)$v;
            }
            if (!$valid) continue;
            if ($row['high']<max($row['open'],$row['close']) || $row['low']>min($row['open'],$row['close'])) throw new \RuntimeException('hk_intraday_ohlc');
            if (isset($rows[$time])&&$rows[$time]!==$row) throw new \RuntimeException('hk_intraday_conflict');
            $rows[$time]=$row;
        }
        ksort($rows);return array_values($rows);
    }

    public function aggregateHours(array $rows): array
    {
        $groups=[];
        foreach ($rows as $row) {
            $date=CarbonImmutable::createFromTimestampUTC((int)($row['time']/1000))->setTimezone('Asia/Hong_Kong');
            $minute=$date->hour*60+$date->minute;$anchor=$minute<720?570:780;
            $key=$date->startOfDay()->addMinutes($anchor+(int)floor(($minute-$anchor)/60)*60)->timestamp*1000;
            if (!isset($groups[$key])) $groups[$key]=array_replace($row,['time'=>$key]);
            else {
                $groups[$key]['close']=$row['close'];$groups[$key]['high']=max($groups[$key]['high'],$row['high']);
                $groups[$key]['low']=min($groups[$key]['low'],$row['low']);$groups[$key]['volume']+=$row['volume'];
            }
        }
        // Thinly traded stocks have legitimate gaps. Never insert manufactured prices.
        return array_values($groups);
    }
}
