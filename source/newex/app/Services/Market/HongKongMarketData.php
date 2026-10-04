<?php

namespace App\Services\Market;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\{Cache, Http};

/** Public reference data only. Never manufactures executable liquidity. */
class HongKongMarketData
{
    public function read(string $url, array $query): array
    {
        $eastmoney=str_ends_with((string)parse_url($url,PHP_URL_HOST),'.eastmoney.com');
        $lock=null;
        if ($eastmoney) {
            if (Cache::has('hk-reference.eastmoney.cooldown')) throw new \RuntimeException('hk_reference_source_cooldown');
            $lock=Cache::lock('hk-reference.eastmoney.probe',10);
            if (!$lock->get()) throw new \RuntimeException('hk_reference_source_busy');
        }
        try {
            $r = Http::connectTimeout(2)->timeout(4)->withOptions(['allow_redirects' => false])
                ->withHeaders(['User-Agent' => 'DeeproMarketData/1.0'])->get($url, $query);
            if (!$r->successful() || !is_array($r->json())) throw new \RuntimeException('hk_reference_unavailable');
            return $r->json();
        } catch (\Throwable $e) {
            if ($eastmoney) Cache::put('hk-reference.eastmoney.cooldown',true,60);
            throw $e;
        } finally { if ($lock) $lock->release(); }

    }

    public function quote(array $asset): array
    {
        try {$quote=Cache::remember('hk-reference.quote.'.$asset['securityCode'], 15, function () use ($asset) {
            try {return $this->normalizeQuote($this->read('https://push2.eastmoney.com/api/qt/stock/get', [
                'secid' => '116.'.$asset['securityCode'], 'fltt' => 2,
                'fields' => 'f43,f44,f45,f46,f57,f58,f60,f86,f107,f170',
            ]), $asset['securityCode']);}
            catch (\Throwable $e) {return app(HongKongTencentReference::class)->quote($asset['securityCode']);}
        });
            Cache::put('hk-reference.last-quote.'.$asset['securityCode'],$quote,604800);
            return $quote;
        } catch (\Throwable $e) {
            $last=Cache::get('hk-reference.last-quote.'.$asset['securityCode']);
            if (is_array($last) && ($last['eventTime']??0)>CarbonImmutable::now()->timestamp-604800) return $last;
            throw $e;
        }
    }

    public function warmQuotes(array $assets): void
    {
        $missing=array_values(array_filter($assets,fn($a)=>!Cache::has('hk-reference.quote.'.$a['securityCode'])));
        if (!$missing) return;
        try {
            foreach (app(HongKongTencentReference::class)->quotes(array_column($missing,'securityCode')) as $code=>$quote) {
                Cache::put('hk-reference.quote.'.$code,$quote,15);
                Cache::put('hk-reference.last-quote.'.$code,$quote,604800);
            }
        } catch (\Throwable $e) { /* Individual provider fallbacks remain available. */ }
    }

    public function normalizeQuote(array $body, string $code): array
    {
        $d = $body['data'] ?? [];
        if (($body['rc'] ?? null) !== 0 || ($d['f57'] ?? null) !== $code || ($d['f107'] ?? null) !== 116)
            throw new \RuntimeException('hk_quote_identity_mismatch');
        foreach (['f43', 'f44', 'f45'] as $key) $this->positive($d[$key] ?? null);
        $time = $this->timestamp($d['f86'] ?? null);
        if ((float)$d['f44'] < (float)$d['f43'] || (float)$d['f45'] > (float)$d['f43'])
            throw new \RuntimeException('hk_quote_invalid');
        return ['price' => (string)$d['f43'], 'high' => (string)$d['f44'], 'low' => (string)$d['f45'],
            'change' => is_numeric($d['f170'] ?? null) ? (string)$d['f170'] : null,
            'eventTime' => $time, 'currency' => 'HKD', 'source' => 'Eastmoney public reference',
            'receivedAt' => CarbonImmutable::now()->toIso8601String()];
    }

    public function fx(): array
    {
        return Cache::remember('hk-reference.fx', 10, function () {
            try {$hkd = $this->normalizeUsdHkd($this->read('https://push2.eastmoney.com/api/qt/stock/get', [
                'secid'=>'119.USDHKD','fltt'=>2,'fields'=>'f43,f57,f58,f86',
            ]));$source='Eastmoney USDHKD';}
            catch (\Throwable $e) {
                $r=Http::connectTimeout(2)->timeout(4)->withOptions(['allow_redirects'=>false])
                    ->withHeaders(['Referer'=>'https://finance.sina.com.cn/'])->get('https://hq.sinajs.cn/list=fx_susdhkd');
                if (!$r->successful()) throw new \RuntimeException('hk_fx_unavailable');
                $hkd=$this->normalizeSinaUsdHkd($r->body());$source='Sina USDHKD';
            }
            $usdt = $this->normalizeUsdtUsd($this->read('https://api.kraken.com/0/public/Trades', ['pair'=>'USDTUSD','count'=>1]));
            return ['hkdPerUsdt' => bcmul($hkd['price'], $usdt['price'], 18),
                'eventTime' => min($hkd['eventTime'], $usdt['eventTime']),
                'usdHkdTime' => $hkd['eventTime'], 'usdtUsdTime' => $usdt['eventTime'],
                'source' => $source.' × Kraken USDT/USD'];
        });
    }

