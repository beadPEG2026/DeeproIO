<?php

namespace App\Services\Chart;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ExternalCandleService
{
    /**
     * Supported exchanges
     */
    private float $deadline = 0;
    private int $pages = 0;

    const EXCHANGE_BINANCE = 'binance';
    const EXCHANGE_MEXC = 'mexc';
    const EXCHANGE_BYBIT = 'bybit';

    /**
     * Resolution to interval mapping for different exchanges
     */
    protected array $resolutionMap = [
        '1S' => ['binance' => '1s', 'mexc' => '1m', 'bybit' => '1'],
        '1' => ['binance' => '1m', 'mexc' => '1m', 'bybit' => '1'],
        '3' => ['binance' => '3m', 'mexc' => '3m', 'bybit' => '3'],
        '5' => ['binance' => '5m', 'mexc' => '5m', 'bybit' => '5'],
        '15' => ['binance' => '15m', 'mexc' => '15m', 'bybit' => '15'],
        '30' => ['binance' => '30m', 'mexc' => '30m', 'bybit' => '30'],
        '60' => ['binance' => '1h', 'mexc' => '60m', 'bybit' => '60'],
        '120' => ['binance' => '2h', 'mexc' => '120m', 'bybit' => '120'],
        '240' => ['binance' => '4h', 'mexc' => '240m', 'bybit' => '240'],
        '360' => ['binance' => '6h', 'mexc' => '360m', 'bybit' => '360'],
        '480' => ['binance' => '8h', 'mexc' => '480m', 'bybit' => '480'],
        '720' => ['binance' => '12h', 'mexc' => '720m', 'bybit' => '720'],
        'D' => ['binance' => '1d', 'mexc' => '1d', 'bybit' => 'D'],
        '1D' => ['binance' => '1d', 'mexc' => '1d', 'bybit' => 'D'],
        '3D' => ['binance' => '3d', 'mexc' => '3d', 'bybit' => '3D'],
        'W' => ['binance' => '1w', 'mexc' => '1w', 'bybit' => 'W'],
        '1W' => ['binance' => '1w', 'mexc' => '1w', 'bybit' => 'W'],
        'M' => ['binance' => '1M', 'mexc' => '1M', 'bybit' => 'M'],
        '1M' => ['binance' => '1M', 'mexc' => '1M', 'bybit' => 'M'],
    ];

    /**
     * Cache TTL in seconds based on resolution
     * Smaller timeframes = shorter cache, larger timeframes = longer cache
     */
    protected array $cacheTtl = [
        '1S' => 1,
        '1' => 5,
        '3' => 10,
        '5' => 15,
        '15' => 30,
        '30' => 60,
        '60' => 120,
        '120' => 300,
        '240' => 600,
        '360' => 900,
        '480' => 1200,
        '720' => 1800,
        'D' => 3600,
        '1D' => 3600,
        '3D' => 7200,
        'W' => 14400,
        '1W' => 14400,
        'M' => 86400,
        '1M' => 86400,
    ];

    /**
     * Exchange API endpoints
     */
    protected array $endpoints = [
        'binance' => 'https://api.binance.com/api/v3/klines',
        'mexc' => 'https://api.mexc.com/api/v3/klines',
        'bybit' => 'https://api.bybit.com/v5/market/kline',
    ];

    /**
     * Get candles from external exchange with caching
     */
    public function getCandles(
        string $symbol,
        int $from,
        int $to,
        string $resolution,
        string $exchange = self::EXCHANGE_BINANCE,
        bool $bypassCache = false
    ): array {
        if (!HistoryWindow::valid($from,$to,$resolution)) return ['s'=>'error','errmsg'=>'Chart range exceeds 5000 bars or resolution is unsupported'];
        if ($bypassCache) {
            return $this->fetchCandles($symbol, $from, $to, $resolution, $exchange);
        }

        // Check if this is a real-time update request (requesting current candle)
        $isRealtimeRequest = $this->isRealtimeRequest($from, $to, $resolution);

        if ($isRealtimeRequest) {
            // For real-time requests, use very short cache (1-2 seconds) or skip cache
            return $this->fetchCandlesWithMinimalCache($symbol, $from, $to, $resolution, $exchange);
        }

        // For historical requests, use normal caching
        $cacheKey = $this->getCacheKey($symbol, $from, $to, $resolution, $exchange);
        $ttl = $this->cacheTtl[$resolution] ?? 60;

        return $this->cachedFetch($cacheKey,$ttl,fn()=>$this->fetchCandles($symbol,$from,$to,$resolution,$exchange));
    }

