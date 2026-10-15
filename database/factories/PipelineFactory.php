<?php

namespace Sellinnate\SalesCrm\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Sellinnate\SalesCrm\Models\Pipeline;
use Sellinnate\SalesCrm\Services\DefaultPipeline;

/**
 * @extends Factory<Pipeline>
 */
class PipelineFactory extends Factory
{
    protected $model = Pipeline::class;

    public function definition(): array
    {
        return [
            'name' => 'Sales pipeline',
            'position' => 0,
            'is_active' => true,
        ];
    }

    /**
     * With the default stages of the configuration.
     */
    public function withStages(): static
    {
        return $this->afterCreating(function (Pipeline $pipeline): void {
            foreach (DefaultPipeline::stages() as $index => $stage) {
                $pipeline->stages()->create([...$stage, 'position' => $index]);
            }
        });
    }
}
