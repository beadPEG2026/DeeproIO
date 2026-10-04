<?php

namespace Database\Factories\Option;

use App\Models\Currency\Currency;
use App\Models\Market\Market;
use App\Models\Option\Option;
use App\Models\User\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

class OptionFactory extends Factory
{
    protected $model = Option::class;

    public function definition()
    {
        return [
            'user_id' => User::factory(),
            'market_id' => Market::factory(),
            'currency_id' => Currency::factory(),
            'type' => 'call',
            'amount' => '100',
            'price' => '50000',
            'market_price' => '50000',
            'pnl' => '0',
            'period' => '1m',
            'timeframe_seconds' => 60,
            'start_at' => Carbon::now(),
            'status' => 'active',
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
        ];
    }

    /**
     * Call option state
     */
    public function call()
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'call',
        ]);
    }

    /**
     * Put option state
     */
    public function put()
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'put',
        ]);
    }

    /**
     * Active option state
     */
    public function active()
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
            'start_at' => Carbon::now(),
        ]);
    }

    /**
     * Expired option state
     */
    public function expired()
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'expired',
            'start_at' => Carbon::now()->subMinutes(5),
        ]);
    }

    /**
     * Won option state
     */
    public function won()
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'win',
            'pnl' => '100',
        ]);
    }

    /**
     * Lost option state
     */
    public function lost()
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'lose',
            'pnl' => '-100',
        ]);
    }
}
