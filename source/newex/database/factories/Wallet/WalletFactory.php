<?php

namespace Database\Factories\Wallet;

use App\Models\Currency\Currency;
use App\Models\User\User;
use App\Models\Wallet\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;

class WalletFactory extends Factory
{
    protected $model = Wallet::class;

    public function definition()
    {
        return [
            'user_id' => User::factory(),
            'currency_id' => Currency::factory(),
            'balance_in_wallet' => '0',
            'balance_in_trade' => '0',
            'balance_in_order' => '0',
            'balance_in_withdraw' => '0',
        ];
    }
}
