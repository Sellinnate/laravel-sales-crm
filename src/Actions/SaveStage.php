<?php

namespace Sellinnate\SalesCrm\Actions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Sellinnate\SalesCrm\Enums\ActivityType;
use Sellinnate\SalesCrm\Enums\RequiredField;
use Sellinnate\SalesCrm\Enums\StageColor;
use Sellinnate\SalesCrm\Enums\StageKind;
use Sellinnate\SalesCrm\Models\Pipeline;
use Sellinnate\SalesCrm\Models\Stage;
use Sellinnate\SalesCrm\Services\DealPositions;
use Sellinnate\SalesCrm\Support\Crm;

/**
 * Create, change, reorder or delete the stages of a pipeline: name, colour, probability, kind,
 * rotting days, the fields required to enter and the activity created on entering. A new open stage
 * goes before won and lost; the kind of a stage with deals does not change; a pipeline keeps at least
 * one open, one won and one lost stage; only an empty stage is deleted.
 */
class SaveStage
{
    public function __construct(private DealPositions $positions) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(Pipeline $pipeline, array $data, Authenticatable $by, ?Stage $stage = null): Stage
    {
        Gate::forUser($by)->authorize('update', $pipeline);

        if ($stage !== null && $stage->pipeline_id !== $pipeline->id) {
            throw ValidationException::withMessages(['stage' => __('sales-crm::validation.stage_pipeline')]);
        }

        $data = array_map(fn (mixed $value): mixed => is_string($value) && trim($value) === '' ? null : $value, $data);

        /** @var array{name: string, color: string, probability: int|string, kind: string, rotting_days?: int|string|null, required_fields?: list<string>|null, on_enter_activity?: array{type?: string|null, title?: string|null, due_in_days?: int|string|null}|null} $validated */
        $validated = Validator::make($data, [
            'name' => ['required', 'string', 'max:80'],
            'color' => ['required', Rule::enum(StageColor::class)],
            'probability' => ['required', 'integer', 'between:0,100'],
            'kind' => ['required', Rule::enum(StageKind::class)],
            'rotting_days' => ['nullable', 'integer', 'between:1,365'],
            'required_fields' => ['nullable', 'array'],
            'required_fields.*' => [Rule::enum(RequiredField::class)],
            'on_enter_activity' => ['nullable', 'array'],
            'on_enter_activity.type' => ['nullable', 'required_with:on_enter_activity.title', Rule::enum(ActivityType::class)],
            'on_enter_activity.title' => ['nullable', 'string', 'max:200'],
            'on_enter_activity.due_in_days' => ['nullable', 'integer', 'between:0,365'],
        ], [], self::attributes())->validate();

        $kind = StageKind::from($validated['kind']);

        // Under the lock of the board: a deal never enters a stage while its kind changes.
        return $this->positions->locked($pipeline->id, fn (): Stage => Crm::db()->transaction(function () use ($pipeline, $validated, $kind, $stage): Stage {
            $stage = $stage === null ? null : Stage::query()->whereKey($stage->id)->lockForUpdate()->firstOrFail();

            if ($stage !== null && $stage->kind !== $kind && $stage->deals()->exists()) {
                throw ValidationException::withMessages(['kind' => __('sales-crm::validation.stage_kind_with_deals')]);
            }

            $automation = $validated['on_enter_activity'] ?? null;
            $attributes = [
                'name' => trim($validated['name']),
                'color' => $validated['color'],
                'probability' => match ($kind) {
                    StageKind::Won => 100,
                    StageKind::Lost => 0,
                    StageKind::Open => (int) $validated['probability'],
                },
                'kind' => $kind,
                'rotting_days' => $kind === StageKind::Open && isset($validated['rotting_days']) ? (int) $validated['rotting_days'] : null,
                'required_fields' => array_values(array_unique($validated['required_fields'] ?? [])) ?: null,
                'on_enter_activity' => is_array($automation) && filled($automation['title'] ?? null) ? [
                    'type' => (string) ($automation['type'] ?? ActivityType::Task->value),
                    'title' => trim((string) $automation['title']),
                    'due_in_days' => (int) ($automation['due_in_days'] ?? 0),
                ] : null,
            ];

            $stage ??= new Stage(['pipeline_id' => $pipeline->id, 'position' => $this->newPosition($pipeline, $kind)]);
            $stage->fill($attributes)->save();

            self::ensureKinds($pipeline);
            $this->positions->touch($pipeline->id);

            return $stage;
        }));
    }

