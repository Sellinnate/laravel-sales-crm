<?php

namespace Sellinnate\SalesCrm\Mcp\Tools;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Sellinnate\SalesCrm\Authorization\Access;
use Sellinnate\SalesCrm\Data\TimelineEntry;
use Sellinnate\SalesCrm\Models\Activity;
use Sellinnate\SalesCrm\Models\Deal;
use Sellinnate\SalesCrm\Services\DealTimeline;

#[IsReadOnly]
class CrmDealGetTool extends CrmTool
{
    protected string $name = 'crm_deal_get';

    protected string $description = 'Returns a deal with its people, open activities and the latest 20 entries of its timeline (notes, activities done, emails, changes, documents).';

    public function schema(JsonSchema $schema): array
    {
        return ['deal_id' => $schema->integer()->min(1)->required()];
    }

    protected function perform(Request $request, Authenticatable $user, Access $access): Response
    {
        $validated = $request->validate(['deal_id' => ['required', 'integer', 'min:1']]);
        $deal = Deal::query()->visibleTo($user)->findOrFail((int) $validated['deal_id']);
        Gate::forUser($user)->authorize('view', $deal);

        return $this->json([
            ...$this->summary($deal, $access),
            'sub_status' => $deal->sub_status,
            'blocker' => $deal->blocker,
            'signature_status' => $deal->signature_status->value,
            'payment_status' => $deal->payment_status->value,
            'approval_status' => $deal->approval_status?->value,
            'open_activities' => Activity::query()->where('deal_id', $deal->id)->whereNull('done_at')->orderBy('due_at')->get()
                ->map(fn (Activity $activity): array => ['id' => $activity->id, 'type' => $activity->type->value, 'title' => $activity->title, 'due_at' => $activity->due_at->toIso8601String(), 'state' => $activity->state()])->all(),
            'timeline' => array_map(fn (TimelineEntry $entry): array => $entry->toArray(), app(DealTimeline::class)->for($deal, $user, null, 20)),
        ]);
    }
}
