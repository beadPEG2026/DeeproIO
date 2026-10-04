<?php
namespace App\Services\Market;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/** Independent public fallback. Daily HKD bars only; never turns minute samples into OHLC. */
final class HongKongTencentReference
{
    public function quotes(array $codes): array
    {
        if (!$codes || count($codes)>30 || count(array_filter($codes,fn($c)=>is_string($c)&&preg_match('/^\d{5}$/D',$c)))!==count($codes))
            throw new \InvalidArgumentException('hk_quote_codes_invalid');
        $response=Http::connectTimeout(3)->timeout(8)->withOptions(['allow_redirects'=>false])
            ->get('https://qt.gtimg.cn/q='.implode(',',array_map(fn($c)=>'hk'.$c,$codes)));
        if (!$response->successful() || strlen($response->body())>200000) throw new \RuntimeException('hk_tencent_quote_unavailable');
        preg_match_all('/(?:^|\n)v_hk(\d{5})="([^"]*)";/', $response->body(), $matches, PREG_SET_ORDER);
        $result=[];
        foreach ($matches as $match) {
            if (!in_array($match[1],$codes,true) || isset($result[$match[1]])) continue;
            try {$result[$match[1]]=$this->normalizeQuote(explode('~',$match[2]),$match[1]);} catch (\Throwable $e) { /* Reject this symbol only. */ }
        }
        return $result;
    }

    public function quote(string $code): array
    {
        $response=Http::connectTimeout(3)->timeout(8)->withOptions(['allow_redirects'=>false])->get('https://qt.gtimg.cn/q=hk'.$code);
        if (!$response->successful() || strlen($response->body())>10000
            || !preg_match('/^v_hk'.preg_quote($code,'/').'="([^"]*)";\s*$/sD',$response->body(),$match)) throw new \RuntimeException('hk_tencent_quote_unavailable');
        return $this->normalizeQuote(explode('~',$match[1]),$code);
    }

    public function normalizeQuote(array $row,string $code): array
    {
        if (($row[0]??null)!=='100' || ($row[2]??null)!==$code || ($row[75]??null)!=='HKD') throw new \RuntimeException('hk_tencent_identity_mismatch');
        if (!is_numeric($row[3]??null) || !is_finite((float)$row[3]) || (float)$row[3]<=0) throw new \RuntimeException('hk_tencent_quote_invalid');
        // A valid unchanged closing quote can have no trades and no session range.
        $noTrades = is_numeric($row[6]??null) && (float)$row[6]===0.0 && (string)($row[33]??'')==='0' && (string)($row[34]??'')==='0';
        if (!$noTrades) foreach ([33,34] as $i) if (!is_numeric($row[$i]??null) || !is_finite((float)$row[$i]) || (float)$row[$i]<=0) throw new \RuntimeException('hk_tencent_quote_invalid');
        $date=CarbonImmutable::createFromFormat('!Y/m/d H:i:s',$row[30]??'','Asia/Hong_Kong');
        if (!$date || $date->format('Y/m/d H:i:s')!==$row[30] || $date->timestamp>CarbonImmutable::now()->timestamp+5
            || (!$noTrades && ((float)$row[33]<(float)$row[3] || (float)$row[34]>(float)$row[3]))) throw new \RuntimeException('hk_tencent_quote_invalid');
        return ['price'=>(string)$row[3],'high'=>$noTrades?null:(string)$row[33],'low'=>$noTrades?null:(string)$row[34], 'no_trades'=>$noTrades,
            'change'=>is_numeric($row[32]??null)?(string)$row[32]:null,'eventTime'=>$date->timestamp,
            'currency'=>'HKD','source'=>'Tencent public reference','receivedAt'=>CarbonImmutable::now()->toIso8601String()];
    }

    public function candles(string $code,int $limit,?int $to): array
    {
        $response=Http::connectTimeout(3)->timeout(8)->withOptions(['allow_redirects'=>false])
            ->get('https://web.ifzq.gtimg.cn/appstock/app/kline/kline',['param'=>'hk'.$code.',day,,'.($to===null?'':gmdate('Y-m-d',$to)).','.$limit.',']);
        if (!$response->successful() || !is_array($response->json())) throw new \RuntimeException('hk_tencent_candles_unavailable');
        return $this->normalizeCandles($response->json(),$code,$to);
    }

    public function normalizeCandles(array $body,string $code,?int $to=null): array
    {
        $d=$body['data']['hk'.$code]??[];
        if (($body['code']??null)!==0 || !is_array($d['day']??null) || !is_array($d['qt']['hk'.$code]??null)) throw new \RuntimeException('hk_tencent_candles_invalid');
        // History identity is independent of today's quote/range (zero-volume sessions are valid).
        $identity=$d['qt']['hk'.$code];
        if (($identity[0]??null)!=='100' || ($identity[2]??null)!==$code || ($identity[75]??null)!=='HKD') throw new \RuntimeException('hk_tencent_identity_mismatch');
        $lines=[];
        foreach ($d['day'] as $row) {
            if (!is_array($row) || count($row)<6 || !preg_match('/^\d{4}-\d{2}-\d{2}$/D',(string)$row[0])) throw new \RuntimeException('hk_tencent_day_time_invalid');
            // Validate OHLC through the common native daily-bar normalizer. Turnover is unknown and omitted.
            $lines[]=implode(',',array_slice($row,0,6)).',';
        }
        $rejected=0;
        $rows=app(HongKongMarketData::class)->normalizeCandles(['rc'=>0,'data'=>['code'=>$code,'market'=>116,'klines'=>$lines]],$code,'1d',$rejected);
        if ($to!==null) $rows=array_values(array_filter($rows,fn($r)=>$r['time']/1000<=$to));
        return ['data'=>$rows,'currency'=>'HKD','source'=>'Tencent public reference','interval'=>'1d',
            'reference_only'=>true,'adjustment'=>'unadjusted','volume_unit'=>'share','rejected_bars'=>$rejected,
            'receivedAt'=>CarbonImmutable::now()->toIso8601String(),'fallback'=>true,'supported_intervals'=>['1d']];
    }
}
