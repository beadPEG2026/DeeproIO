<?php

namespace Database\Factories\P2P;

use App\Models\Currency\Currency;
use App\Models\User\User;
use App\Modules\P2P\Models\PeerTrade\PeerAd;
use App\Modules\P2P\Models\PeerTrade\PeerOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

class PeerOrderFactory extends Factory
{
    protected $model = PeerOrder::class;

    public function definition()
    {
        return [
            'id' => (string) generate_uuid(),
            'user_id' => User::factory(),
            'ad_user_id' => User::factory(),
            'base_currency_id' => Currency::factory(),
            'quote_currency_id' => Currency::factory(),
            'amount' => '0.1',
            'quote_amount' => '5000',
            'payment_method_id' => 1,
            'user_payment_method_id' => 1,
            'ad_id' => PeerAd::factory(),
            'price' => '50000',
            'status' => 'payment_pending',
            'type' => 'buy',
            'timeframe' => 15,
            'fee_maker' => '0.001',
            'fee_taker' => '0.001',
            'isQuoteAmount' => false,
            'pair' => 'BTC-USD',
        ];
    }

    public function buy()
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'buy',
        ]);
    }

    public function sell()
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'sell',
        ]);
    }

    public function paymentPending()
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'payment_pending',
        ]);
    }

    public function confirmTransfer()
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'confirm_transfer',
        ]);
    }

    public function completed()
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
        ]);
    }

    public function cancelled()
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'cancelled',
        ]);
    }

    public function appealed()
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'appealed',
        ]);
    }
}
