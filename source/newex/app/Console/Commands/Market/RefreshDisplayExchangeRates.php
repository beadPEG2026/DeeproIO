<?php
namespace App\Console\Commands\Market;
use Illuminate\Console\Command;
use App\Services\Market\DisplayExchangeRates;
final class RefreshDisplayExchangeRates extends Command
{
    protected $signature='market:display-exchange-rates';
    protected $description='Refresh official display FX and historical HK valuation rates without changing market prices';
    public function handle(): int
    {
        try {app(DisplayExchangeRates::class)->refresh();$this->info('Display exchange rates refreshed');return self::SUCCESS;}
        catch (\Throwable $e) {$this->error('DISPLAY_FX_REFRESH_FAILED');return self::FAILURE;}
    }
}
