<?php

namespace App\Console\Commands\Market;

use App\Models\Market\Market;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Setting;

class MarketLastPriceUpdateCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'market:last-price-update';

    protected $market = null;

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
        $markets = Market::where('liq', true)->where('status',true)->pluck('name');

        foreach ($markets as $market) {

            $candidate=Market::whereName($market)->firstOrFail();
            $inverse=\App\Services\Market\StablecoinOrientation::inverse($candidate);
            $response = Http::get('https://api.binance.com/api/v3/ticker/24hr', [
                'symbol' => $inverse ? 'USDCUSDT' : market_sanitize($market)
            ]);

            if ($response->successful()) {

                $ticker = $response->json();
                if ($inverse) $ticker['prevClosePrice']=\App\Services\Market\StablecoinOrientation::reciprocal($ticker['prevClosePrice']);

                $marketModel = Market::whereName($market)->first();

                if($marketModel) {
                    $ratio = 0;//$marketModel->discount * 0.01;
                    $priceK = $ticker['prevClosePrice'] + ($ticker['prevClosePrice'] * $ratio);
                    $this->info($market . ' ' . $priceK);
                    $marketModel->last = $ticker['prevClosePrice'] + ($ticker['prevClosePrice'] * $ratio);
                    $marketModel->update();
                }
            }
            sleep(1);
        }
    }
}