    public function normalizeSinaUsdHkd(string $body): array
    {
        if (strlen($body)>4000 || !preg_match('/^var hq_str_fx_susdhkd="([^"]*)";\s*$/sD',$body,$match)) throw new \RuntimeException('hk_fx_identity_mismatch');
        $row=explode(',',$match[1]);
        if (count($row)!==18) throw new \RuntimeException('hk_fx_identity_mismatch');
        foreach ([1,2,8] as $i) $this->positive($row[$i]);
        if ((float)$row[1]>(float)$row[2]) throw new \RuntimeException('hk_fx_invalid');
        $stamp=$row[17].' '.$row[0];
        $at=CarbonImmutable::createFromFormat('!Y-m-d H:i:s',$stamp,'Asia/Hong_Kong');
        if (!$at || $at->format('Y-m-d H:i:s')!==$stamp) throw new \RuntimeException('hk_fx_timestamp_invalid');
        return ['price'=>$row[8],'eventTime'=>$this->timestamp($at->timestamp)];
    }

    public function normalizeUsdHkd(array $body): array
    {
        $d = $body['data'] ?? [];
        if (($body['rc'] ?? null) !== 0 || ($d['f57'] ?? null) !== 'USDHKD') throw new \RuntimeException('hk_fx_identity_mismatch');
        $this->positive($d['f43'] ?? null);
        return ['price'=>(string)$d['f43'], 'eventTime'=>$this->timestamp($d['f86'] ?? null)];
    }

    public function normalizeUsdtUsd(array $body): array
    {
        $rows = $body['result']['USDTZUSD'] ?? null;
        if (($body['error'] ?? null) !== [] || !is_array($rows) || count($rows) !== 1)
            throw new \RuntimeException('hk_fx_identity_mismatch');
        $row = $rows[0]; $this->positive($row[0] ?? null);
        if (!is_numeric($row[2] ?? null) || !is_finite((float)$row[2])) throw new \RuntimeException('hk_fx_timestamp_invalid');
        return ['price'=>(string)$row[0], 'eventTime'=>$this->timestamp((int)floor((float)$row[2]))];
    }

    public function candles(array $asset, string $interval, int $limit, ?int $to): array
    {
        // Hourly bars are built from five-minute bars; source 60-minute labels cross lunch.
        $periods = ['1m'=>1, '5m'=>5, '15m'=>15, '1h'=>5, '1d'=>101];
        if (!isset($periods[$interval]) || $limit < 1 || $limit > 300 || ($to !== null && $to < 0))
            throw new \InvalidArgumentException('hk_candle_range_invalid');
        if ($interval!=='1d') {
            try { return app(HongKongIntraday::class)->candles($asset,$interval,$limit,$to); }
            catch (\Throwable $e) { /* Validate alternate native source below. */ }
        }
        $cutoff=$to;
        // Daily source pages depend on a date, not the live polling second. Filter exact cutoffs after cache retrieval.
        if ($interval==='1d') $to=CarbonImmutable::createFromTimestampUTC(min($to??CarbonImmutable::now()->timestamp,CarbonImmutable::now()->timestamp))->endOfDay()->timestamp;
        $query = ['secid'=>'116.'.$asset['securityCode'], 'fields1'=>'f1,f2,f3,f4,f5,f6',
            'fields2'=>'f51,f52,f53,f54,f55,f56,f57,f58,f59,f60,f61',
            'klt'=>$periods[$interval], 'fqt'=>0, 'end'=>$to === null ? '20500000' : gmdate('Ymd', $to+($interval==='1d'?0:86400)),
            'lmt'=>$interval === '1h' ? $limit*12 : $limit];
        $result=Cache::remember('hk-reference.candles.'.hash('sha256', json_encode([$query,$interval,$limit,$to])), 15, function () use ($query, $asset, $interval, $to, $limit) {
            try {$body = $this->read('https://push2his.eastmoney.com/api/qt/stock/kline/get', $query);}
            catch (\Throwable $e) {
                if ($interval !== '1d') throw new \RuntimeException('hk_intraday_reference_unavailable');
                return app(HongKongTencentReference::class)->candles($asset['securityCode'],$limit,$to)+['symbol'=>$asset['symbol']];
            }
            $rejected = 0;
            $rows = $this->normalizeCandles($body, $asset['securityCode'], $interval === '1h' ? '5m' : $interval, $rejected);
            if ($interval === '1h') $rows = $this->aggregateHours($rows);
            if ($to !== null) $rows = array_values(array_filter($rows, fn($r) => $r['time']/1000 <= $to));
            return ['data'=>array_slice($rows,-$limit), 'currency'=>'HKD', 'source'=>'Eastmoney public reference',
                'symbol'=>$asset['symbol'], 'interval'=>$interval, 'reference_only'=>true,
                'adjustment'=>'unadjusted', 'volume_unit'=>'share', 'rejected_bars'=>$rejected,
                'receivedAt'=>CarbonImmutable::now()->toIso8601String()];
        });
        if ($cutoff!==null) $result['data']=array_values(array_filter($result['data'],fn($r)=>$r['time']/1000<=$cutoff));
        return $result;
    }

