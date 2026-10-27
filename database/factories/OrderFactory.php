<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Оформленный заказ без позиций: номер SM-2026-NNNNNN, клиент с организацией.
 *
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        static $n = 0;
        $n++;

        return [
            'number' => sprintf('SM-2026-%06d', $n),
            'user_id' => User::factory(['organization_id' => Organization::factory()]),
            'organization_id' => fn (array $attributes) => User::find($attributes['user_id'])?->organization_id,
            'status' => OrderStatus::New,
            'total' => 100,
            'total_without_vat' => 100,
            'total_vat' => 20,
            'total_with_vat' => 120,
            'placed_at' => now()->subMinutes($n),
        ];
    }
}
