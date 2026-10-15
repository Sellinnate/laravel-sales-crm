<?php

namespace Sellinnate\SalesCrm\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Sellinnate\SalesCrm\Models\Person;

/**
 * @extends Factory<Person>
 */
class PersonFactory extends Factory
{
    protected $model = Person::class;

    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
        ];
    }
}
