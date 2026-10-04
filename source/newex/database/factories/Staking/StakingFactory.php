<?php

namespace Database\Factories\Staking;

use App\Models\Currency\Currency;
use App\Models\Staking\Staking;
use Illuminate\Database\Eloquent\Factories\Factory;

class StakingFactory extends Factory
{
    protected $model = Staking::class;

    public function definition()
    {
        return [
            'currency_id' => Currency::factory(),
            'allowed_days' => '7,14,30,60,90',
            'rewards_percentage' => '1,2,5,10,15',
            'min_amount' => '10',
            'max_amount' => '100000',
            'status' => 'active',
        ];
    }

    /**
     * Active staking state
     */
    public function active()
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
        ]);
    }

    /**
     * Inactive staking state
     */
    public function inactive()
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
        ]);
    }
}
