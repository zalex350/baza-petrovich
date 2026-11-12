<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Rules\Unp;
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
            'name' => fake()->unique()->company(),
            'legal_name' => null,
            'unp' => self::unp(),
            'address' => fake()->address(),
            'phone' => fake()->phoneNumber(),
            'email' => fake()->unique()->companyEmail(),
            'comment' => null,
        ];
    }

    /** Действительный УНП (контрольная цифра — App\Rules\Unp), уникальный в прогоне */
    private static function unp(): string
    {
        do {
            $first8 = fake()->unique()->numerify('########');
        } while (Unp::checkDigit($first8) === null);

        return $first8.Unp::checkDigit($first8);
    }
}