    public function normalizeCandles(array $body, string $code, string $interval, ?int &$rejected = null): array
    {
        $d = $body['data'] ?? [];
        if (($body['rc'] ?? null) !== 0 || ($d['code'] ?? null) !== $code || ($d['market'] ?? null) !== 116 || !is_array($d['klines'] ?? null))
            throw new \RuntimeException('hk_candle_identity_mismatch');
        $rows = []; $rejected = 0;
        $minutes = ['1m'=>1, '5m'=>5, '15m'=>15, '1d'=>0][$interval] ?? null;
        if ($minutes === null) throw new \InvalidArgumentException('hk_candle_interval_invalid');
        foreach ($d['klines'] as $line) {
            try {
                if (!is_string($line)) throw new \RuntimeException('hk_candle_invalid');
                $v = explode(',', $line);
                if (count($v) < 7) throw new \RuntimeException('hk_candle_invalid');
                $format = $minutes ? 'Y-m-d H:i' : 'Y-m-d';
                $date = CarbonImmutable::createFromFormat('!'.$format, $v[0], $minutes ? 'Asia/Hong_Kong' : 'UTC');
                if (!$date || $date->format($format) !== $v[0]) throw new \RuntimeException('hk_candle_time_invalid');
                // Minute bars are end-labelled. A native daily bar uses its trading date at UTC midnight.
                $timestamp = $date->subMinutes($minutes)->timestamp;
                if ($minutes) {
                    $minute=$date->hour*60+$date->minute;
                    if (!(($minute > 570 && $minute <= 720) || ($minute > 780 && $minute <= 960)) || $minute%$minutes !== 0)
                        throw new \RuntimeException('hk_candle_session_invalid');
                }
                foreach ([1,2,3,4] as $i) $this->positive($v[$i]);
                if (!is_numeric($v[5]) || !is_finite((float)$v[5]) || (float)$v[5] < 0 || (float)$v[3] < max((float)$v[1],(float)$v[2])
                    || (float)$v[4] > min((float)$v[1],(float)$v[2]) || $timestamp > CarbonImmutable::now()->timestamp+60)
                    throw new \RuntimeException('hk_candle_invalid');
                $row = ['time'=>$timestamp*1000, 'open'=>(float)$v[1], 'close'=>(float)$v[2],
                    'high'=>(float)$v[3], 'low'=>(float)$v[4], 'volume'=>(float)$v[5]];
            } catch (\Throwable $e) { ++$rejected; continue; }
            // Conflicting duplicate timestamps are ambiguous, so reject this response entirely.
            if (isset($rows[$timestamp]) && $rows[$timestamp] !== $row) throw new \RuntimeException('hk_candle_conflict');
            $rows[$timestamp] = $row;
        }
        ksort($rows);
        if (!$rows && $rejected) throw new \RuntimeException('hk_candles_all_invalid');
        return array_values($rows);
    }

    public function aggregateHours(array $rows): array
    {
        $groups=[];
        foreach ($rows as $row) {
            $date=CarbonImmutable::createFromTimestampUTC((int)($row['time']/1000))->setTimezone('Asia/Hong_Kong');
            $minute=$date->hour*60+$date->minute;
            $anchor=$minute<720 ? 570 : 780;
            $start=$anchor+(int)floor(($minute-$anchor)/60)*60;
            $key=$date->startOfDay()->addMinutes($start)->timestamp;
            $groups[$key][]=$row;
        }
        $result=[];
        foreach ($groups as $start=>$bars) {
            $minute=(int)CarbonImmutable::createFromTimestampUTC($start)->setTimezone('Asia/Hong_Kong')->format('Hi');
            $count=$minute===1130 ? 6 : 12;
            // Skip incomplete source windows rather than label a partial history as a full hour.
            if (count($bars)!==$count || $bars[0]['time']!==$start*1000 || end($bars)['time']!==($start+($count-1)*300)*1000) continue;
            $result[]=['time'=>$start*1000,'open'=>$bars[0]['open'],'close'=>end($bars)['close'],
                'high'=>max(array_column($bars,'high')),'low'=>min(array_column($bars,'low')),'volume'=>array_sum(array_column($bars,'volume'))];
        }
        return $result;
    }

    private function timestamp($value): int
    {
        if (!is_int($value) || $value <= 0 || $value > CarbonImmutable::now()->timestamp+5) throw new \RuntimeException('hk_reference_timestamp_invalid');
        return $value;
    }

    private function positive($value): void
    {
        if (!is_numeric($value) || !is_finite((float)$value) || (float)$value <= 0)
            throw new \RuntimeException('hk_reference_number_invalid');
    }
}
