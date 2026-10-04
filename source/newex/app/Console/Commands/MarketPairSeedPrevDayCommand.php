<?php

namespace App\Console\Commands;

use App\Models\Currency\Currency;
use App\Models\FileUpload\FileUpload;
use App\Models\Market\Market;
use App\Models\User\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class MarketPairSeedPrevDayCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'market:seed-prev';

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
        $markets = Market::get();

        foreach ($markets as $marketModel) {

            $response = Http::get('https://api.binance.com/api/v3/ticker/24hr', [
                'symbol' => market_sanitize($marketModel->name)
            ]);

            if ($response->successful()) {

                $ticker = $response->json();

                if ($marketModel) {
                    $ratio = 0;
                    $marketModel->last = $ticker['prevClosePrice'] + ($ticker['prevClosePrice'] * $ratio);
                    $marketModel->update();
                }
            }

        }
    }
}