    /**
     * Put the stages of a pipeline in a new order: the columns of the board follow it.
     *
     * @param  list<int|string>  $stageIds  every stage of the pipeline, in the new order
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function reorder(Pipeline $pipeline, array $stageIds, Authenticatable $by): void
    {
        Gate::forUser($by)->authorize('update', $pipeline);

        $stageIds = array_map(intval(...), $stageIds);

        $this->positions->locked($pipeline->id, fn () => Crm::db()->transaction(function () use ($pipeline, $stageIds): void {
            $current = Stage::query()->where('pipeline_id', $pipeline->id)->pluck('id')->map(intval(...))->sort()->values()->all();
            $given = $stageIds;
            sort($given);

            if ($given !== $current) {
                throw ValidationException::withMessages(['stages' => __('sales-crm::validation.stage_order')]);
            }

            foreach ($stageIds as $position => $id) {
                Stage::query()->whereKey($id)->update(['position' => $position]);
            }

            $this->positions->touch($pipeline->id);
        }));
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function delete(Stage $stage, Authenticatable $by): void
    {
        $stage->loadMissing('pipeline');
        Gate::forUser($by)->authorize('update', $stage->pipeline);

        $this->positions->locked($stage->pipeline_id, function () use ($stage): void {
            if ($stage->deals()->exists()) {
                throw ValidationException::withMessages(['stage' => __('sales-crm::validation.stage_has_deals')]);
            }

            self::ensureKinds($stage->pipeline, without: $stage->id);

            Crm::db()->transaction(function () use ($stage): void {
                $stage->delete();
                $this->positions->touch($stage->pipeline_id);
            });
        });
    }

    /**
     * A pipeline always has at least one open, one won and one lost stage.
     *
     * @throws ValidationException
     */
    public static function ensureKinds(Pipeline $pipeline, ?int $without = null): void
    {
        $kinds = Stage::query()
            ->where('pipeline_id', $pipeline->id)
            ->when($without !== null, fn ($query) => $query->whereKeyNot($without))
            ->get(['kind'])
            ->map(fn (Stage $stage): string => $stage->kind->value)
            ->unique()
            ->all();

        foreach (StageKind::cases() as $kind) {
            if (! in_array($kind->value, $kinds, true)) {
                throw ValidationException::withMessages(['kind' => __('sales-crm::validation.stage_kinds', ['kind' => mb_strtolower($kind->label())])]);
            }
        }
    }

    /**
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        $attributes = [];

        foreach (['name', 'color', 'probability', 'kind', 'rotting_days', 'required_fields'] as $field) {
            $attributes[$field] = mb_strtolower((string) __('sales-crm::fields.stage.'.$field));
        }

        $attributes['required_fields.*'] = $attributes['required_fields'];
        $attributes['on_enter_activity.type'] = mb_strtolower((string) __('sales-crm::fields.stage.automation_type'));
        $attributes['on_enter_activity.title'] = mb_strtolower((string) __('sales-crm::fields.stage.automation_title'));
        $attributes['on_enter_activity.due_in_days'] = mb_strtolower((string) __('sales-crm::fields.stage.automation_days'));

        return $attributes;
    }

    /**
     * A new open stage goes right before the first closed one, a closed stage at the end.
     */
    private function newPosition(Pipeline $pipeline, StageKind $kind): int
    {
        $stages = Stage::query()->where('pipeline_id', $pipeline->id)->orderBy('position')->orderBy('id')->get();

        if ($kind !== StageKind::Open) {
            return $stages->isEmpty() ? 0 : (int) $stages->max('position') + 1;
        }

        $index = $stages->search(fn (Stage $existing): bool => $existing->kind !== StageKind::Open);
        $position = $index === false ? $stages->count() : (int) $index;

        foreach ($stages->values() as $at => $existing) {
            $target = $at >= $position ? $at + 1 : $at;

            if ($existing->position !== $target) {
                $existing->forceFill(['position' => $target])->save();
            }
        }

        return $position;
    }
}