    private function cachedFetch(string $key,int $ttl,callable $fetch): array
    {
        if (($cached=Cache::get($key)) !== null) return $cached;
        $lock=Cache::lock($key.':fetch',15);
        try {
            return $lock->block(1,function()use($key,$ttl,$fetch){
                if (($cached=Cache::get($key)) !== null) return $cached;
                $data=$fetch(); Cache::put($key,$data,($data['s']??'error')==='error'?2:$ttl); return $data;
            });
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            return Cache::get($key) ?? ['s'=>'error','errmsg'=>'Chart request is in progress; retry shortly'];
        }
    }
    private function boundedRequest(): \Illuminate\Http\Client\PendingRequest
    {
        $remaining=$this->deadline-microtime(true);
        if (++$this->pages>26 || $remaining<=0.05) throw new \RuntimeException('Candle request budget exceeded');
        return Http::connectTimeout(min(2,$remaining))->timeout(min(4,$remaining));
    }

    /**
     * Check if this is a real-time update request
     * Real-time requests typically ask for the last 2-10 bars including "now"
     */
    protected function isRealtimeRequest(int $from, int $to, string $resolution): bool
    {
        $now = time();
        $resolutionSeconds = $this->getResolutionSeconds($resolution);

        // If 'to' is within 2 resolution periods of now, it's likely a real-time request
        $isNearNow = ($to >= $now - ($resolutionSeconds * 2));

        // If requesting less than 15 bars, it's likely a real-time update
        $requestedBars = ($to - $from) / $resolutionSeconds;
        $isSmallRequest = $requestedBars <= 15;

        return $isNearNow && $isSmallRequest;
    }

    /**
     * Fetch candles with minimal caching for real-time updates
     */
    protected function fetchCandlesWithMinimalCache(
        string $symbol,
        int $from,
        int $to,
        string $resolution,
        string $exchange
    ): array {
        // Use a very short cache key based on current second (1 second cache)
        $cacheKey = "chart:rt:v3:{$exchange}:{$symbol}:{$resolution}:{$from}:{$to}:" . floor(time() / 2);

        return $this->cachedFetch($cacheKey,2,fn()=>$this->fetchCandles($symbol,$from,$to,$resolution,$exchange));
    }

    /**
     * Fetch candles from exchange API
     */
    protected function fetchCandles(
        string $symbol,
        int $from,
        int $to,
        string $resolution,
        string $exchange
    ): array {
        $this->deadline=microtime(true)+12; $this->pages=0;
        $interval = $this->getInterval($resolution, $exchange);

        if (!$interval) {
            return ['s' => 'error', 'errmsg' => 'Invalid resolution'];
        }

        try {
            $candles = match ($exchange) {
                self::EXCHANGE_BINANCE => $this->fetchFromBinance($symbol, $from, $to, $interval),
                self::EXCHANGE_MEXC => $this->fetchFromMexc($symbol, $from, $to, $interval),
                self::EXCHANGE_BYBIT => $this->fetchFromBybit($symbol, $from, $to, $interval, $resolution),
                default => throw new \Exception("Unsupported exchange: {$exchange}"),
            };

            if (empty($candles)) {
                return ['s' => 'no_data'];
            }

            $candles=array_values(array_filter($candles,fn($c)=>(int)$c[0]>=$from*1000 && (int)$c[0]<=$to*1000));
            if(count($candles)>HistoryWindow::MAX_BARS+2) throw new \RuntimeException('Candle result exceeds budget');
            usort($candles,fn($a,$b)=>(int)$a[0]<=>(int)$b[0]);
            return $this->formatCandlesForTradingView($candles);
        } catch (\Exception $e) {
            Log::error("ExternalCandleService error: {$e->getMessage()}", [
                'symbol' => $symbol,
                'exchange' => $exchange,
                'resolution' => $resolution,
            ]);

            return ['s' => 'error', 'errmsg' => $e->getMessage()];
        }
    }

