<?php

namespace App\Modules\Merchant\Tests\Factories;

use App\Modules\Merchant\Models\Merchant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class MerchantFactory extends Factory
{
    protected $model = Merchant::class;

    public function definition(): array
    {
        return [
            'id' => Str::uuid()->toString(),
            'user_id' => null, // Set manually or via state
            'business_name' => $this->faker->company(),
            'business_email' => $this->faker->companyEmail(),
            'website_url' => $this->faker->url(),
            'status' => 'active',
            'verification_status' => 'verified',
            'webhook_secret' => 'whsec_' . Str::random(32),
            'default_webhook_url' => $this->faker->url() . '/webhooks',
            'fee_percent' => '1.00',
            'daily_volume_limit_usd' => 100000.00,
            'monthly_volume_limit_usd' => 1000000.00,
            'single_invoice_limit_usd' => 10000.00,
            'min_invoice_amount_usd' => 1.00,
            'ip_whitelist' => null,
            'metadata' => [],
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
            'verification_status' => 'pending',
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'suspended',
        ]);
    }
}
