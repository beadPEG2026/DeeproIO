<?php

namespace App\Console\Commands\SystemMonitor;

use App\Models\Market\Market;
use App\Services\Market\MarketService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class PMMarketWatcher extends Command
{
    protected $signature = 'pm:market-watcher';
    protected $description = 'Watch and restart PM2 market liquidity runners (LIQ-*)';

    protected string $liqPrefix = 'LIQ-';

    public function handle()
    {
        if (!config('pm.markets_enabled')) {
            $this->info('PM Market Watcher is disabled.');
            return 0;
        }

        $homePath = config('pm.home_path');
        $phpPath  = config('pm.php_path', '/usr/bin/php');

        $processes = collect(supervisor($homePath)->list())
            ->keyBy(fn ($p) => $p->name);

        $markets = Market::where('liq', true)->get();

        foreach ($markets as $market) {

            $processName = $this->liqPrefix . market_sanitize($market->name);

            $isRunning = $processes->has($processName)
                && $processes[$processName]->pm2Env->status === 'online';

            if ($isRunning) {
                continue;
            }

            if (supervisor($homePath)->findBy('name', $processName)) {
                supervisor($homePath)->delete($processName);
            }

            $stock = \App\Services\Market\StockAssets::supports($market->name);
            $command = $stock ? 'market:stock-liquidity ' . $market->name : ($market->custom_liquidity
                ? 'market:custom-token-liquidity ' . $market->name
                : 'market:run-liquidity ' . $market->name);

            putenv('PATH=' . config('pm.usr_path', '/usr/local/bin:/usr/bin:/bin'));

            supervisor($homePath)->start(base_path('artisan'), [
                'name' => $processName,
                'interpreter' => $phpPath,
                ' ' . $command,
            ]);

            if (!$stock && !$market->custom_liquidity) {
                $this->syncBinancePrice($market);
            }

            (new MarketService())->updateMarketsInfoCache();

            $this->warn("Restarted liquidity runner: {$processName}");
        }

        return 0;
    }

    protected function syncBinancePrice(Market $market)
    {
        $response = Http::get('https://api.binance.com/api/v3/ticker/24hr', [
            'symbol' => market_sanitize($market->name),
        ]);

        if ($response->successful()) {
            $ticker = $response->json();
            $market->update([
                'last' => $ticker['prevClosePrice'],
                'liq' => true,
            ]);
        }
    }
}
