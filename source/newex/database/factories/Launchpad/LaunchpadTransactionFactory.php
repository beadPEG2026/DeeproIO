<?php

namespace Database\Factories\Launchpad;

use App\Models\Launchpad\Launchpad;
use App\Models\Launchpad\LaunchpadTransaction;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class LaunchpadTransactionFactory extends Factory
{
    protected $model = LaunchpadTransaction::class;

    public function definition()
    {
        return [
            'user_id' => User::factory(),
            'launchpad_id' => Launchpad::factory(),
            'amount' => '1',
        ];
    }
}
