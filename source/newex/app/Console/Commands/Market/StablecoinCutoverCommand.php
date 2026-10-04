<?php
namespace App\Console\Commands\Market;

use App\Services\Market\StablecoinMarketCutover;
use Illuminate\Console\Command;

final class StablecoinCutoverCommand extends Command
{
    protected $signature = 'market:stablecoin-cutover {--prepare : Create the closed replacement market} {--apply= : Apply only the exact reviewed snapshot SHA256}';
    protected $description = 'Review or cut over USDC/USDT to USDT/USDC without rewriting history';

    public function handle(StablecoinMarketCutover $service): int
    {
        if ($this->option('prepare') && $this->option('apply')) throw new \InvalidArgumentException('Choose prepare or apply');
        if ($this->option('prepare')) $service->prepare();
        $result = $this->option('apply') ? $service->apply($this->option('apply')) : $service->snapshot();
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return self::SUCCESS;
    }
}
