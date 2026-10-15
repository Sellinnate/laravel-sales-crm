<?php

namespace Sellinnate\SalesCrm\Actions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Sellinnate\SalesCrm\Models\Pipeline;
use Sellinnate\SalesCrm\Services\DealPositions;
use Sellinnate\SalesCrm\Services\DefaultPipeline;
use Sellinnate\SalesCrm\Support\Crm;
use Sellinnate\SalesCrm\Support\Locks;
use Sellinnate\SalesCrm\Workspaces\Workspace;

/**
 * Create, change or delete a pipeline of a workspace: a new one starts with the default stages; a
 * workspace always keeps at least one active pipeline; only a pipeline without deals is deleted.
 */
class SavePipeline
{
    public function __construct(private DefaultPipeline $defaultPipeline, private DealPositions $positions) {}

    /**
     * @param  array{name?: mixed, is_active?: mixed}  $data
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(Model|Workspace|null $workspace, array $data, Authenticatable $by, ?Pipeline $pipeline = null): Pipeline
    {
        $workspace = Workspace::of($workspace);

        $pipeline === null
            ? Gate::forUser($by)->authorize('create', [Pipeline::class, $workspace])
            : Gate::forUser($by)->authorize('update', $pipeline);

        if ($pipeline !== null && ! $pipeline->inWorkspace($workspace)) {
            throw ValidationException::withMessages(['pipeline' => __('sales-crm::validation.other_workspace')]);
        }

        $validated = Validator::make($data, [
            'name' => ['required', 'string', 'max:80'],
            'is_active' => ['boolean'],
        ], [], ['name' => mb_strtolower((string) __('sales-crm::fields.pipeline.name')), 'is_active' => mb_strtolower((string) __('sales-crm::fields.pipeline.is_active'))])->validate();

        return Locks::run(self::lockKey($workspace), fn (): Pipeline => Crm::db()->transaction(function () use ($workspace, $validated, $by, $pipeline): Pipeline {
            $name = trim((string) $validated['name']);

            if ($pipeline === null) {
                $active = (bool) ($validated['is_active'] ?? true);

                if (! $active && ! Pipeline::query()->inWorkspace($workspace)->active()->exists()) {
                    throw ValidationException::withMessages(['is_active' => __('sales-crm::validation.active_pipeline')]);
                }

                $position = (int) Pipeline::query()->inWorkspace($workspace)->max('position') + 1;

                return $this->defaultPipeline->create($workspace, $name, $by, $position, $active);
            }

            $active = (bool) ($validated['is_active'] ?? $pipeline->is_active);

            if (! $active && ! Pipeline::query()->inWorkspace($workspace)->whereKeyNot($pipeline->id)->active()->exists()) {
                throw ValidationException::withMessages(['is_active' => __('sales-crm::validation.active_pipeline')]);
            }

            $pipeline->fill(['name' => $name, 'is_active' => $active])->save();
            $this->positions->touch($pipeline->id);

            return $pipeline;
        }));
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function delete(Pipeline $pipeline, Authenticatable $by): void
    {
        Gate::forUser($by)->authorize('delete', $pipeline);

        // The lock of the pipelines of the workspace, then the one of the board: no deal arrives meanwhile.
        Locks::run(self::lockKey($pipeline->workspaceRef()), fn () => $this->positions->locked($pipeline->id, function () use ($pipeline): void {
            if ($pipeline->deals()->exists()) {
                throw ValidationException::withMessages(['pipeline' => __('sales-crm::validation.pipeline_has_deals')]);
            }

            if (! Pipeline::query()->inWorkspace($pipeline->workspaceRef())->whereKeyNot($pipeline->id)->active()->exists()) {
                throw ValidationException::withMessages(['pipeline' => __('sales-crm::validation.active_pipeline')]);
            }

            $pipeline->delete();
        }));
    }

    private static function lockKey(?Workspace $workspace): string
    {
        return 'pipelines:'.md5($workspace?->key() ?? 'shared');
    }
}
