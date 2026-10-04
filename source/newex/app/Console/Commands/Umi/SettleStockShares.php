<?php

namespace App\Console\Commands\Umi;

use App\Services\Umi\V2\StockShares;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use App\Services\Umi\V2\FundedRuntime;

final class SettleStockShares extends Command
{
    protected $signature = 'umi:stock-shares-settle';
    protected $description = 'Confirm matured UMI stock points and unlock station shares';

    public function handle(StockShares $shares): int
    {
        if (!config('umi-v2.funded_enabled') || !FundedRuntime::schemaReady()) {
            return self::SUCCESS;
        }
        $lock = Cache::lock('umi-stock-maturity', 120);
        if (!$lock->get()) { return self::SUCCESS; }
        try {
            $result = $shares->settleDue();
            Cache::put('deepro.health.umi-stock-maturity', time(), 900);
        } finally { $lock->release(); }
        $this->line('确权 ' . $result['confirmed'] . ' 笔，解锁 ' . $result['unlocked'] . ' 笔。');
        return self::SUCCESS;
    }
}
