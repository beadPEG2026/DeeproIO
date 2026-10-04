<?php

namespace Database\Factories\Order;

use App\Models\Market\Market;
use App\Models\Order\FuturesContract;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class FuturesContractFactory extends Factory
{
    protected $model = FuturesContract::class;

    public function definition()
    {
        return [
            'id' => (string) generate_uuid(),
            'user_id' => User::factory(),
            'market_id' => Market::factory(),
            'type' => FuturesContract::TYPE_MARKET,
            'is_long' => true,
            'status' => 'active',
            'leverage' => 10,
            'quantity' => '0.1',
            'price' => '50000',
            'liquidation_price' => '45000',
            'balance' => '500',
            'released_amount' => '0',
            'base_currency_id' => 1,
            'quote_currency_id' => 2,
            'take_profit_price' => null,
            'stop_loss_price' => null,
            'total_funding_fee_paid' => '0',
        ];
    }

    /**
     * Long position state
     */
    public function long()
    {
        return $this->state(fn (array $attributes) => [
            'is_long' => true,
        ]);
    }

    /**
     * Short position state
     */
    public function short()
    {
        return $this->state(fn (array $attributes) => [
            'is_long' => false,
        ]);
    }

    /**
     * Pending limit order state
     */
    public function pendingLimit()
    {
        return $this->state(fn (array $attributes) => [
            'type' => FuturesContract::TYPE_LIMIT,
            'status' => 'pending',
        ]);
    }

    /**
     * Active position state
     */
    public function active()
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
            'activated_at' => now(),
        ]);
    }

    /**
     * Scheduled position state
     */
    public function scheduled()
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'scheduled',
            'start_at' => now()->addHour(),
        ]);
    }

    /**
     * With take profit/stop loss
     */
    public function withTPSL(string $tpPrice = '55000', string $slPrice = '48000')
    {
        return $this->state(fn (array $attributes) => [
            'take_profit_price' => $tpPrice,
            'stop_loss_price' => $slPrice,
        ]);
    }

    /**
     * High leverage position
     */
    public function highLeverage(int $leverage = 100)
    {
        return $this->state(fn (array $attributes) => [
            'leverage' => $leverage,
        ]);
    }
}
