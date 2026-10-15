<?php

namespace Sellinnate\SalesCrm\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Sellinnate\SalesCrm\Authorization\Access;
use Sellinnate\SalesCrm\Enums\ApprovalStatus;
use Sellinnate\SalesCrm\Enums\DealSource;
use Sellinnate\SalesCrm\Enums\StageKind;
use Sellinnate\SalesCrm\Events\ApprovalRequested;
use Sellinnate\SalesCrm\Events\DealCreated;
use Sellinnate\SalesCrm\Exceptions\MissingRequirements;
use Sellinnate\SalesCrm\Models\Deal;
use Sellinnate\SalesCrm\Models\Pipeline;
use Sellinnate\SalesCrm\Models\Stage;
use Sellinnate\SalesCrm\Models\WorkspaceSetting;
use Sellinnate\SalesCrm\Services\DealCompletions;
use Sellinnate\SalesCrm\Services\DealFields;
use Sellinnate\SalesCrm\Services\DealHistory;
use Sellinnate\SalesCrm\Services\DealPositions;
use Sellinnate\SalesCrm\Services\DealRequirements;
use Sellinnate\SalesCrm\Services\DefaultPipeline;
use Sellinnate\SalesCrm\Services\Matcher;
use Sellinnate\SalesCrm\Support\Crm;
use Sellinnate\SalesCrm\Workspaces\Workspace;
use Throwable;

/**
 * A new deal in a pipeline of a workspace: the quick add of the board, an integration of the host
 * (see {@see self::fromOrigin()}), the import, the JSON API and the MCP tools. The deal lands at the
 * top of its stage (the first open one unless told otherwise) with the fields that stage requires;
 * the company is matched or created and the person becomes the primary contact. The owner is the
 * author when they work in the workspace, unless another one is given. Without an author the deal
 * comes from the system.
 */
class CreateDeal
{
    public function __construct(
        private DealFields $fields,
        private DealRequirements $requirements,
        private DealHistory $history,
        private DealPositions $positions,
        private DefaultPipeline $defaultPipeline,
        private DealCompletions $completions,
        private Matcher $matcher,
        private Access $access,
    ) {}

