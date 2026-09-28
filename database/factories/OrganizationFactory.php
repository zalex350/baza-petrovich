<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name'       => fake()->unique()->company(),
            'legal_name' => null,
            'unp'        => fake()->unique()->numerify('#########'),
            'address'    => fake()->address(),
            'phone'      => fake()->phoneNumber(),
            'email'      => fake()->unique()->companyEmail(),
            'comment'    => null,
        ];
    }
}
