<?php

namespace App\Console\Commands\Market;

use App\Models\Currency\Currency;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Setting;

class CurrencyRatesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'market:fiat-currency-rates';

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
        $apiKey = config('app.currencyapi_apikey');

        if(!$apiKey) return;

        $response = Http::get('https://api.currencyapi.com/v3/latest', [
            'apikey' => $apiKey
        ]);

        if ($response->successful()) {

            $content = $response->json();

            foreach($content['data'] as $currency) {

                $model = Currency::where('type', 'fiat')->where('symbol', $currency['code'])->first();

                if(!$model) continue;

                $model->rate = $currency['value'];
                $model->update();
            }
        }
    }
}
