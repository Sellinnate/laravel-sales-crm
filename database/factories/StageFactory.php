<?php

namespace Sellinnate\SalesCrm\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Sellinnate\SalesCrm\Models\Pipeline;
use Sellinnate\SalesCrm\Models\Stage;

/**
 * @extends Factory<Stage>
 */
class StageFactory extends Factory
{
    protected $model = Stage::class;

    public function definition(): array
    {
        return [
            'pipeline_id' => Pipeline::factory(),
            'name' => fake()->words(2, true),
            'position' => 0,
            'color' => 'neutral',
            'probability' => 20,
            'kind' => 'open',
        ];
    }

    public function won(): static
    {
        return $this->state(['kind' => 'won', 'probability' => 100, 'color' => 'success']);
    }

    public function lost(): static
    {
        return $this->state(['kind' => 'lost', 'probability' => 0, 'color' => 'danger']);
    }
}
