<?php
namespace App\Services\Market;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\{Cache, Http};

/** Public display data only; never used to price orders, margin or wallets. */
final class GlobalMarketOverview
{
    private const BASE = 'https://pro-api.coinmarketcap.com/public-api/';
    private const FEEDS = [
        'global' => ['v1/global-metrics/quotes/latest', 300, 1800],
        'altcoin' => ['v1/altcoin-season-index/latest', 900, 7200],
        'sentiment' => ['v3/fear-and-greed/latest', 900, 7200],
    ];
    public function status(): array {
        $out=[];foreach(self::FEEDS as $key=>$feed)$out[$key]=['value'=>Cache::get('market-overview.value.'.$key),'failures'=>Cache::get('market-overview.failures.'.$key,0),'refreshSeconds'=>$feed[1]];return $out;
    }
    public function snapshot(): array
    {
        $due = array_filter(self::FEEDS, fn($f, $key) => !Cache::has('market-overview.next.'.$key), ARRAY_FILTER_USE_BOTH);
        $lock = Cache::lock('market-overview.refresh', 15);
        if ($due && $lock->get()) {
            try {
                // Recheck after acquiring the lock; all visitors share one bounded refresh.
                $due = array_filter($due, fn($f, $key) => !Cache::has('market-overview.next.'.$key), ARRAY_FILTER_USE_BOTH);
                foreach ($due as $key => $_) Cache::put('market-overview.next.'.$key, true, 60);
                $responses = Http::pool(function (Pool $pool) use ($due) {
                    $calls = [];
                    foreach ($due as $key => $feed) $calls[] = $pool->as($key)->acceptJson()->connectTimeout(3)->timeout(7)
                        ->withOptions(['allow_redirects' => false])->get(self::BASE.$feed[0]);
                    return $calls;
                });
                foreach ($due as $key => $feed) {
                    try {
                        $response = $responses[$key] ?? null;
                        if (!$response instanceof \Illuminate\Http\Client\Response || !$response->successful()) throw new \RuntimeException('provider_unavailable');
                        $value = $this->normalize($key, $response->json());
                        Cache::put('market-overview.value.'.$key, $value, 86400);
                        Cache::forget('market-overview.failures.'.$key);
                        Cache::put('market-overview.next.'.$key, true, $feed[1]);
                    } catch (\Throwable $e) {
                        $this->failed($key);
                    }
                }
            } catch (\Throwable $e) {
                foreach ($due as $key => $_) $this->failed($key);
            } finally { $lock->release(); }
        }
        $result = [];
        foreach (self::FEEDS as $key => $feed) {
            $value = Cache::get('market-overview.value.'.$key);
            if (!$value) { $result[$key] = ['available'=>false, 'source'=>'CoinMarketCap']; continue; }
            $age = time() - strtotime($value['sourceTime']);
            $result[$key] = $value + ['available'=>true, 'stale'=>$age > $feed[2] || Cache::has('market-overview.failures.'.$key)];
        }
        return ['data'=>$result, 'receivedAt'=>now()->toIso8601String()];
    }
    public function normalize(string $key, array $body): array
    {
        if ((string)($body['status']['error_code'] ?? '') !== '0') throw new \UnexpectedValueException('provider_error');
        $d = $body['data'] ?? [];
        $rawTime = match ($key) { 'global' => $d['last_updated'] ?? null, 'altcoin' => $d['snapshot_time'] ?? null, 'sentiment' => $d['update_time'] ?? null, default => null };
        if (!is_string($rawTime) || !preg_match('/T.*(?:Z|[+-]\d\d:\d\d)$/', $rawTime)) throw new \UnexpectedValueException('missing_source_time');
        $time = CarbonImmutable::parse($rawTime);
        if ($time->timestamp > time()+60 || $time->timestamp < time()-86400) throw new \UnexpectedValueException('invalid_source_time');
        $result = ['source'=>'CoinMarketCap', 'sourceUrl'=>'https://coinmarketcap.com/charts/', 'sourceTime'=>$time->toIso8601String(), 'fetchedAt'=>now()->toIso8601String()];
        if ($key === 'global') {
            $q = $d['quote']['USD'] ?? [];
            $cap = $this->number($q['total_market_cap'] ?? null);
            if ($cap === null || $cap <= 0) throw new \UnexpectedValueException('invalid_market_cap');
            return $result + ['value'=>$cap, 'unit'=>'USD', 'change'=>$this->number($q['total_market_cap_yesterday_percentage_change'] ?? null), 'btcDominance'=>$this->number($d['btc_dominance'] ?? null)];
        }
        $value = $this->number($key === 'altcoin' ? ($d['altcoin_index'] ?? null) : ($d['value'] ?? null));
        if ($value === null || $value < 0 || $value > 100) throw new \UnexpectedValueException('invalid_index');
        $classification = $key === 'altcoin' ? ($value >= 75 ? 'Altcoin season' : ($value <= 25 ? 'Bitcoin season' : 'Neutral')) : null;
        if ($key === 'sentiment') {
            $classes=['extreme fear'=>'Extreme fear','fear'=>'Fear','neutral'=>'Neutral','greed'=>'Greed','extreme greed'=>'Extreme greed'];
            $classification=$classes[strtolower((string)($d['value_classification']??''))]??null;
        }
        return $result + ['value'=>$value, 'unit'=>'index', 'classification'=>$classification];
    }
    private function failed(string $key): void {
        $failures=min(6,(int)Cache::get('market-overview.failures.'.$key,0)+1);
        Cache::put('market-overview.failures.'.$key,$failures,86400);
        Cache::put('market-overview.next.'.$key,true,min(3600,60*(2**$failures)));
    }
    private function number($value): ?float { return !is_bool($value) && is_numeric($value) && is_finite((float)$value) ? (float)$value : null; }
}
