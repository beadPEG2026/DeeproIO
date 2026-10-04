<?php

namespace Database\Factories\Market;

use App\Models\Currency\Currency;
use App\Models\Market\Market;
use Illuminate\Database\Eloquent\Factories\Factory;

class MarketFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Market::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        $base_currency = Currency::orderBy('id', 'desc')->first();
        $quote_currency = Currency::orderBy('id', 'asc')->first();

        return [
            'name' => ($base_currency && $quote_currency) ? ($base_currency->name . "-" . $quote_currency->name) : 'BTC-USDT',
            'base_currency_id' => $base_currency->id ?? 1,
            'quote_currency_id' => $quote_currency->id ?? 2,
            'base_precision' => 8,
            'quote_precision' => 8,
        ];
    }
}
