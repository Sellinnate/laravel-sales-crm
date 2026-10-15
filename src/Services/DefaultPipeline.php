<?php

namespace Sellinnate\SalesCrm\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Sellinnate\SalesCrm\Enums\ActivityType;
use Sellinnate\SalesCrm\Enums\RequiredField;
use Sellinnate\SalesCrm\Enums\StageColor;
use Sellinnate\SalesCrm\Enums\StageKind;
use Sellinnate\SalesCrm\Models\Pipeline;
use Sellinnate\SalesCrm\Support\Crm;
use Sellinnate\SalesCrm\Support\Locks;
use Sellinnate\SalesCrm\Workspaces\Workspace;

/**
 * The default pipeline of a workspace, created the first time the workspace is opened in the CRM
 * (not when the workspace is created: workspaces that never use the CRM have no rows) and editable
 * afterwards. Its stages come from `sales-crm.pipeline.default_stages`.
 */
class DefaultPipeline
{
    /**
     * The stages of a new pipeline, validated against the catalogues.
     *
     * @return list<array{name: string, color: string, probability: int, kind: string, rotting_days: int|null, required_fields: list<string>|null, on_enter_activity: array{type: string, title: string, due_in_days: int}|null}>
     */
    public static function stages(): array
    {
        $stages = [];
        $configured = config('sales-crm.pipeline.default_stages', []);

        foreach (is_array($configured) ? $configured : [] as $stage) {
            if (! is_array($stage) || ! is_string($stage['name'] ?? null) || trim($stage['name']) === '') {
                continue;
            }

            $kind = StageKind::tryFrom((string) ($stage['kind'] ?? 'open')) ?? StageKind::Open;
            $automation = is_array($stage['on_enter_activity'] ?? null) ? $stage['on_enter_activity'] : null;
            $type = $automation === null ? null : ActivityType::tryFrom((string) ($automation['type'] ?? ''));
            $required = array_values(array_filter(
                array_map(fn (mixed $field): ?string => RequiredField::tryFrom(is_string($field) ? $field : '')?->value, (array) ($stage['required_fields'] ?? [])),
            ));

            $stages[] = [
                'name' => mb_substr(trim($stage['name']), 0, 80),
                'color' => (StageColor::tryFrom((string) ($stage['color'] ?? '')) ?? StageColor::Neutral)->value,
                'probability' => match ($kind) {
                    StageKind::Won => 100,
                    StageKind::Lost => 0,
                    StageKind::Open => max(0, min(100, (int) ($stage['probability'] ?? 0))),
                },
                'kind' => $kind->value,
                'rotting_days' => $kind === StageKind::Open && isset($stage['rotting_days']) ? max(1, (int) $stage['rotting_days']) : null,
                'required_fields' => $required === [] ? null : $required,
                'on_enter_activity' => $type !== null && is_string($automation['title'] ?? null) && trim($automation['title']) !== '' ? [
                    'type' => $type->value,
                    'title' => mb_substr(trim($automation['title']), 0, 200),
                    'due_in_days' => max(0, (int) ($automation['due_in_days'] ?? 0)),
                ] : null,
            ];
        }

        $kinds = array_unique(array_column($stages, 'kind'));

        // A pipeline always has an open, a won and a lost stage: a broken configuration falls back on them.
        if (count($kinds) < 3) {
            return [
                ['name' => 'New', 'color' => 'neutral', 'probability' => 10, 'kind' => 'open', 'rotting_days' => null, 'required_fields' => null, 'on_enter_activity' => null],
                ['name' => 'Won', 'color' => 'success', 'probability' => 100, 'kind' => 'won', 'rotting_days' => null, 'required_fields' => null, 'on_enter_activity' => null],
                ['name' => 'Lost', 'color' => 'danger', 'probability' => 0, 'kind' => 'lost', 'rotting_days' => null, 'required_fields' => null, 'on_enter_activity' => null],
            ];
        }

        return $stages;
    }

    /**
     * The first pipeline of the workspace (the active ones first), creating the default one when there is none.
     */
    public function ensureFor(?Workspace $workspace, ?Authenticatable $by = null): Pipeline
    {
        $pipeline = $this->first($workspace);

        if ($pipeline !== null) {
            return $pipeline;
        }

        return Locks::run('default-pipeline:'.($workspace?->key() ?? 'shared'), fn (): Pipeline => $this->first($workspace)
            ?? $this->create($workspace, (string) config('sales-crm.pipeline.default_name', 'Sales pipeline'), $by));
    }

    public function first(?Workspace $workspace): ?Pipeline
    {
        return Pipeline::query()->inWorkspace($workspace)->ordered()->first();
    }

    /**
     * A new pipeline of the workspace with the default stages.
     */
    public function create(?Workspace $workspace, string $name, ?Authenticatable $by = null, int $position = 0, bool $active = true): Pipeline
    {
        return Crm::db()->transaction(function () use ($workspace, $name, $by, $position, $active): Pipeline {
            $pipeline = new Pipeline([
                'name' => $name,
                'position' => $position,
                'is_active' => $active,
                'created_by' => Crm::userKey($by),
            ]);
            $pipeline->setWorkspace($workspace)->save();

            foreach (self::stages() as $index => $stage) {
                $pipeline->stages()->create([...$stage, 'position' => $index]);
            }

            return $pipeline->load('stages');
        });
    }
}
