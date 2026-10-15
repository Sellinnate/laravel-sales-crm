<?php

namespace Sellinnate\SalesCrm\Database\Factories;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Sellinnate\SalesCrm\Models\Deal;
use Sellinnate\SalesCrm\Models\Pipeline;
use Sellinnate\SalesCrm\Models\Stage;

/**
 * A deal in the first stage of a pipeline (one with the default stages unless given).
 *
 * @extends Factory<Deal>
 */
class DealFactory extends Factory
{
    protected $model = Deal::class;

    public function definition(): array
    {
        return [
            'title' => ucfirst(implode(' ', (array) fake()->words(3))),
            'source' => 'other',
            'stage_entered_at' => CarbonImmutable::now(),
            'last_activity_at' => CarbonImmutable::now(),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Deal $deal): void {
            if ($deal->getAttribute('stage_id') === null) {
                $pipeline = $deal->getAttribute('pipeline_id') !== null ? Pipeline::query()->findOrFail($deal->pipeline_id) : Pipeline::factory()->withStages()->create();
                $deal->pipeline_id = $pipeline->id;
                $deal->stage_id = (int) $pipeline->stages()->value('id');
            } elseif ($deal->getAttribute('pipeline_id') === null) {
                $deal->pipeline_id = (int) Stage::query()->whereKey($deal->stage_id)->value('pipeline_id');
            }

            if ($deal->workspace_type === null && $deal->getAttribute('pipeline_id') !== null) {
                $pipeline = Pipeline::query()->find($deal->pipeline_id);
                $deal->setWorkspace($pipeline?->workspaceRef());
            }
        });
    }

    public function inStage(Stage $stage): static
    {
        return $this->state(fn (): array => [
            'pipeline_id' => $stage->pipeline_id,
            'stage_id' => $stage->id,
            'won_at' => $stage->kind->value === 'won' ? CarbonImmutable::now() : null,
            'lost_at' => $stage->kind->value === 'lost' ? CarbonImmutable::now() : null,
            'lost_reason' => $stage->kind->value === 'lost' ? 'other' : null,
        ]);
    }
}
