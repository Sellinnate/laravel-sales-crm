<?php

namespace Sellinnate\SalesCrm\Services;

use Carbon\CarbonImmutable;
use Sellinnate\SalesCrm\Enums\ActivityType;
use Sellinnate\SalesCrm\Enums\DocumentKind;
use Sellinnate\SalesCrm\Enums\RecurringPeriod;
use Sellinnate\SalesCrm\Enums\RequiredField;
use Sellinnate\SalesCrm\Enums\StageKind;
use Sellinnate\SalesCrm\Models\Deal;
use Sellinnate\SalesCrm\Models\Stage;

/**
 * The fields a deal needs to enter a stage: asked by the mini form of the board and checked again by
 * the move itself, so no caller (API, MCP, a forged request) skips them. A lost stage also needs the
 * reason of the loss.
 */
class DealRequirements
{
    /**
     * The required fields of the stage the deal does not satisfy yet.
     *
     * @return list<RequiredField>
     */
    public function missing(Deal $deal, Stage $stage): array
    {
        return array_values(array_filter($stage->requiredFields(), fn (RequiredField $field): bool => ! $this->satisfied($deal, $field)));
    }

    public function needsLostReason(Deal $deal, Stage $stage): bool
    {
        return $stage->kind === StageKind::Lost && $deal->lost_reason === null;
    }

    public function allows(Deal $deal, Stage $stage): bool
    {
        return $this->missing($deal, $stage) === [] && ! $this->needsLostReason($deal, $stage);
    }

    /**
     * The keys of the mini form that complete the missing fields (and the reason of a loss): only
     * these reach the move, so a field the form does not show never changes.
     *
     * @param  list<RequiredField>  $missing
     * @return list<string>
     */
    public static function fillKeys(array $missing, bool $lostReason): array
    {
        $keys = [];

        foreach ($missing as $field) {
            array_push($keys, ...match ($field) {
                RequiredField::Company => ['company_name'],
                RequiredField::Person => ['person'],
                RequiredField::Owner => ['owner_id'],
                RequiredField::Amount => ['amount_once', 'recurring', 'recurring_period'],
                RequiredField::ExpectedCloseDate => ['expected_close_date'],
                RequiredField::OfferValidUntil => ['offer_valid_until'],
                RequiredField::PaymentTerms => ['payment_terms'],
                RequiredField::NextActivity => ['next_activity'],
                RequiredField::OfferDocument => ['offer_document', 'offer_valid_until'],
            });
        }

        return $lostReason ? [...$keys, 'lost_reason', 'lost_note'] : $keys;
    }

    /**
     * The starting values of the mini form.
     *
     * @param  list<RequiredField>  $missing
     * @return array<string, mixed>
     */
    public static function fillDefaults(Deal $deal, array $missing): array
    {
        return array_filter([
            'recurring_period' => in_array(RequiredField::Amount, $missing, true) ? ($deal->recurring_period ?? RecurringPeriod::Yearly)->value : null,
            'owner_id' => in_array(RequiredField::Owner, $missing, true) ? '' : null,
            'next_activity' => in_array(RequiredField::NextActivity, $missing, true)
                ? ['type' => ActivityType::Call->value, 'title' => '', 'due_date' => CarbonImmutable::tomorrow()->toDateString(), 'due_time' => '']
                : null,
            'person' => in_array(RequiredField::Person, $missing, true) ? ['first_name' => '', 'last_name' => '', 'email' => '', 'mobile' => ''] : null,
        ], fn (mixed $value): bool => $value !== null);
    }

    public function satisfied(Deal $deal, RequiredField $field): bool
    {
        return match ($field) {
            RequiredField::Company => $deal->company_id !== null,
            RequiredField::Person => $deal->exists && $deal->people()->exists(),
            RequiredField::Owner => $deal->owner_id !== null,
            RequiredField::Amount => (int) $deal->amount_once_cents > 0 || (int) $deal->recurring_cents > 0,
            RequiredField::ExpectedCloseDate => $deal->expected_close_date !== null,
            RequiredField::OfferValidUntil => $deal->offer_valid_until !== null,
            RequiredField::PaymentTerms => filled($deal->payment_terms),
            RequiredField::NextActivity => $deal->exists && $deal->activities()->whereNull('done_at')->exists(),
            RequiredField::OfferDocument => $deal->exists && $deal->documents()->where('kind', DocumentKind::Quote->value)->exists(),
        };
    }
}
