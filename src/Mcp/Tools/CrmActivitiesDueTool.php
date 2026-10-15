<?php

namespace Sellinnate\SalesCrm\Mcp\Tools;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Sellinnate\SalesCrm\Authorization\Access;
use Sellinnate\SalesCrm\Models\Activity;
use Sellinnate\SalesCrm\Support\Crm;

#[IsReadOnly]
class CrmActivitiesDueTool extends CrmTool
{
    protected string $name = 'crm_activities_due';

    protected string $description = 'The open activities assigned to you that are overdue or due today (or within `days`), with their deal.';

    public function schema(JsonSchema $schema): array
    {
        return ['days' => $schema->integer()->min(0)->max(30)->description('Also the next N days (default 0: today).')];
    }

    protected function perform(Request $request, Authenticatable $user, Access $access): Response
    {
        $days = max(0, min(30, (int) $request->get('days', 0)));
        $now = CarbonImmutable::now();
        $activities = Activity::query()->visibleTo($user)->whereNull('done_at')->where('assigned_to', Crm::userKey($user))
            ->where('due_at', '<=', $now->addDays($days)->endOfDay())->with('deal')->orderBy('due_at')->limit(100)->get();

        return $this->json($activities->map(fn (Activity $activity): array => array_filter([
            'id' => $activity->id,
            'type' => $activity->type->value,
            'title' => $activity->title,
            'due_at' => $activity->due_at->toIso8601String(),
            'state' => $activity->state($now),
            'deal' => $activity->deal === null ? null : ['id' => $activity->deal->id, 'title' => $activity->deal->title],
        ], fn (mixed $value): bool => $value !== null))->values()->all());
    }
}
