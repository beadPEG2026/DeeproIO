<?php

namespace App\Modules\Merchant\Tests\Factories;

use App\Modules\Merchant\Models\Merchant;
use App\Modules\Merchant\Models\MerchantInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class MerchantInvoiceFactory extends Factory
{
    protected $model = MerchantInvoice::class;

    public function definition(): array
    {
        return [
            'id' => Str::uuid()->toString(),
            'merchant_id' => Merchant::factory(),
            'external_id' => 'order_' . Str::random(8),
            'status' => 'awaiting_selection',
            'amount_usd' => $this->faker->randomFloat(2, 10, 1000),
            'customer_email' => $this->faker->email(),
            'customer_name' => $this->faker->name(),
            'description' => $this->faker->sentence(),
            'source' => 'api',
            'environment' => 'test',
            'expires_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    public function awaitingPayment(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'awaiting_payment',
            'deposit_address' => 'bc1q' . Str::random(38),
            'amount_crypto' => '0.005',
            'rate_usd' => '40000.00',
            'rate_locked_at' => now(),
            'rate_expires_at' => now()->addMinutes(15),
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'paid',
            'deposit_address' => 'bc1q' . Str::random(38),
            'amount_crypto' => '0.005',
            'amount_received_crypto' => '0.005',
            'rate_usd' => '40000.00',
            'paid_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'expired',
            'expires_at' => now()->subMinutes(5),
            'expired_at' => now(),
        ]);
    }
}
