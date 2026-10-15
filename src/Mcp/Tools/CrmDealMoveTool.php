<?php

namespace Sellinnate\SalesCrm\Mcp\Tools;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Sellinnate\SalesCrm\Actions\MoveDeal;
use Sellinnate\SalesCrm\Authorization\Access;
use Sellinnate\SalesCrm\Enums\LostReason;
use Sellinnate\SalesCrm\Models\Deal;
use Sellinnate\SalesCrm\Models\Stage;

class CrmDealMoveTool extends CrmTool
{
    protected string $name = 'crm_deal_move';

    protected string $description = 'Moves a deal to another stage of its pipeline. If the stage requires fields the deal lacks, the error lists them (requirements.<field>): pass them in fill (amount_once, recurring, owner_id, company_name, offer_valid_until, expected_close_date, payment_terms, person {first_name, last_name, email}, next_activity {type, title, due_date, due_time}) and retry. A lost stage needs lost_reason.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'deal_id' => $schema->integer()->min(1)->required(),
            'stage_id' => $schema->integer()->min(1)->required(),
            'lost_reason' => $schema->string()->enum(LostReason::values()),
            'lost_note' => $schema->string()->max(1000),
            'fill' => $schema->object()->description('The fields the stage requires.'),
        ];
    }

    protected function perform(Request $request, Authenticatable $user, Access $access): Response
    {
        $validated = $request->validate([
            'deal_id' => ['required', 'integer', 'min:1'],
            'stage_id' => ['required', 'integer', 'min:1'],
            'lost_reason' => ['nullable', 'string'],
            'lost_note' => ['nullable', 'string', 'max:1000'],
            'fill' => ['nullable', 'array'],
        ]);

        $deal = Deal::query()->visibleTo($user)->findOrFail((int) $validated['deal_id']);
        $stage = Stage::query()->where('pipeline_id', $deal->pipeline_id)->find((int) $validated['stage_id']);

        if ($stage === null) {
            return Response::error('The stage does not belong to the pipeline of the deal: see crm_pipelines.');
        }

        /** @var array<string, mixed> $fill */
        $fill = array_filter([...(array) ($validated['fill'] ?? []), 'lost_reason' => $validated['lost_reason'] ?? null, 'lost_note' => $validated['lost_note'] ?? null], fn (mixed $value): bool => $value !== null);
        $deal = app(MoveDeal::class)->handle($deal, $stage, $user, $fill);

        return $this->json(['message' => 'Deal moved to «'.$stage->name.'».', 'deal' => $this->summary($deal->fresh() ?? $deal, $access)]);
    }
}
