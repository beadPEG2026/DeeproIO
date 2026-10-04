<?php

namespace App\Console\Commands\Chart;

use App\Models\Market\Market;
use App\Services\Chart\ExternalCandleService;
use Illuminate\Console\Command;

class WarmChartCacheCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'chart:warm-cache 
                            {--symbol= : Specific symbol to warm (e.g., BTCUSDT)}
                            {--exchange=binance : Exchange to fetch from (binance, mexc, bybit)}
                            {--all : Warm cache for all markets with switch_chart enabled}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Pre-warm chart candle cache for external data sources';

    protected ExternalCandleService $candleService;

    public function __construct(ExternalCandleService $candleService)
    {
        parent::__construct();
        $this->candleService = $candleService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $exchange = $this->option('exchange');
        $symbol = $this->option('symbol');
        $all = $this->option('all');

        if ($symbol) {
            $this->warmSymbol($symbol, $exchange);
            return Command::SUCCESS;
        }

        if ($all) {
            $this->warmAllMarkets($exchange);
            return Command::SUCCESS;
        }

        // Default: warm popular trading pairs
        $this->warmPopularPairs($exchange);

        return Command::SUCCESS;
    }

    /**
     * Warm cache for a specific symbol
     */
    protected function warmSymbol(string $symbol, string $exchange): void
    {
        $this->info("Warming cache for {$symbol} on {$exchange}...");

        $resolutions = ['1', '5', '15', '30', '60', '240', '1D'];
        $bar = $this->output->createProgressBar(count($resolutions));
        $bar->start();

        foreach ($resolutions as $resolution) {
            try {
                $now = time();
                $seconds = $this->getResolutionSeconds($resolution);
                $from = $now - ($seconds * 500);

                $this->candleService->getCandles($symbol, $from, $now, $resolution, $exchange);
                $bar->advance();
            } catch (\Exception $e) {
                $this->error("\nFailed to warm {$resolution}: {$e->getMessage()}");
            }

            usleep(200000); // Rate limit protection
        }

        $bar->finish();
        $this->newLine();
        $this->info("Cache warmed for {$symbol}");
    }

    /**
     * Warm cache for all markets with switch_chart enabled
     */
    protected function warmAllMarkets(string $exchange): void
    {
        $markets = Market::where('switch_chart', true)
            ->where('status', true)
            ->get();

        if ($markets->isEmpty()) {
            $this->warn('No markets with switch_chart enabled found.');
            return;
        }

        $this->info("Warming cache for {$markets->count()} markets...");

        foreach ($markets as $market) {
            // Use chart_symbol if set, otherwise normalize market name
            $symbol = $market->chart_symbol 
                ? $market->chart_symbol 
                : $this->normalizeSymbol($market->name);
            
            // Use market's chart_source if set
            $marketExchange = $market->chart_source ?? $exchange;
            
            $this->warmSymbol($symbol, $marketExchange);
            sleep(1); // Delay between markets
        }

        $this->info('All markets cache warmed successfully!');
    }

    /**
     * Warm cache for popular trading pairs
     */
    protected function warmPopularPairs(string $exchange): void
    {
        $popularPairs = [
            'BTCUSDT',
            'ETHUSDT',
            'BNBUSDT',
            'SOLUSDT',
            'XRPUSDT',
            'DOGEUSDT',
            'ADAUSDT',
            'AVAXUSDT',
            'DOTUSDT',
            'MATICUSDT',
        ];

        $this->info("Warming cache for popular pairs on {$exchange}...");

        foreach ($popularPairs as $symbol) {
            try {
                $this->warmSymbol($symbol, $exchange);
            } catch (\Exception $e) {
                $this->error("Failed to warm {$symbol}: {$e->getMessage()}");
            }
            sleep(1);
        }

        $this->info('Popular pairs cache warmed successfully!');
    }

    /**
     * Normalize symbol format
     */
    protected function normalizeSymbol(string $symbol): string
    {
        return str_replace(['-', '/', '_'], '', strtoupper($symbol));
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
}
