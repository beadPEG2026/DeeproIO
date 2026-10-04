<?php

namespace Database\Seeders\Tests;

use App\Models\Currency\Currency;
use App\Models\Market\Market;
use Illuminate\Database\Seeder;

class MarketSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        $base = Currency::where('symbol', 'BTC')->first();
        $quote = Currency::where('symbol', 'USDT')->first();

        $model = new Market();
        $model->name = $base->symbol . '-' . $quote->symbol;
        $model->base_currency_id = $base->id;
        $model->quote_currency_id = $quote->id;
        $model->base_ticker_size = '0.00001';
        $model->quote_ticker_size = '0.01';
        $model->save();
    }
}
