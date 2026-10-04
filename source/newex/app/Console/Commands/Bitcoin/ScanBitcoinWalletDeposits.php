<?php

namespace App\Console\Commands\Bitcoin;

use App\Services\Deposit\BitcoinWalletScanner;
use Illuminate\Console\Command;

final class ScanBitcoinWalletDeposits extends Command
{
    protected $signature = 'btc:scan-wallet-deposits {--dry-run : Check discovery without callbacks or checkpoint changes}';
    protected $description = 'Discover Bitcoin Core wallet receipts and retry pending deposit confirmations';

    public function handle(BitcoinWalletScanner $scanner): int
    {
        try {
            $this->line(json_encode($scanner->run((bool) $this->option('dry-run'))));
            return self::SUCCESS;
        } catch (\Throwable $e) {
            // RPC exception text can contain credentials; expose a stable code only.
            $this->error('BTC_WALLET_SCAN_FAILED');
            return self::FAILURE;
        }
    }
}
