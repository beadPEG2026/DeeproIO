<?php

namespace App\Modules\Merchant\Tests\Factories;

use App\Modules\Merchant\Models\Merchant;
use App\Modules\Merchant\Models\MerchantApiKey;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class MerchantApiKeyFactory extends Factory
{
    protected $model = MerchantApiKey::class;

    public function definition(): array
    {
        return [
            'id' => Str::uuid()->toString(),
            'merchant_id' => Merchant::factory(),
            'name' => $this->faker->words(2, true),
            'public_key' => 'pk_test_' . Str::random(24),
            'secret_key_hash' => hash('sha256', 'sk_test_' . Str::random(32)),
            'secret_key_last4' => Str::random(4),
            'environment' => 'test',
            'is_active' => true,
            'permissions' => ['invoices:read', 'invoices:write', 'webhooks:read'],
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    public function live(): static
    {
        return $this->state(fn (array $attributes) => [
            'public_key' => 'pk_live_' . Str::random(24),
            'environment' => 'live',
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
            'revoked_at' => now(),
        ]);
    }
}
