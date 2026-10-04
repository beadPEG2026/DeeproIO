<?php

namespace Database\Factories\Launchpad;

use App\Models\Currency\Currency;
use App\Models\Launchpad\Launchpad;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

class LaunchpadFactory extends Factory
{
    protected $model = Launchpad::class;

    public function definition()
    {
        return [
            'name' => $this->faker->company . ' Token',
            'description' => $this->faker->paragraph,
            'currency_id' => Currency::factory(),
            'network_id' => 1, // ETH
            'rate' => '0.001',
            'min_buy' => '0.1',
            'max_buy' => '10',
            'soft_cap' => '100',
            'hard_cap' => '1000',
            'start_time' => Carbon::now()->subDay(),
            'end_time' => Carbon::now()->addWeek(),
            'status' => true,
            'purchasable' => true,
            'raised_amount' => '0',
            'progress' => 0,
        ];
    }

    /**
     * Active launchpad state
     */
    public function active()
    {
        return $this->state(fn (array $attributes) => [
            'status' => true,
            'purchasable' => true,
            'start_time' => Carbon::now()->subDay(),
            'end_time' => Carbon::now()->addWeek(),
        ]);
    }

    /**
     * Inactive launchpad state
     */
    public function inactive()
    {
        return $this->state(fn (array $attributes) => [
            'status' => false,
        ]);
    }

    /**
     * Ended launchpad state
     */
    public function ended()
    {
        return $this->state(fn (array $attributes) => [
            'status' => true,
            'purchasable' => false,
            'start_time' => Carbon::now()->subWeek(),
            'end_time' => Carbon::now()->subDay(),
        ]);
    }

    /**
     * Upcoming launchpad state
     */
    public function upcoming()
    {
        return $this->state(fn (array $attributes) => [
            'status' => true,
            'purchasable' => false,
            'start_time' => Carbon::now()->addDay(),
            'end_time' => Carbon::now()->addWeek(),
        ]);
    }

    /**
     * Sold out state
     */
    public function soldOut()
    {
        return $this->state(fn (array $attributes) => [
            'raised_amount' => $attributes['hard_cap'] ?? '1000',
            'progress' => 100,
            'purchasable' => false,
        ]);
    }
}