    /**
     * Fetch candles from Binance
     */
    protected function fetchFromBinance(string $symbol, int $from, int $to, string $interval): array
    {
        $allCandles = [];
        $startTime = $from * 1000;
        $endTime = $to * 1000;
        $limit = 1000;

        while ($startTime < $endTime) {
            $response = $this->boundedRequest()
                ->get(rtrim(config('liquidity.market_data_base'), '/') . '/api/v3/klines', [
                    'symbol' => $this->normalizeSymbol($symbol, 'binance'),
                    'interval' => $interval,
                    'startTime' => $startTime,
                    'endTime' => $endTime,
                    'limit' => $limit,
                ]);

            if (!$response->successful()) {
                throw new \Exception("Binance API error: " . $response->body());
            }

            $candles = $response->json();

            if (empty($candles)) {
                break;
            }

            $allCandles = array_merge($allCandles, $candles);

            // Move start time to after the last candle
            $lastCandle = end($candles);
            if ((int)$lastCandle[0] < $startTime) throw new \RuntimeException('Candle page made no progress');
            $startTime = (int)$lastCandle[0] + 1;

            // Binance rate limit protection
            if (count($candles) < $limit) {
                break;
            }

            usleep(100000); // 100ms delay between requests
        }

        return $allCandles;
    }

    /**
     * Fetch candles from MEXC
     */
    protected function fetchFromMexc(string $symbol, int $from, int $to, string $interval): array
    {
        $allCandles = [];
        $startTime = $from * 1000;
        $endTime = $to * 1000;
        $limit = 1000;

        while ($startTime < $endTime) {
            $response = $this->boundedRequest()
                ->get($this->endpoints['mexc'], [
                    'symbol' => $this->normalizeSymbol($symbol, 'mexc'),
                    'interval' => $interval,
                    'startTime' => $startTime,
                    'endTime' => $endTime,
                    'limit' => $limit,
                ]);

            if (!$response->successful()) {
                throw new \Exception("MEXC API error: " . $response->body());
            }

            $candles = $response->json();

            if (empty($candles)) {
                break;
            }

            $allCandles = array_merge($allCandles, $candles);

            $lastCandle = end($candles);
            if ((int)$lastCandle[0] < $startTime) throw new \RuntimeException('Candle page made no progress');
            $startTime = (int)$lastCandle[0] + 1;

            if (count($candles) < $limit) {
                break;
            }

            usleep(100000);
        }

        return $allCandles;
    }

    /**
     * Fetch candles from Bybit
     */
    protected function fetchFromBybit(string $symbol, int $from, int $to, string $interval, string $resolution): array
    {
        $allCandles = [];
        $startTime = $from * 1000;
        $endTime = $to * 1000;
        $limit = 200;

        // Bybit uses category-based API
        $category = 'spot';

        while ($startTime < $endTime) {
            $response = $this->boundedRequest()
                ->get($this->endpoints['bybit'], [
                    'category' => $category,
                    'symbol' => $this->normalizeSymbol($symbol, 'bybit'),
                    'interval' => $interval,
                    'start' => $startTime,
                    'end' => $endTime,
                    'limit' => $limit,
                ]);

            if (!$response->successful()) {
                throw new \Exception("Bybit API error: " . $response->body());
            }

            $data = $response->json();

            if (!isset($data['result']['list']) || empty($data['result']['list'])) {
                break;
            }

            // Bybit returns data in descending order, need to reverse
            $candles = array_reverse($data['result']['list']);

            // Convert Bybit format to standard format
            foreach ($candles as $candle) {
                $allCandles[] = [
                    $candle[0], // Open time
                    $candle[1], // Open
                    $candle[2], // High
                    $candle[3], // Low
                    $candle[4], // Close
                    $candle[5], // Volume
                ];
            }

            $firstCandle = reset($candles);
            if ((int)$firstCandle[0] > $endTime) throw new \RuntimeException('Candle page made no progress');
            $endTime = (int)$firstCandle[0] - 1;

            if (count($candles) < $limit) {
                break;
            }

            usleep(100000);
        }

        return $allCandles;
    }

