<?php
namespace App\Console\Commands\Market;

use App\Services\Market\StockNews;
use Illuminate\Console\Command;

final class RefreshStockNews extends Command
{
    protected $signature = 'market:refresh-stock-news';
    protected $description = 'Refresh cached US and Hong Kong stock headlines';

    public function handle(StockNews $news): int
    {
        foreach (['US','HK'] as $region) {
            $snapshot=$news->refresh($region);
            $this->line($region.': '.count($snapshot['items']).' headlines; last successful refresh '.($snapshot['updatedAt']??'none'));
        }
        return self::SUCCESS;
    }
}
