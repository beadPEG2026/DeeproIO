<?php

namespace App\Console\Commands\Market;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SyncKlineRuntimeCacheCommand extends Command
{
    protected $signature = 'kline:sync-runtime-cache {--twice : Sync once, wait 30 seconds, then sync again} {--interval=5 : Seconds between sync loops} {--cycles=1 : Number of sync loops to run}';

    protected $description = 'Sync runtime kline market cache into the markets table';

    public function handle(): int
    {
        $interval = max(1, (int) $this->option('interval'));
        $cycles = max(1, (int) $this->option('cycles'));

        if ($this->option('twice')) {
            $interval = 30;
            $cycles = 2;
        }

        for ($i = 0; $i < $cycles; $i++) {
            $this->syncOnce();

            if ($i < ($cycles - 1)) {
                sleep($interval);
            }
        }

        return 0;
    }

    protected function syncOnce(): void
    {
        $ids = Cache::get($this->getKlineRuntimeConfigIdsCacheKey(), []);

        if (!is_array($ids) || empty($ids)) {
            return;
        }

        foreach (array_values(array_unique(array_map('intval', $ids))) as $marketId) {
            if ($marketId <= 0) {
                continue;
            }

            $config = $this->getKlineRuntimeConfig($marketId);

            if (empty($config) || empty($config['persist_pending'])) {
                continue;
            }

            $persistAfter = (int)($config['persist_after'] ?? 0);

            if ($persistAfter > 0 && $persistAfter > time()) {
                continue;
            }

            $update = [];

            if (array_key_exists('bot_price_floor', $config)) {
                $update['bot_price_floor'] = $config['bot_price_floor'];
            }

            if (array_key_exists('bot_price_ceiling', $config)) {
                $update['bot_price_ceiling'] = $config['bot_price_ceiling'];
            }

            if (array_key_exists('custom_liquidity_t', $config)) {
                $update['custom_liquidity_t'] = filter_var($config['custom_liquidity_t'], FILTER_VALIDATE_BOOLEAN);
            }

            if (array_key_exists('last', $config) && is_numeric($config['last']) && (float) $config['last'] > 0) {
                $update['last'] = $config['last'];
            }

            if (array_key_exists('bot_current_price', $config) && is_numeric($config['bot_current_price']) && (float) $config['bot_current_price'] > 0) {
                $update['bot_current_price'] = (string)$config['bot_current_price'];
            }

            if (array_key_exists('bot_momentum', $config)) {
                $update['bot_momentum'] = $config['bot_momentum'];
            }

            if (empty($update)) {
                continue;
            }

            $update['updated_at'] = now();

            try {
                DB::table('markets')
                    ->where('id', $marketId)
                    ->update($update);

                $config['persist_pending'] = false;
                $config['persisted_at'] = now()->toDateTimeString();
                unset($config['persist_after']);
                Cache::forever($this->getKlineRuntimeConfigCacheKey($marketId), $config);
            } catch (\Throwable $e) {
                //
            }
        }
    }

    protected function getKlineRuntimeConfigCacheKey(int $marketId): string
    {
        return 'market_kline_runtime_config_' . $marketId;
    }

    protected function getKlineRuntimeConfigIdsCacheKey(): string
    {
        return 'market_kline_runtime_config_ids';
    }

    protected function getKlineRuntimeConfig(int $marketId): array
    {
        if ($marketId <= 0) {
            return [];
        }

        $config = Cache::get($this->getKlineRuntimeConfigCacheKey($marketId), []);

        return is_array($config) ? $config : [];
    }
}
