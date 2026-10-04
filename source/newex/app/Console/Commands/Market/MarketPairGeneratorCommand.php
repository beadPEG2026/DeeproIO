<?php

namespace App\Console\Commands\Market;

use App\Services\Liquidity\Binance\BinanceApi;
use Illuminate\Console\Command;

class MarketPairGeneratorCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'market:pair-generate';

    protected $transactions = [];

    protected $currencies = [];

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

    }
}
