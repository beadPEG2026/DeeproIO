<?php

namespace Database\Factories\P2P;

use App\Modules\P2P\Models\PeerTrade\PeerPaymentMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

class PeerPaymentMethodFactory extends Factory
{
    protected $model = PeerPaymentMethod::class;

    public function definition()
    {
        return [
            'title' => $this->faker->randomElement(['Bank Transfer', 'PayPal', 'Wise', 'Skrill', 'Cash App']),
            'status' => true,
            'color' => $this->faker->hexColor(),
        ];
    }

    public function active()
    {
        return $this->state(fn (array $attributes) => [
            'status' => true,
        ]);
    }

    public function inactive()
    {
        return $this->state(fn (array $attributes) => [
            'status' => false,
        ]);
    }
}
