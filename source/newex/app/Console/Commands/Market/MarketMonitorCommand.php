<?php

namespace App\Console\Commands\Market;

use App\Repositories\Market\MarketRepository;
use Illuminate\Console\Command;

class MarketMonitorCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'market-monitor:stats';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $marketRepository = new MarketRepository();

        $markets = $marketRepository->all(false, true);

        $markets->each(function ($market) {

            $low = market_get_stats($market->id, 'low');
            $high = market_get_stats($market->id, 'high');

            if(!$market->liq) {
                $low = 0;
                $high = 0;
                market_set_stats_force($market->id, 'low', 0);
                market_set_stats_force($market->id, 'high', 0);
                market_set_stats_force($market->id, 'volume', 0);

                $market->last = market_get_stats($market->id, 'last');
            }

            $market->bid = market_get_stats($market->id, 'bid');
            $market->ask = market_get_stats($market->id, 'ask');
            //$market->last = market_get_stats($market->id, 'last');
            $market->high = $high;
            $market->low = $low;
            $market->capitalization = market_get_stats($market->id, 'capitalization');
            $market->update();
        });

        supervisor()->flush();
    }
}
