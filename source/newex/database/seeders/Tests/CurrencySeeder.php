<?php

namespace Database\Seeders\Tests;

use App\Models\Currency\Currency;
use Illuminate\Database\Seeder;

class CurrencySeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        $currencies = [
            'BTC',
            'USDT'
        ];

        foreach ($currencies as $currency) {
            $model = new Currency();
            $model->name = $currency;
            $model->symbol = $currency;
            $model->type = "coin";
            $model->save();
        }
    }
}
