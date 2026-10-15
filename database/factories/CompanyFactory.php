<?php

namespace Sellinnate\SalesCrm\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Sellinnate\SalesCrm\Models\Company;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'city' => fake()->city(),
        ];
    }
}
