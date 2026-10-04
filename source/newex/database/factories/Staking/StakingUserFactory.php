<?php

namespace Database\Factories\Staking;

use App\Models\Currency\Currency;
use App\Models\Staking\Staking;
use App\Models\Staking\StakingUser;
use App\Models\User\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

class StakingUserFactory extends Factory
{
    protected $model = StakingUser::class;

    public function definition()
    {
        $days = 30;
        $valueDate = Carbon::now()->addDays(1);

        return [
            'user_id' => User::factory(),
            'staking_id' => Staking::factory(),
            'currency_id' => Currency::factory(),
            'amount' => '1000',
            'days' => $days,
            'apy' => '5',
            'reward' => '0',
            'value_date' => $valueDate->format('Y-m-d 00:00:00'),
            'redemption_date' => $valueDate->addDays($days)->format('Y-m-d 00:00:00'),
            'status' => 'active',
        ];
    }

    /**
     * Active staking user state
     */
    public function active()
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
        ]);
    }

    /**
     * Redeemed state
     */
    public function redeemed()
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'redeemed',
        ]);
    }

    /**
     * Completed state
     */
    public function completed()
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
        ]);
    }

    /**
     * Redeemable state - past redemption date
     */
    public function redeemable()
    {
        return $this->state(fn (array $attributes) => [
            'redemption_date' => Carbon::now()->subDay()->format('Y-m-d 00:00:00'),
            'status' => 'active',
        ]);
    }
}
