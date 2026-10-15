<?php

namespace Sellinnate\SalesCrm\Mcp\Tools;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Sellinnate\SalesCrm\Authorization\Access;
use Sellinnate\SalesCrm\Models\Pipeline;
use Sellinnate\SalesCrm\Services\DealBoard;

#[IsReadOnly]
class CrmPipelinesTool extends CrmTool
{
    protected string $name = 'crm_pipelines';

    protected string $description = 'Lists the pipelines the user works in, with their stages (id, name, kind, probability, required fields) and, per stage, the number and first-year value of the open deals. Call it first to know the stage ids for crm_deal_create and crm_deal_move.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace' => $schema->string()->description('Workspace key ("type|id") to limit the result; omit for all.'),
        ];
    }

    protected function perform(Request $request, Authenticatable $user, Access $access): Response
    {
        $pipelines = Pipeline::query()->visibleTo($user)->ordered()->with('stages')->get();
        $workspace = $request->get('workspace');
        $board = app(DealBoard::class);

        return $this->json($pipelines
            ->filter(fn (Pipeline $pipeline): bool => ! is_string($workspace) || $workspace === '' || $pipeline->workspaceRef()?->key() === $workspace)
            ->map(function (Pipeline $pipeline) use ($access, $board, $user): array {
                $columns = collect($board->columns($pipeline, $board->query($user, ['closed' => 'recent'], $pipeline)))->keyBy(fn (array $column): int => $column['stage']->id);

                return [
                    'id' => $pipeline->id,
                    'name' => $pipeline->name,
                    'active' => $pipeline->is_active,
                    'workspace' => $access->resolver()->enabled() ? ['key' => $pipeline->workspaceRef()?->key(), 'name' => $access->label($pipeline->workspaceRef())] : null,
                    'stages' => $pipeline->stages->map(fn ($stage): array => [
                        'id' => $stage->id,
                        'name' => $stage->name,
                        'kind' => $stage->kind->value,
                        'probability' => $stage->probability,
                        'required_fields' => $stage->required_fields ?? [],
                        'deals' => $columns->get($stage->id)['count'] ?? 0,
                        'value' => ($columns->get($stage->id)['value'] ?? 0) / 100,
                    ])->values()->all(),
                ];
            })->values()->all());
    }
}
