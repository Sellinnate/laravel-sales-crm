<?php

namespace Sellinnate\SalesCrm\Support;

use Illuminate\Support\Facades\Route;
use Sellinnate\SalesCrm\Authorization\Access;
use Sellinnate\SalesCrm\Models\Deal;
use Sellinnate\SalesCrm\Models\Person;

/**
 * A deal as the JSON API and the MCP tools present it: amounts in major units, ids and names of its
 * stage, pipeline, company, owner and people.
 */
final class DealPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function present(Deal $deal, Access $access): array
    {
        $deal->loadMissing(['stage', 'pipeline', 'company', 'owner', 'people']);
        $money = fn (?int $cents): ?float => $cents === null ? null : $cents / 100;

        return array_filter([
            'id' => $deal->id,
            'title' => $deal->title,
            'workspace' => $access->resolver()->enabled() ? ['key' => $deal->workspaceRef()?->key(), 'name' => $access->label($deal->workspaceRef())] : null,
            'pipeline' => ['id' => $deal->pipeline_id, 'name' => $deal->pipeline->name],
            'stage' => ['id' => $deal->stage_id, 'name' => $deal->stage->name, 'kind' => $deal->stage->kind->value, 'probability' => $deal->stage->probability],
            'status' => $deal->isWon() ? 'won' : ($deal->isLost() ? 'lost' : 'open'),
            'company' => $deal->company === null ? null : ['id' => $deal->company->id, 'name' => $deal->company->name],
            'owner' => $deal->owner === null ? null : ['id' => Crm::userKey($deal->owner), 'name' => Crm::userName($deal->owner)],
            'priority' => $deal->priority?->value,
            'source' => $deal->source->value,
            'amount_once' => $money($deal->amount_once_cents),
            'deposit' => $money($deal->deposit_cents),
            'recurring' => $deal->recurring_cents === null ? null : ['amount' => $money($deal->recurring_cents), 'period' => $deal->recurring_period?->value],
            'first_year_value' => $money($deal->first_year_value_cents),
            'weighted_value' => $money($deal->weightedCents()),
            'currency' => Format::currency(),
            'expected_close_date' => $deal->expected_close_date?->toDateString(),
            'offer_valid_until' => $deal->offer_valid_until?->toDateString(),
            'next_step' => $deal->next_step,
            'next_activity_at' => $deal->next_activity_at?->toIso8601String(),
            'last_activity_at' => $deal->last_activity_at?->toIso8601String(),
            'won_at' => $deal->won_at?->toIso8601String(),
            'lost_at' => $deal->lost_at?->toIso8601String(),
            'lost_reason' => $deal->lost_reason?->value,
            'signature_status' => $deal->signature_status->value,
            'payment_status' => $deal->payment_status->value,
            'approval_status' => $deal->approval_status?->value,
            'people' => $deal->people->map(fn (Person $person): array => array_filter([
                'id' => $person->id,
                'name' => $person->name,
                'email' => $person->email,
                'phone' => $person->callableNumber(),
                'primary' => (bool) $person->getRelationValue('pivot')?->getAttribute('is_primary'),
            ], fn (mixed $value): bool => $value !== null))->values()->all(),
            'external_ref' => $deal->external_ref,
            'origin' => $deal->origin_type === null ? null : ['type' => $deal->origin_type, 'id' => $deal->origin_id],
            'tags' => $deal->tags,
            'custom_values' => $deal->custom_values,
            'url' => Route::has('sales-crm.deals.show') ? route('sales-crm.deals.show', $deal) : null,
            'created_at' => $deal->created_at?->toIso8601String(),
            'updated_at' => $deal->updated_at?->toIso8601String(),
        ], fn (mixed $value): bool => $value !== null && $value !== []);
    }
}
