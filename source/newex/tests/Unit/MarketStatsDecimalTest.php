<?php

namespace Tests\Unit;

use App\Console\Commands\Market\MarketStatsWatcherCommand;
use PHPUnit\Framework\TestCase;
use Illuminate\Cache\{ArrayStore, Repository};
use Illuminate\Support\Facades\Cache;

final class MarketStatsDecimalTest extends TestCase
{
    private function invoke(MarketStatsWatcherCommand $watcher, string $method, ...$arguments)
    {
        $reflection = new \ReflectionMethod($watcher, $method);
        $reflection->setAccessible(true);
        return $reflection->invoke($watcher, ...$arguments);
    }

    public static function feedPrices(): array
    {
        return [
            'WIN decimal string' => ['0.00004758', '0.00004758', 8],
            'WIN legacy float cache' => [0.00004758, '0.00004758', 8],
            'smaller decimal' => ['0.000000000123', '0.000000000123', 18],
            'smaller legacy float' => [0.000000000123, '0.000000000123', 18],
            'eighteen decimal places' => ['0.000000000000000123', '0.000000000000000123', 18],
            'scientific feed notation' => ['4.758e-5', '0.00004758', 8],
            'USDS' => ['0.99990000', '0.9999', 8],
            'BTC' => ['83241.12000000', '83241.12', 8],
            'source precision beyond float' => ['83241.123456789123456789', '83241.123456789123456789', 18],
        ];
    }

    /** @dataProvider feedPrices */
    public function test_feed_decimal_survives_extraction_adjustment_and_display($input, string $expected, int $precision): void
    {
        $watcher = new MarketStatsWatcherCommand();
        $raw = $this->invoke($watcher, 'extractNumericValue', [['c' => $input]], ['close', 'c']);
        $this->assertSame($expected, $raw);
        $adjusted = $this->invoke($watcher, 'buildStandardAdjustedStats', 1, ['open' => $raw], $raw, $raw, $raw);
        foreach (['last', 'open', 'high', 'low'] as $key) {
            $this->assertIsString($adjusted[$key]);
            $this->assertStringNotContainsString('e', strtolower($adjusted[$key]));
            $this->assertSame($expected, $this->invoke($watcher, 'formatDecimal', $adjusted[$key], $precision));
        }
        $this->assertSame('0', $adjusted['change']);
    }

    public function test_discount_custom_multiplier_and_bs_handle_micro_prices(): void
    {
        $watcher = new MarketStatsWatcherCommand();
        $discounted = $this->invoke($watcher, 'calculateRawAdjustedPrice', '0.00004758', 0.05, 1.0);
        $this->assertSame('0.000049959', $this->invoke($watcher, 'formatDecimal', $discounted, 18));
        // A custom multiplier replaces the additive discount, as in the existing policy.
        $custom = $this->invoke($watcher, 'calculateRawAdjustedPrice', '0.00004758', 0.05, 1.2);
        $this->assertSame('0.000057096', $this->invoke($watcher, 'formatDecimal', $custom, 18));
        $bs = $this->invoke($watcher, 'applyPriceMultiplier', $custom, 0.5);
        $this->assertSame('0.000028548', $this->invoke($watcher, 'formatDecimal', $bs, 18));
        $tinyFactor = $this->invoke($watcher, 'applyPriceMultiplier', '0.00004758', 0.00001);
        $this->assertSame('0.0000000004758', $this->invoke($watcher, 'formatDecimal', $tinyFactor, 18));
    }

    public function test_existing_float_output_and_percent_formatting_remain_decimal(): void
    {
        $watcher = new MarketStatsWatcherCommand();
        $this->assertSame('0.00004758', $this->invoke($watcher, 'formatDecimal', 0.00004758, 8));
        $this->assertSame('10', $this->invoke($watcher, 'calculateChangePercent', 110, 100));
        $this->assertSame('-10', $this->invoke($watcher, 'calculateChangePercent', 90, 100));
        $this->assertSame('9.09', $this->invoke($watcher, 'calculateChangePercent', 60, 55));
        $this->assertNull($this->invoke($watcher, 'calculateChangePercent', 1, 0));
        $this->assertNull($this->invoke($watcher, 'calculateChangePercent', INF, 1));
    }

    public function test_anchor_offset_and_stream_payload_preserve_micro_prices(): void
    {
        $watcher = new class extends MarketStatsWatcherCommand {
            protected function getKlineAdjustmentState(int $marketId): array
            {
                return ['adjusted_at'=>'test-anchor', 'timestamp'=>time()-31, 'price'=>'0.00005758'];
            }
            protected function rememberKlineAdjustmentAnchor(int $marketId, ?array $rawStats, array $state, ?array $standardStats = null): void {}
            public function setupMarket(): void
            {
                $this->initialMarkets[1] = 'WIN-USDT';
                $this->quoteMarkets[1] = 8;
            }
        };
        $originalCache = Cache::getFacadeRoot();
        $cache = new Repository(new ArrayStore());
        Cache::swap($cache);
        try {
            $cache->put('market_kline_adjustment_anchor_1', [
                'adjusted_at'=>'test-anchor', 'price'=>0.00005758, 'standard_close'=>0.00004758,
            ]);
            $stats = $this->invoke($watcher, 'buildAdjustedStats', 1, [
                'close'=>'0.00004758', 'open'=>'0.000045', 'high'=>'0.000048', 'low'=>'0.000044',
            ]);
            $watcher->setupMarket();
            $payload = $this->invoke($watcher, 'buildStatsPayload', 1, $stats);
            $this->assertSame('0.00005758', $payload['last']);
            $this->assertSame('0.000058', $payload['high']);
            $this->assertSame('0.000054', $payload['low']);
            $this->assertSame('4.69', $payload['change']);
            $this->assertSame('0.000055', $this->invoke($watcher, 'formatDecimal', $stats['open'], 8));
        } finally {
            Cache::clearResolvedInstance('cache');
            if ($originalCache !== null) Cache::swap($originalCache);
        }
    }

    public function test_invalid_values_and_excessive_exponents_cannot_reach_bcmath(): void
    {
        $watcher = new MarketStatsWatcherCommand();
        foreach ([NAN, INF, '-INF', 'not a price', '1e1000000'] as $input) {
            $this->assertSame('0', $this->invoke($watcher, 'calculateRawAdjustedPrice', $input, 0.0, 1.0));
        }
        $this->assertSame('0.000001', $this->invoke($watcher, 'extractNumericValue', [['c'=>'invalid']], ['c'], 0.000001));
    }
}
