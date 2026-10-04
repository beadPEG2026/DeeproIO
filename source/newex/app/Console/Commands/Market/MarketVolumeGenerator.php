<?php

namespace App\Console\Commands\Market;

use App\Jobs\Market\MarketCapCalculationJob;
use App\Models\Market\Market;
use App\Models\Order\Order;
use App\Models\Transaction\Transaction;
use App\Services\Liquidity\Binance\BinanceApi;
use App\Services\Market\MarketService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MarketVolumeGenerator extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'market:volume-generate {market=0}';

    protected $transactions = [];

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
        $isInteractive = $this->argument('market', "0");

        if($isInteractive == "0") {
            $market = $this->ask('Enter market name, e.g. BTC-USDT', 'BTC-USDT');
        } else {
            $market = $this->argument('market');
        }

        $model = Market::whereName($market)->first();

        if(!$model) {
            $this->info('Invalid market name');
            return;
        };

        if($isInteractive == "0") {
            $days = $this->ask('Enter total number of days to draw the chart history, e.g 90', 90);
            $volume1 = $this->ask('Quantity in base currency, e.g. 0.538', 0.538);
            $volume2 = $this->ask('Quantity in quote currency,  e.g. 20', 20);
        } else {
            $days = 1;
            $volume1 = 0.538;
            $volume2 = 20;
        }

        if(!$days) $days = 30;

        $marketName = market_sanitize($market);

        if($days > 1) {
            Transaction::where('market_id', $model->id)->whereNull('order_id')->delete();
        }

        if($days == 1) {
            $days = 2;

            Transaction::where('market_id', $model->id)->where('is_volume', 1)->where(function($query){
                $query->whereDate('created_at', Carbon::today())->orWhereDate('created_at', Carbon::yesterday());
            })->delete();
        }

        try {

                $api = new BinanceApi();

                $response = $api->klines($marketName, $days, '1d');

                $bars = new Collection($response);

                $orderType = ['buy', 'sell'];

                $carbonOriginal = Carbon::now();

                $carbonYesterday = Carbon::yesterday();

                $carbonDate = Carbon::now();

                $carbonDate->subDays($days);

                $bars->each(function ($bar) use ($model, $orderType, $carbonDate, $volume1, $volume2, $carbonOriginal, $carbonYesterday) {

                    $carbonDate->addDay();

                    // 1 = Open, 2 = High, 3 = Low, 4 = Close
                    for($i=1; $i<=4; $i++) {

                        $randPercentage = rand(1, 50);

                        $dailyVolume1 = $volume1 * ((100-$randPercentage) / 100);
                        $dailyVolume2 = $volume2 * ((100-$randPercentage) / 100);

                        $randMinutes = [rand(10, 22), rand(23, 29), rand(30, 40), rand(41, 59)];
                        $randHours = ['02', '03', '04', '05'];
                        $randSeconds = rand(1, 59);

                        $hour = $randHours[$i-1];
                        $minutes = $randMinutes[$i-1];

                        $this->transactions[] = [
                            'market_id' => $model->id,
                            'process_id' => Str::uuid(),
                            'order_id' => null,
                            'user_id' => null,
                            'is_maker' => true,
                            'order_type' => 'limit',
                            'order_side' => $orderType[rand(0, 1)],
                            'price' => $bar[$i],
                            'is_volume' => 1,
                            'base_currency' => $dailyVolume1,
                            'quote_currency' => $dailyVolume2,
                            'created_at' => $carbonDate->format("Y-m-d $hour:$minutes:$randSeconds"),
                            'updated_at' => $carbonDate->format("Y-m-d $hour:$minutes:$randSeconds"),
                        ];
                    }

                    if($carbonOriginal->format("Y-m-d") == $carbonDate->format("Y-m-d")) {
                        (new MarketService())->updateStats($model->id, $bar[4], $dailyVolume1, $dailyVolume2);
                    }

                    if($carbonYesterday->format("Y-m-d") == $carbonDate->format("Y-m-d")) {
                        $model->last = $bar[4];
                        $model->update();
                    }

                });

                Transaction::insert($this->transactions);

        } catch (\Exception $e) {
            $this->info("Volume generate error");
            $this->info($e->getMessage());
        }

    }
}
