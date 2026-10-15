<?php

namespace Sellinnate\SalesCrm\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Sellinnate\SalesCrm\Actions\CreateDeal;
use Sellinnate\SalesCrm\Actions\DeleteDeal;
use Sellinnate\SalesCrm\Actions\ManageActivities;
use Sellinnate\SalesCrm\Actions\ManageNotes;
use Sellinnate\SalesCrm\Actions\MoveDeal;
use Sellinnate\SalesCrm\Actions\UpdateDeal;
use Sellinnate\SalesCrm\Authorization\Access;
use Sellinnate\SalesCrm\Models\Deal;
use Sellinnate\SalesCrm\Models\Pipeline;
use Sellinnate\SalesCrm\Models\Stage;
use Sellinnate\SalesCrm\Services\DealBoard;
use Sellinnate\SalesCrm\Support\Crm;
use Sellinnate\SalesCrm\Support\DealPresenter;
use Sellinnate\SalesCrm\Workspaces\Workspace;

/**
 * The deals over JSON: list with the filters of the board, show, create, update, move, delete, and
 * notes and activities. Every write goes through the actions of the CRM (validation errors are 422
 * with the field errors, the requirements of a stage under `requirements.<field>`).
 */
class DealController extends Controller
{
    public function index(Request $request, Access $access, DealBoard $board): JsonResponse
    {
        $user = ApiUser::from($request, $access);
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:200'],
            'quick' => ['nullable', 'array'],
            'quick.*' => ['string', Rule::in(DealBoard::QUICK_FILTERS)],
            'owner_id' => ['nullable', 'string', 'max:64'],
            'priority' => ['nullable', 'string', 'max:16'],
            'source' => ['nullable', 'string', 'max:32'],
            'tag' => ['nullable', 'string', 'max:30'],
            'pipeline_id' => ['nullable', 'integer'],
            'stage_id' => ['nullable', 'integer'],
            'closed' => ['nullable', Rule::in(['recent', 'all'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $pipeline = isset($validated['pipeline_id']) ? Pipeline::query()->visibleTo($user)->findOrFail((int) $validated['pipeline_id']) : null;
        $page = $board->query($user, [
            'search' => $validated['search'] ?? null,
            'quick' => array_values($validated['quick'] ?? []),
            'owner' => $validated['owner_id'] ?? null,
            'priority' => $validated['priority'] ?? null,
            'source' => $validated['source'] ?? null,
            'tag' => $validated['tag'] ?? null,
            'stage' => $validated['stage_id'] ?? null,
            'closed' => $validated['closed'] ?? 'all',
        ], $pipeline)
            ->with(['stage', 'pipeline', 'company', 'owner', 'people'])
            ->orderByDesc(Crm::table('deals').'.updated_at')
            ->paginate((int) ($validated['per_page'] ?? 25));

        return new JsonResponse([
            'data' => collect($page->items())->map(fn (Deal $deal): array => DealPresenter::present($deal, $access))->values()->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function show(Request $request, Access $access, Deal $deal): JsonResponse
    {
        Gate::forUser(ApiUser::from($request, $access))->authorize('view', $deal);

        return new JsonResponse(['data' => DealPresenter::present($deal, $access)]);
    }

    public function store(Request $request, Access $access, CreateDeal $create): JsonResponse
    {
        $user = ApiUser::from($request, $access);
        /** @var array<string, mixed> $data */
        $data = $request->except(['workspace']);
        $workspace = null;

        if (isset($data['pipeline_id'])) {
            $workspace = Pipeline::query()->visibleTo($user)->findOrFail((int) $data['pipeline_id'])->workspaceRef();
        } elseif ($access->resolver()->enabled()) {
            $workspace = Workspace::fromKey($request->string('workspace')->toString()) ?? abort(422, 'Give the workspace (type|id) or a pipeline_id.');
        }

        $deal = $create->handle($workspace, $data, $user);

        return new JsonResponse(['data' => DealPresenter::present($deal->fresh() ?? $deal, $access)], 201);
    }

    public function update(Request $request, Access $access, UpdateDeal $update, Deal $deal): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->all();
        $deal = $update->handle($deal, $data, ApiUser::from($request, $access));

        return new JsonResponse(['data' => DealPresenter::present($deal->fresh() ?? $deal, $access)]);
    }

    public function move(Request $request, Access $access, MoveDeal $move, Deal $deal): JsonResponse
    {
        $user = ApiUser::from($request, $access);
        $validated = $request->validate(['stage_id' => ['required', 'integer'], 'after_id' => ['nullable', 'integer'], 'fill' => ['nullable', 'array']]);
        $stage = Stage::query()->where('pipeline_id', $deal->pipeline_id)->findOrFail((int) $validated['stage_id']);
        /** @var array<string, mixed> $fill */
        $fill = (array) ($validated['fill'] ?? []);
        $deal = $move->handle($deal, $stage, $user, $fill, isset($validated['after_id']) ? (int) $validated['after_id'] : null);

        return new JsonResponse(['data' => DealPresenter::present($deal->fresh() ?? $deal, $access)]);
    }

    public function destroy(Request $request, Access $access, DeleteDeal $delete, Deal $deal): JsonResponse
    {
        $delete->handle($deal, ApiUser::from($request, $access));

        return new JsonResponse(null, 204);
    }

    public function note(Request $request, Access $access, ManageNotes $notes, Deal $deal): JsonResponse
    {
        $validated = $request->validate(['body' => ['required', 'string'], 'pinned' => ['nullable', 'boolean']]);
        $note = $notes->add($deal, (string) $validated['body'], ApiUser::from($request, $access), (bool) ($validated['pinned'] ?? false));

        return new JsonResponse(['data' => ['id' => $note->id, 'body' => $note->body, 'created_at' => $note->created_at?->toIso8601String()]], 201);
    }

    public function activity(Request $request, Access $access, ManageActivities $activities, Deal $deal): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->only(['type', 'title', 'due_date', 'due_time', 'remind_minutes', 'assigned_to', 'notes', 'person_id']);
        $activity = $activities->save($deal, $data, ApiUser::from($request, $access));

        return new JsonResponse(['data' => ['id' => $activity->id, 'type' => $activity->type->value, 'title' => $activity->title, 'due_at' => $activity->due_at->toIso8601String()]], 201);
    }
}
