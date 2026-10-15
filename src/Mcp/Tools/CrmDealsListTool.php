<?php

namespace Sellinnate\SalesCrm\Mcp\Tools;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Sellinnate\SalesCrm\Authorization\Access;
use Sellinnate\SalesCrm\Models\Deal;
use Sellinnate\SalesCrm\Models\Pipeline;
use Sellinnate\SalesCrm\Services\DealBoard;
use Sellinnate\SalesCrm\Support\Crm;

#[IsReadOnly]
class CrmDealsListTool extends CrmTool
{
    protected string $name = 'crm_deals_list';

    protected string $description = 'Searches the deals the user sees, with the filters of the board: free text (title, company, people, email, phone, external reference), quick filters (mine, p1, no_activity, stale, offer_expired, approval), owner, priority, source, tag, pipeline and stage. Returns at most 50 deals, most recently updated first.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description('Free text.'),
            'quick' => $schema->array()->items($schema->string()->enum(DealBoard::QUICK_FILTERS))->description('Quick filters, combined.'),
            'owner_id' => $schema->string()->description('Owner user id, or "none".'),
            'priority' => $schema->string()->enum(['p1', 'p2', 'p3', 'p4', 'excluded']),
            'pipeline_id' => $schema->integer(),
            'stage_id' => $schema->integer(),
            'include_closed' => $schema->boolean()->description('Also won and lost deals older than the board shows.'),
            'limit' => $schema->integer()->min(1)->max(50),
        ];
    }

    protected function perform(Request $request, Authenticatable $user, Access $access): Response
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:200'],
            'quick' => ['nullable', 'array'],
            'quick.*' => ['string'],
            'owner_id' => ['nullable'],
            'priority' => ['nullable', 'string'],
            'pipeline_id' => ['nullable', 'integer'],
            'stage_id' => ['nullable', 'integer'],
            'include_closed' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $pipeline = isset($validated['pipeline_id']) ? Pipeline::query()->visibleTo($user)->findOrFail((int) $validated['pipeline_id']) : null;
        $deals = app(DealBoard::class)->query($user, [
            'search' => $validated['search'] ?? null,
            'quick' => array_values(array_map('strval', $validated['quick'] ?? [])),
            'owner' => isset($validated['owner_id']) && is_scalar($validated['owner_id']) ? (string) $validated['owner_id'] : null,
            'priority' => $validated['priority'] ?? null,
            'stage' => $validated['stage_id'] ?? null,
            'closed' => ($validated['include_closed'] ?? false) ? 'all' : 'recent',
        ], $pipeline)
            ->with(['stage', 'pipeline', 'company', 'owner', 'people'])
            ->orderByDesc(Crm::table('deals').'.updated_at')
            ->limit((int) ($validated['limit'] ?? 25))
            ->get();

        return $this->json(['count' => $deals->count(), 'deals' => $deals->map(fn (Deal $deal): array => $this->summary($deal, $access))->values()->all()]);
    }
}
