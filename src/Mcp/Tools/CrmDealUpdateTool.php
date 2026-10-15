<?php

namespace Sellinnate\SalesCrm\Mcp\Tools;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Sellinnate\SalesCrm\Actions\UpdateDeal;
use Sellinnate\SalesCrm\Authorization\Access;
use Sellinnate\SalesCrm\Models\Deal;

class CrmDealUpdateTool extends CrmTool
{
    protected string $name = 'crm_deal_update';

    protected string $description = 'Changes fields of a deal (only those given): title, company_name, owner_id, priority, source, tags, external_ref, amount_once, deposit, recurring, recurring_period, recurring_options, payment_terms, offer_details, offer_valid_until, expected_close_date, sub_status, next_step, blocker, missing_data, call_context, closing_tip, last_contacted_at, signature_status (+ signature_evidence), payment_status (+ amount_collected, payment_evidence), approval_required, approver_id, approval_note, approval_status, commission_type, commission_value, commission_note, custom_values. The stage changes with crm_deal_move.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'deal_id' => $schema->integer()->min(1)->required(),
            'fields' => $schema->object()->required()->description('The fields to change, as in the description.'),
        ];
    }

    protected function perform(Request $request, Authenticatable $user, Access $access): Response
    {
        $validated = $request->validate(['deal_id' => ['required', 'integer', 'min:1'], 'fields' => ['required', 'array']]);
        $deal = Deal::query()->visibleTo($user)->findOrFail((int) $validated['deal_id']);
        /** @var array<string, mixed> $fields */
        $fields = $validated['fields'];
        $deal = app(UpdateDeal::class)->handle($deal, $fields, $user);

        return $this->json(['message' => 'Deal updated.', 'deal' => $this->summary($deal->fresh() ?? $deal, $access)]);
    }
}