    /**
     * @param  array<string, mixed>  $data  the deal fields, plus `pipeline_id`, `stage_id`, `person` (details or `person_id`, `role`), `next_activity`, `offer_document`
     * @param  bool  $requirements  false to import deals as they are: what the stage requires is reported, not asked
     * @param  bool  $append  true for imports: the deal goes at the bottom of its column, not on top
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(Model|Workspace|null $workspace, array $data, ?Authenticatable $by, bool $requirements = true, bool $append = false, ?Model $origin = null): Deal
    {
        $workspace = Workspace::of($workspace);

        if ($by !== null) {
            Gate::forUser($by)->authorize('create', [Deal::class, $workspace]);
        }

        $pipeline = $this->pipeline($workspace, $data['pipeline_id'] ?? null, $by);
        $stage = $this->stage($pipeline, $data['stage_id'] ?? null);
        $completions = DealCompletions::extract($data);

        $fields = array_diff_key($data, array_flip(['pipeline_id', 'stage_id']));
        $fields['title'] ??= null;
        $fields['source'] ??= DealSource::Other->value;

        if (! array_key_exists('owner_id', $fields)) {
            $fields['owner_id'] = $by !== null && $this->access->isMember($by, $workspace) ? Crm::userKey($by) : null;
        }

        $validated = $this->fields->validate($workspace, $fields);

        if ($stage->kind === StageKind::Lost && blank($validated['lost_reason'] ?? null)) {
            throw MissingRequirements::exception($stage, [], lostReason: true);
        }

        // The lock of the deduplication first, then the one of the board: both last until the commit.
        return $this->matcher->locked($workspace, fn (): Deal => $this->positions->locked($pipeline->id, fn (): Deal => Crm::db()->transaction(function () use ($workspace, $pipeline, $stage, $validated, $completions, $by, $requirements, $append, $origin): Deal {
            $now = CarbonImmutable::now();
            // The stage as it is now, under the lock of the board: its kind and requirements may have changed.
            $stage = Stage::query()->whereKey($stage->id)->firstOrFail();

            if ($stage->kind === StageKind::Lost && blank($validated['lost_reason'] ?? null)) {
                throw MissingRequirements::exception($stage, [], lostReason: true);
            }

            $deal = new Deal([
                'pipeline_id' => $pipeline->id,
                'stage_id' => $stage->id,
                'stage_entered_at' => $now,
                'last_activity_at' => $now,
                'won_at' => $stage->kind === StageKind::Won ? $now : null,
                'lost_at' => $stage->kind === StageKind::Lost ? $now : null,
                'created_by' => Crm::userKey($by),
                'updated_by' => Crm::userKey($by),
                'origin_type' => $origin?->getMorphClass(),
                'origin_id' => $origin === null ? null : (string) $origin->getKey(),
            ]);
            $deal->setWorkspace($workspace);

            $this->fields->fill($deal, $validated, $by);
            $this->fields->check($deal, $by);
            $deal->save();

            $this->completions->write($deal, $completions, $by, $deal->offer_valid_until?->toDateString());

            $missing = $requirements ? $this->requirements->missing($deal, $stage) : [];

            if ($missing !== []) {
                throw MissingRequirements::exception($stage, $missing);
            }

            // A deal born to approve reaches its approver, as a request made later does.
            if ($deal->approval_status === ApprovalStatus::Pending && $deal->approver_id !== null && ! Crm::sameUser($deal->approver_id, Crm::userKey($by))) {
                ApprovalRequested::dispatch($deal, $by);
            }

            $append ? $this->positions->append($deal) : $this->positions->place($deal, null);
            $deal->setRelation('stage', $stage);
            $this->history->created($deal, $by, $now);
            $this->completions->onEnter($deal, $stage);
            $this->positions->touch($pipeline->id);

            DealCreated::dispatch($deal, $by);

            return $deal;
        })));
    }

    /**
     * The deal born from a record of the host (an appointment held, an order, a form entry), once per
     * record: called again it returns the deal already there. Without `stage_id` the deal enters the
     * entry stage of the workspace settings, else the first open stage of the default pipeline.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function fromOrigin(Model $origin, Model|Workspace|null $workspace, array $data, ?Authenticatable $by = null): Deal
    {
        $workspace = Workspace::of($workspace);
        $existing = $this->existingFor($origin);

        if ($existing !== null) {
            return $existing;
        }

        if (! isset($data['stage_id']) && ! isset($data['pipeline_id'])) {
            $entry = WorkspaceSetting::for($workspace)->entryStage()->with('pipeline')->first();

            if ($entry !== null && $entry->isOpen() && $entry->pipeline->inWorkspace($workspace)) {
                $data['pipeline_id'] = $entry->pipeline_id;
                $data['stage_id'] = $entry->id;
            }
        }

        $data['source'] ??= DealSource::Integration->value;

        try {
            return $this->handle($workspace, $data, $by, requirements: false, origin: $origin);
        } catch (Throwable $exception) {
            // Created meanwhile by another request for the same record (unique origin).
            if ($exception instanceof UniqueConstraintViolationException && ($existing = $this->existingFor($origin)) !== null) {
                return $existing;
            }

            throw $exception;
        }
    }

    private function existingFor(Model $origin): ?Deal
    {
        return Deal::query()->where('origin_type', $origin->getMorphClass())->where('origin_id', (string) $origin->getKey())->first();
    }

    /**
     * @throws ValidationException
     */
    private function pipeline(?Workspace $workspace, mixed $pipelineId, ?Authenticatable $by): Pipeline
    {
        if ($pipelineId === null || $pipelineId === '') {
            return $this->defaultPipeline->ensureFor($workspace, $by);
        }

        return Pipeline::query()->inWorkspace($workspace)->whereKey(is_numeric($pipelineId) ? (int) $pipelineId : 0)->first()
            ?? throw ValidationException::withMessages(['pipeline_id' => __('sales-crm::validation.pipeline_workspace')]);
    }

    /**
     * @throws ValidationException
     */
    private function stage(Pipeline $pipeline, mixed $stageId): Stage
    {
        $stage = $stageId === null || $stageId === ''
            ? $pipeline->firstOpenStage()
            : $pipeline->stages->firstWhere('id', is_numeric($stageId) ? (int) $stageId : 0);

        return $stage ?? throw ValidationException::withMessages(['stage_id' => __('sales-crm::validation.stage_pipeline')]);
    }
}