    /**
     * Format candles for TradingView UDF format
     */
    protected function formatCandlesForTradingView(array $candles): array
    {
        if (empty($candles)) {
            return ['s' => 'no_data'];
        }

        $result = [
            's' => 'ok',
            't' => [],
            'o' => [],
            'h' => [],
            'l' => [],
            'c' => [],
            'v' => [],
        ];

        foreach ($candles as $candle) {
            // Standard format: [openTime, open, high, low, close, volume, ...]
            $result['t'][] = (int)floor($candle[0] / 1000); // Convert to seconds
            $result['o'][] = (float)$candle[1];
            $result['h'][] = (float)$candle[2];
            $result['l'][] = (float)$candle[3];
            $result['c'][] = (float)$candle[4];
            $result['v'][] = (float)$candle[5];
            $result['qv'][] = isset($candle[7]) ? (float)$candle[7] : null;
        }

        return $result;
    }

    /**
     * Get exchange-specific interval from resolution
     */
    protected function getInterval(string $resolution, string $exchange): ?string
    {
        return $this->resolutionMap[$resolution][$exchange] ?? null;
    }

    /**
     * Normalize symbol for specific exchange
     * Converts internal format (BTC-USDT) to exchange format (BTCUSDT)
     */
    protected function normalizeSymbol(string $symbol, string $exchange): string
    {
        // Remove common separators and uppercase
        $normalized = str_replace(['-', '/', '_'], '', strtoupper($symbol));

        return match ($exchange) {
            'binance', 'mexc' => $normalized,
            'bybit' => $normalized,
            default => $normalized,
        };
    }

    /**
     * Generate cache key for candle data
     */
    protected function getCacheKey(
        string $symbol,
        int $from,
        int $to,
        string $resolution,
        string $exchange
    ): string {
        // Round timestamps to resolution boundaries for better cache hits
        $roundedFrom = $this->roundTimestamp($from, $resolution);
        $roundedTo = $this->roundTimestamp($to, $resolution);

        return "chart:candles:v3:{$exchange}:{$symbol}:{$resolution}:{$from}:{$to}";
    }

    /**
     * Round timestamp to resolution boundary for better cache efficiency
     */
    protected function roundTimestamp(int $timestamp, string $resolution): int
    {
        $seconds = $this->getResolutionSeconds($resolution);
        return (int)floor($timestamp / $seconds) * $seconds;
    }

    /**
     * Get resolution in seconds
     */
    protected function getResolutionSeconds(string $resolution): int
    {
        return match ($resolution) {
            '1S' => 1,
            '1' => 60,
            '3' => 180,
            '5' => 300,
            '15' => 900,
            '30' => 1800,
            '60' => 3600,
            '120' => 7200,
            '240' => 14400,
            '360' => 21600,
            '480' => 28800,
            '720' => 43200,
            'D', '1D' => 86400,
            '3D' => 259200,
            'W', '1W' => 604800,
            'M', '1M' => 2592000,
            default => 300,
        };
    }

    /**
     * Pre-warm cache for a symbol (useful for scheduled jobs)
     */
    public function warmCache(
        string $symbol,
        string $exchange = self::EXCHANGE_BINANCE,
        array $resolutions = ['1', '5', '15', '60', '240', '1D']
    ): void {
        $now = time();

        foreach ($resolutions as $resolution) {
            $seconds = $this->getResolutionSeconds($resolution);
            $from = $now - ($seconds * 500); // Get last 500 candles

            try {
                $this->getCandles($symbol, $from, $now, $resolution, $exchange);
                Log::info("Warmed cache for {$symbol} {$resolution} on {$exchange}");
            } catch (\Exception $e) {
                Log::error("Failed to warm cache: {$e->getMessage()}");
            }

            usleep(200000); // 200ms delay between resolutions
        }
    }

