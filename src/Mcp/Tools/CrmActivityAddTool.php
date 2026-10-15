<?php

namespace Sellinnate\SalesCrm\Mcp\Tools;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Sellinnate\SalesCrm\Actions\ManageActivities;
use Sellinnate\SalesCrm\Authorization\Access;
use Sellinnate\SalesCrm\Enums\ActivityType;
use Sellinnate\SalesCrm\Models\Deal;

class CrmActivityAddTool extends CrmTool
{
    protected string $name = 'crm_activity_add';

    protected string $description = 'Plans an activity on a deal: type (call, email, meeting, deadline, task), title, due_date (Y-m-d), optional due_time (H:i), remind_minutes, assigned_to (user id, default: you), notes. With done=true and an outcome it logs an activity already done.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'deal_id' => $schema->integer()->min(1)->required(),
            'type' => $schema->string()->enum(ActivityType::values())->required(),
            'title' => $schema->string()->max(200)->required(),
            'due_date' => $schema->string()->required(),
            'due_time' => $schema->string(),
            'remind_minutes' => $schema->integer(),
            'assigned_to' => $schema->string(),
            'notes' => $schema->string()->max(2000),
            'done' => $schema->boolean(),
            'outcome' => $schema->string()->max(2000),
        ];
    }

    protected function perform(Request $request, Authenticatable $user, Access $access): Response
    {
        $data = $request->all();
        $deal = Deal::query()->visibleTo($user)->findOrFail((int) ($data['deal_id'] ?? 0));
        $actions = app(ManageActivities::class);
        $activity = $actions->save($deal, array_intersect_key($data, array_flip(['type', 'title', 'due_date', 'due_time', 'remind_minutes', 'assigned_to', 'notes'])), $user);

        if ((bool) ($data['done'] ?? false)) {
            $activity = $actions->complete($activity, is_string($data['outcome'] ?? null) ? $data['outcome'] : null, $user);
        }

        return $this->json(['message' => $activity->isDone() ? 'Activity logged.' : 'Activity planned.', 'activity_id' => $activity->id, 'due_at' => $activity->due_at->toIso8601String()]);
    }
}
