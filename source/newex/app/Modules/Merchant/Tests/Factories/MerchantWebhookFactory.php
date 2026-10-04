<?php

namespace App\Modules\Merchant\Tests\Factories;

use App\Modules\Merchant\Models\Merchant;
use App\Modules\Merchant\Models\MerchantInvoice;
use App\Modules\Merchant\Models\MerchantWebhook;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class MerchantWebhookFactory extends Factory
{
    protected $model = MerchantWebhook::class;

    public function definition(): array
    {
        return [
            'id' => Str::uuid()->toString(),
            'merchant_id' => Merchant::factory(),
            'invoice_id' => MerchantInvoice::factory(),
            'event_type' => $this->faker->randomElement([
                'invoice.created',
                'invoice.pending',
                'invoice.paid',
                'invoice.expired',
            ]),
            'idempotency_key' => Str::uuid()->toString(),
            'webhook_url' => $this->faker->url() . '/webhooks',
            'payload' => ['test' => 'data'],
            'status' => 'pending',
            'attempt_count' => 0,
            'max_attempts' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    public function delivered(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'delivered',
            'delivered_at' => now(),
            'last_response_code' => 200,
            'attempt_count' => 1,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'last_response_code' => 500,
            'attempt_count' => 5,
            'last_error' => 'Server error',
        ]);
    }
}
