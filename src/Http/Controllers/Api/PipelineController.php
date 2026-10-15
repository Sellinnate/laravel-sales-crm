<?php

namespace Sellinnate\SalesCrm\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Sellinnate\SalesCrm\Authorization\Access;
use Sellinnate\SalesCrm\Models\Pipeline;
use Sellinnate\SalesCrm\Models\Stage;

/**
 * GET /api/crm/pipelines: the pipelines of the user with their stages.
 */
class PipelineController extends Controller
{
    public function index(Request $request, Access $access): JsonResponse
    {
        $user = ApiUser::from($request, $access);

        $pipelines = Pipeline::query()->visibleTo($user)->ordered()->with('stages')->get()->map(fn (Pipeline $pipeline): array => [
            'id' => $pipeline->id,
            'name' => $pipeline->name,
            'active' => $pipeline->is_active,
            'workspace' => $pipeline->workspaceRef()?->key(),
            'stages' => $pipeline->stages->map(fn (Stage $stage): array => [
                'id' => $stage->id,
                'name' => $stage->name,
                'kind' => $stage->kind->value,
                'probability' => $stage->probability,
                'position' => $stage->position,
                'rotting_days' => $stage->rotting_days,
                'required_fields' => $stage->required_fields ?? [],
            ])->values()->all(),
        ]);

        return new JsonResponse(['data' => $pipelines->values()->all()]);
    }
}
