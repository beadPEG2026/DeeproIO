<?php

namespace Database\Factories\P2P;

use App\Models\Currency\Currency;
use App\Models\User\User;
use App\Modules\P2P\Models\PeerTrade\PeerAd;
use Illuminate\Database\Eloquent\Factories\Factory;

class PeerAdFactory extends Factory
{
    protected $model = PeerAd::class;

    public function definition()
    {
        return [
            'id' => (string) generate_uuid(),
            'user_id' => User::factory(),
            'base_currency_id' => Currency::factory(),
            'quote_currency_id' => Currency::factory(),
            'amount' => '1',
            'remaining_amount' => '1',
            'min_amount' => '0.001',
            'max_amount' => '1',
            'type' => 'sell',
            'price' => '50000',
            'price_type' => 'fixed',
            'price_percentage' => '100',
            'timeframe' => 15,
            'remarks' => null,
            'auto_reply' => null,
            'status' => 'active',
            'fee_rate' => '0.1',
            'fee_reserved' => '0.001',
            'fee_remaining' => '0.001',
        ];
    }

    public function sell()
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'sell',
        ]);
    }

    public function buy()
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'buy',
        ]);
    }

    public function active()
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
        ]);
    }

    public function draft()
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'draft',
        ]);
    }

    public function closed()
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'closed',
        ]);
    }

    public function fixedPrice(string $price = '50000')
    {
        return $this->state(fn (array $attributes) => [
            'price_type' => 'fixed',
            'price' => $price,
        ]);
    }

    public function floatPrice(string $percentage = '100')
    {
        return $this->state(fn (array $attributes) => [
            'price_type' => 'float',
            'price_percentage' => $percentage,
        ]);
    }
}
