<?php

namespace Database\Factories\Order;

use App\Models\Market\Market;
use App\Models\Order\Order;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition()
    {
        return [
            'id' => (string) generate_uuid(),
            'user_id' => User::factory(),
            'market_id' => Market::factory(),
            'type' => Order::TYPE_LIMIT,
            'side' => Order::SIDE_BUY,
            'quantity' => '0.1',
            'initial_quantity' => '0.1',
            'quote_quantity' => '0',
            'initial_quote_quantity' => '0',
            'price' => '50000',
            'fee' => '0',
            'fee_rate' => '0.25',
            'base_currency_id' => 1,
            'quote_currency_id' => 2,
            'trigger_price' => null,
            'trigger_condition' => null,
        ];
    }
}
