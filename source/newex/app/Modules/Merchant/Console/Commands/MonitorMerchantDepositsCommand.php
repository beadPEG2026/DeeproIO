<?php

namespace App\Modules\Merchant\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class MonitorMerchantDepositsCommand extends Command
{
    protected $signature = 'merchant:monitor-deposits {--network= : Specific network to monitor (erc, trc, bep, matic, sol)}';
    protected $description = 'Monitor blockchain deposits for merchant invoices across all networks';

    public function handle(): int
    {
        $network = $this->option('network');

        if ($network) {
            return $this->runSpecificWatcher($network);
        }

        // Run all watchers
        $watchers = [
            'merchant:monitor-erc-deposits',
            'merchant:monitor-trc-deposits',
            'merchant:monitor-bep-deposits',
            'merchant:monitor-matic-deposits',
            'merchant:monitor-sol-deposits',
        ];

        foreach ($watchers as $watcher) {
            try {
                Artisan::call($watcher);
            } catch (\Exception $e) {
                Log::error("Merchant watcher {$watcher} failed", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return 0;
    }

    protected function runSpecificWatcher(string $network): int
    {
        $command = match (strtolower($network)) {
            'erc', 'erc20', 'eth' => 'merchant:monitor-erc-deposits',
            'trc', 'trc20', 'trx' => 'merchant:monitor-trc-deposits',
            'bep', 'bep20', 'bnb', 'bsc' => 'merchant:monitor-bep-deposits',
            'matic', 'matic20', 'polygon' => 'merchant:monitor-matic-deposits',
            'sol', 'solana', 'spl' => 'merchant:monitor-sol-deposits',
            default => null,
        };

        if (!$command) {
            $this->error("Unknown network: {$network}");
            return 1;
        }

        return Artisan::call($command);
    }
}
