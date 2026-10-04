<?php

namespace App\Modules\Merchant\Tests\Factories;

use App\Models\Currency\Currency;
use App\Modules\Merchant\Models\Merchant;
use App\Modules\Merchant\Models\MerchantDepositAddress;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class MerchantDepositAddressFactory extends Factory
{
    protected $model = MerchantDepositAddress::class;

    public function definition(): array
    {
        return [
            'id' => Str::uuid()->toString(),
            'merchant_id' => Merchant::factory(),
            'currency_id' => 1,
            'address' => 'bc1q' . Str::random(38),
            'address_type' => 'unique',
            'status' => MerchantDepositAddress::STATUS_AVAILABLE,
            'hd_path' => "m/84'/0'/0'/0/" . rand(0, 1000),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    public function assigned(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => MerchantDepositAddress::STATUS_ASSIGNED,
            'assigned_at' => now(),
        ]);
    }
}