    /**
     * Get symbol info for TradingView
     */
    public function getSymbolInfo(string $symbol, string $exchange = self::EXCHANGE_BINANCE): array
    {
        $cacheKey = "chart:symbol_info:{$exchange}:{$symbol}";

        return Cache::remember($cacheKey, 3600, function () use ($symbol, $exchange) {
            return [
                'name' => $symbol,
                'ticker' => $symbol,
                'description' => $symbol,
                'type' => 'crypto',
                'session' => '24x7',
                'timezone' => 'Etc/UTC',
                'exchange' => strtoupper($exchange),
                'minmov' => 1,
                'pricescale' => 100000000,
                'has_intraday' => true,
                'has_seconds' => true,
                'has_daily' => true,
                'has_weekly_and_monthly' => true,
                'supported_resolutions' => array_keys($this->resolutionMap),
                'data_status' => 'streaming',
            ];
        });
    }

    /**
     * Search symbols on exchange
     */
    public function searchSymbols(
        string $query,
        string $exchange = self::EXCHANGE_BINANCE,
        int $limit = 30
    ): array {
        $cacheKey = "chart:exchange_symbols:{$exchange}";

        $allSymbols = Cache::remember($cacheKey, 3600, function () use ($exchange) {
            return $this->fetchExchangeSymbols($exchange);
        });

        $query = strtoupper($query);
        $results = [];

        foreach ($allSymbols as $symbol) {
            if (str_contains($symbol['symbol'], $query)) {
                $results[] = [
                    'symbol' => $symbol['symbol'],
                    'full_name' => $symbol['symbol'],
                    'description' => $symbol['description'] ?? $symbol['symbol'],
                    'exchange' => strtoupper($exchange),
                    'type' => 'crypto',
                ];

                if (count($results) >= $limit) {
                    break;
                }
            }
        }

        return $results;
    }

    /**
     * Fetch all symbols from exchange
     */
    protected function fetchExchangeSymbols(string $exchange): array
    {
        try {
            return match ($exchange) {
                self::EXCHANGE_BINANCE => $this->fetchBinanceSymbols(),
                self::EXCHANGE_MEXC => $this->fetchMexcSymbols(),
                self::EXCHANGE_BYBIT => $this->fetchBybitSymbols(),
                default => [],
            };
        } catch (\Exception $e) {
            Log::error("Failed to fetch exchange symbols: {$e->getMessage()}");
            return [];
        }
    }

    protected function fetchBinanceSymbols(): array
    {
        $response = Http::timeout(10)->get(rtrim(config('liquidity.market_data_base'), '/') . '/api/v3/exchangeInfo');

        if (!$response->successful()) {
            return [];
        }

        $data = $response->json();
        $symbols = [];

        foreach ($data['symbols'] ?? [] as $symbol) {
            if ($symbol['status'] === 'TRADING') {
                $symbols[] = [
                    'symbol' => $symbol['symbol'],
                    'description' => "{$symbol['baseAsset']}/{$symbol['quoteAsset']}",
                ];
            }
        }

        return $symbols;
    }

    protected function fetchMexcSymbols(): array
    {
        $response = Http::timeout(10)->get('https://api.mexc.com/api/v3/exchangeInfo');

        if (!$response->successful()) {
            return [];
        }

        $data = $response->json();
        $symbols = [];

        foreach ($data['symbols'] ?? [] as $symbol) {
            if ($symbol['status'] === 'ENABLED') {
                $symbols[] = [
                    'symbol' => $symbol['symbol'],
                    'description' => "{$symbol['baseAsset']}/{$symbol['quoteAsset']}",
                ];
            }
        }

        return $symbols;
    }

    protected function fetchBybitSymbols(): array
    {
        $response = Http::timeout(10)->get('https://api.bybit.com/v5/market/instruments-info', [
            'category' => 'spot',
        ]);

        if (!$response->successful()) {
            return [];
        }

        $data = $response->json();
        $symbols = [];

        foreach ($data['result']['list'] ?? [] as $symbol) {
            if ($symbol['status'] === 'Trading') {
                $symbols[] = [
                    'symbol' => $symbol['symbol'],
                    'description' => "{$symbol['baseCoin']}/{$symbol['quoteCoin']}",
                ];
            }
        }

        return $symbols;
    }
}
