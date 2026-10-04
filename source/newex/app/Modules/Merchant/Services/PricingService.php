<?php

namespace App\Modules\Merchant\Services;

use App\Models\Currency\Currency;
use App\Modules\Merchant\Models\MerchantInvoice;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class PricingService
{
    /**
     * Rate source handlers
     */
    protected array $rateSources = [];

    public function __construct()
    {
        $this->registerRateSources();
    }

    /**
     * Lock rate for an invoice
     */
    public function lockRate(MerchantInvoice $invoice, Currency $currency): array
    {
        // Get current rate
        $rate = $this->getCurrentRate($currency);

        if (!$rate) {
            throw new RuntimeException("Unable to fetch rate for {$currency->symbol}");
        }

        // Calculate crypto amount
        $cryptoAmount = $this->calculateCryptoAmount(
            $invoice->amount_usd,
            $rate['rate_usd'],
            $currency->decimals
        );

        // Apply merchant-specific premium if any
        $cryptoAmount = $this->applyPremium($cryptoAmount, $currency);

        // Calculate validity window
        $rateValidity = config('merchant_acquiring.invoice.rate_validity', 900);
        $expiresAt = now()->addSeconds($rateValidity);

        return [
            'rate_usd' => $rate['rate_usd'],
            'bid_price' => $rate['bid_price'] ?? null,
            'ask_price' => $rate['ask_price'] ?? null,
            'rate_source' => $rate['source'],
            'amount_crypto' => $cryptoAmount,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Get current rate for a currency
     */
    public function getCurrentRate(Currency $currency): ?array
    {
        $cacheKey = "rate:{$currency->symbol}";
        $cacheTtl = config('merchant_acquiring.pricing.rate_cache_ttl', 30);

        return Cache::remember($cacheKey, $cacheTtl, function () use ($currency) {
            return $this->fetchRate($currency);
        });
    }

    /**
     * Fetch rate from source with fallback chain
     */
    protected function fetchRate(Currency $currency): ?array
    {
        // For stablecoins with fixed rate (USDT, USDC, etc.)
        if ($currency->is_stable) {
            return [
                'rate_usd' => '1.00000000',
                'source' => 'fixed',
                'pair' => null,
            ];
        }

        // Try sources in order: internal (platform cache) -> binance -> coingecko
        $sources = ['internal', 'binance', 'coingecko'];

        foreach ($sources as $source) {
            $handler = $this->rateSources[$source] ?? null;

            if (!$handler) {
                continue;
            }

            $startTime = microtime(true);

            try {
                $rate = $handler($currency);
                $latencyMs = (int) ((microtime(true) - $startTime) * 1000);

                if ($rate) {
                    $rate['latency_ms'] = $latencyMs;
                    Log::debug("Rate fetched successfully", [
                        'currency' => $currency->symbol,
                        'source' => $source,
                        'rate' => $rate['rate_usd'],
                    ]);
                    return $rate;
                }
            } catch (\Exception $e) {
                Log::warning("Rate source {$source} failed for {$currency->symbol}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::error("All rate sources failed for {$currency->symbol}");
        return null;
    }

    /**
     * Register rate source handlers
     */
    protected function registerRateSources(): void
    {
        // Binance rate source
        $this->rateSources['binance'] = function (Currency $currency) {
            return $this->fetchBinanceRate($currency);
        };

        // CoinGecko rate source
        $this->rateSources['coingecko'] = function (Currency $currency) {
            return $this->fetchCoinGeckoRate($currency);
        };

        // Internal rate source (from exchange orderbook)
        $this->rateSources['internal'] = function (Currency $currency) {
            return $this->fetchInternalRate($currency);
        };
    }

    /**
     * Fetch rate from Binance
     */
    protected function fetchBinanceRate(Currency $currency): ?array
    {
        try {
            $pair = $this->guessBinancePair($currency);

            $response = Http::timeout(5)
                ->get("https://api.binance.me/api/v3/ticker/bookTicker", [
                    'symbol' => $pair,
                ]);

            if (!$response->successful()) {
                Log::error("Binance API error", [
                    'currency' => $currency->symbol,
                    'status' => $response->status(),
                ]);
                return null;
            }

            $data = $response->json();

            // If pair is against USDT, the rate is direct
            $bidPrice = (float) $data['bidPrice'];
            $askPrice = (float) $data['askPrice'];

            // Use ask price for buyer (they're buying crypto)
            $useAsk = config('merchant_acquiring.pricing.use_ask_for_buy', true);
            $rateUsd = $useAsk ? $askPrice : $bidPrice;

            // Apply spread
            $spreadPercent = config('merchant_acquiring.pricing.spread_percent', 0.5);
            $rateUsd = $rateUsd * (1 + ($spreadPercent / 100));

            $spreadCalc = $bidPrice > 0 ? (($askPrice - $bidPrice) / $bidPrice) * 100 : 0;

            return [
                'rate_usd' => number_format($rateUsd, 12, '.', ''),
                'bid_price' => number_format($bidPrice, 12, '.', ''),
                'ask_price' => number_format($askPrice, 12, '.', ''),
                'spread_percent' => $spreadCalc,
                'source' => 'binance',
                'pair' => $pair,
            ];
        } catch (\Exception $e) {
            Log::error("Binance rate fetch failed", [
                'currency' => $currency->symbol,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Fetch rate from CoinGecko
     */
    protected function fetchCoinGeckoRate(Currency $currency): ?array
    {
        try {
            $coinId = $this->getCoinGeckoId($currency);

            $response = Http::timeout(10)
                ->get("https://api.coingecko.com/api/v3/simple/price", [
                    'ids' => $coinId,
                    'vs_currencies' => 'usd',
                ]);

            if (!$response->successful()) {
                return null;
            }

            $data = $response->json();
            $price = $data[$coinId]['usd'] ?? null;

            if (!$price) {
                return null;
            }

            // Apply spread
            $spreadPercent = config('merchant_acquiring.pricing.spread_percent', 0.5);
            $rateUsd = $price * (1 + ($spreadPercent / 100));

            return [
                'rate_usd' => number_format($rateUsd, 12, '.', ''),
                'source' => 'coingecko',
                'pair' => "{$coinId}/usd",
            ];
        } catch (\Exception $e) {
            Log::error("CoinGecko rate fetch failed", [
                'currency' => $currency->symbol,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Fetch rate from internal exchange/platform cache
     */
    protected function fetchInternalRate(Currency $currency): ?array
    {
        try {
            // Try to get rate from platform's market cache
            $marketPairs = Cache::get('marketPairs');
            $symbol = strtoupper($currency->symbol);

            // Try common quote currencies
            $quoteCurrencies = ['USDT', 'USD', 'BUSD'];

            foreach ($quoteCurrencies as $quote) {
                $pairKey = $symbol . $quote;

                if ($marketPairs && isset($marketPairs[$pairKey])) {
                    $marketId = $marketPairs[$pairKey];
                    $lastPrice = market_get_stats($marketId, 'last');

                    if ($lastPrice && $lastPrice > 0) {
                        // Apply spread
                        $spreadPercent = config('merchant_acquiring.pricing.spread_percent', 0.5);
                        $rateUsd = $lastPrice * (1 + ($spreadPercent / 100));

                        return [
                            'rate_usd' => number_format($rateUsd, 12, '.', ''),
                            'source' => 'internal',
                            'pair' => $pairKey,
                        ];
                    }
                }
            }

            // Also try reverse pairs (e.g., USDT/BTC)
            foreach ($quoteCurrencies as $quote) {
                $pairKey = $quote . $symbol;

                if ($marketPairs && isset($marketPairs[$pairKey])) {
                    $marketId = $marketPairs[$pairKey];
                    $lastPrice = market_get_stats($marketId, 'last');

                    if ($lastPrice && $lastPrice > 0) {
                        // Inverse the rate
                        $rateUsd = 1 / $lastPrice;

                        // Apply spread
                        $spreadPercent = config('merchant_acquiring.pricing.spread_percent', 0.5);
                        $rateUsd = $rateUsd * (1 + ($spreadPercent / 100));

                        return [
                            'rate_usd' => number_format($rateUsd, 12, '.', ''),
                            'source' => 'internal',
                            'pair' => $pairKey . ' (inverse)',
                        ];
                    }
                }
            }

            return null;
        } catch (\Exception $e) {
            Log::warning("Internal rate fetch failed", [
                'currency' => $currency->symbol,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Calculate crypto amount from USD
     */
    public function calculateCryptoAmount(float $amountUsd, string $rateUsd, int $precision = 8): string
    {

        if ((float) $rateUsd <= 0) {
            throw new RuntimeException('Invalid rate');
        }

        // crypto_amount = usd_amount / rate_usd
        $cryptoAmount = bcdiv((string) $amountUsd, $rateUsd, $precision + 4);

        // Round to precision
        return $this->roundCrypto($cryptoAmount, $precision);
    }

    /**
     * Calculate USD amount from crypto
     */
    public function calculateUsdAmount(string $amountCrypto, string $rateUsd): string
    {
        // usd_amount = crypto_amount * rate_usd
        return bcmul($amountCrypto, $rateUsd, 2);
    }

    /**
     * Apply currency-specific premium
     */
    protected function applyPremium(string $amount, Currency $currency): string
    {
        // No premium applied at currency level for now
        // Can be extended to use merchant_fee_percent if needed
        return $amount;
    }

    /**
     * Round crypto amount to precision
     */
    protected function roundCrypto(string $amount, int $precision): string
    {
        // Use bcmath for precision
        $factor = bcpow('10', (string) $precision);
        $rounded = bcdiv(
            bcadd(bcmul($amount, $factor, 0), '0', 0),
            $factor,
            $precision
        );

        return $rounded;
    }

    /**
     * Guess Binance trading pair
     */
    protected function guessBinancePair(Currency $currency): string
    {
        $symbol = strtoupper($currency->symbol);

        // Most common pairs
        $stablecoins = ['USDT', 'USDC', 'BUSD', 'DAI'];

        if (in_array($symbol, $stablecoins)) {
            return 'BUSDUSDT'; // Stablecoin proxy
        }

        return $symbol . 'USDT';
    }

    /**
     * Get CoinGecko coin ID
     */
    protected function getCoinGeckoId(Currency $currency): string
    {
        $symbol = strtoupper($currency->symbol);

        $mapping = [
            'BTC' => 'bitcoin',
            'ETH' => 'ethereum',
            'USDT' => 'tether',
            'USDC' => 'usd-coin',
            'BNB' => 'binancecoin',
            'XRP' => 'ripple',
            'ADA' => 'cardano',
            'DOGE' => 'dogecoin',
            'SOL' => 'solana',
            'TRX' => 'tron',
            'MATIC' => 'matic-network',
            'LTC' => 'litecoin',
        ];

        return $mapping[$symbol] ?? strtolower($symbol);
    }

    /**
     * Get estimated rate (for display before selection)
     */
    public function getEstimatedRate(Currency $currency, float $amountUsd): array
    {
        $rate = $this->getCurrentRate($currency);

        if (!$rate) {
            return [
                'available' => false,
                'error' => 'Rate unavailable',
            ];
        }

        $cryptoAmount = $this->calculateCryptoAmount(
            $amountUsd,
            $rate['rate_usd'],
            $currency->decimals
        );

        return [
            'available' => true,
            'rate_usd' => $rate['rate_usd'],
            'amount_crypto' => $cryptoAmount,
            'amount_crypto_display' => rtrim(rtrim($cryptoAmount, '0'), '.'),
            'rate_source' => $rate['source'],
            'estimated' => true,
            'validity_seconds' => config('merchant_acquiring.invoice.rate_validity', 900),
        ];
    }

    /**
     * Refresh stale rates in background
     */
    public function refreshStaleRates(): int
    {
        $currencies = Currency::where('is_merchant', true)->where('status', true)->get();
        $refreshed = 0;

        foreach ($currencies as $currency) {
            $cacheKey = "rate:{$currency->symbol}";
            $cached = Cache::get($cacheKey);

            if (!$cached) {
                $this->getCurrentRate($currency);
                $refreshed++;
            }
        }

        return $refreshed;
    }

    /**
     * Check if rate is still valid for invoice
     */
    public function isRateValid(MerchantInvoice $invoice): bool
    {
        if (!$invoice->rate_expires_at) {
            return false;
        }

        return $invoice->rate_expires_at->isFuture();
    }

    /**
     * Get rate drift (how much rate has changed since locking)
     */
    public function getRateDrift(MerchantInvoice $invoice): ?array
    {
        if (!$invoice->currency_id || !$invoice->rate_usd) {
            return null;
        }

        $currency = $invoice->currencyModel;
        $currentRate = $this->getCurrentRate($currency);

        if (!$currentRate) {
            return null;
        }

        $lockedRate = (float) $invoice->rate_usd;
        $currentRateUsd = (float) $currentRate['rate_usd'];

        $driftPercent = (($currentRateUsd - $lockedRate) / $lockedRate) * 100;

        return [
            'locked_rate' => $lockedRate,
            'current_rate' => $currentRateUsd,
            'drift_percent' => round($driftPercent, 4),
            'favorable_to_merchant' => $driftPercent > 0,
        ];
    }
}
