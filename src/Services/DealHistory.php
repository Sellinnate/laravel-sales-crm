<?php

namespace Sellinnate\SalesCrm\Services;

use BackedEnum;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Sellinnate\SalesCrm\Enums\CommissionType;
use Sellinnate\SalesCrm\Enums\DealEventType;
use Sellinnate\SalesCrm\Enums\StageKind;
use Sellinnate\SalesCrm\Models\Company;
use Sellinnate\SalesCrm\Models\Deal;
use Sellinnate\SalesCrm\Models\DealEvent;
use Sellinnate\SalesCrm\Models\Stage;
use Sellinnate\SalesCrm\Models\StageVisit;
use Sellinnate\SalesCrm\Support\Crm;
use Sellinnate\SalesCrm\Support\Format;

/**
 * The history of a deal: an event for its creation, for every change of a tracked field and of stage
 * (with won, lost and reopened), and a visit per stage for the time spent there. Values are stored
 * as shown — names, labels, amounts and dates — so the timeline and the MCP tools read them as they
 * are, even after the owner or the company is gone.
 */
class DealHistory
{
    /**
     * The tracked fields (their labels are `sales-crm::fields.deal.<field>`).
     */
    public const FIELDS = [
        'title', 'company_id', 'owner_id', 'priority', 'amount_once_cents', 'deposit_cents', 'recurring_cents', 'recurring_period',
        'expected_close_date', 'offer_valid_until', 'signature_status', 'payment_status', 'amount_collected_cents',
        'approval_required', 'approval_status', 'approver_id', 'commission_type', 'commission_value', 'lost_reason',
    ];

    public static function label(string $field): string
    {
        $key = 'sales-crm::fields.deal.'.$field;
        $label = __($key);

        return is_string($label) && $label !== $key ? $label : $field;
    }

    /**
     * The deal was created: the event and the first visit, in its stage.
     */
    public function created(Deal $deal, ?Authenticatable $by, ?CarbonImmutable $at = null): void
    {
        $at ??= CarbonImmutable::now();
        $deal->loadMissing('stage');

        DealEvent::query()->create([
            'deal_id' => $deal->id,
            'user_id' => Crm::userKey($by),
            'type' => DealEventType::Created,
            'to' => $deal->stage->name,
            'created_at' => $at,
        ]);

        $this->enter($deal, $deal->stage, $by, $at);
    }

    /**
     * The tracked fields changed by the last save, one event each.
     *
     * @param  array<string, mixed>  $before  the attributes before the change ({@see Deal::getOriginal()})
     * @return list<string> the tracked fields that changed
     */
    public function updated(Deal $deal, array $before, ?Authenticatable $by): array
    {
        $changed = array_values(array_intersect(self::FIELDS, array_keys($deal->getChanges())));
        $now = CarbonImmutable::now();
        $tracked = [];

        foreach ($changed as $field) {
            $previousType = $before['commission_type'] ?? null;
            $from = $this->display($field, $before[$field] ?? null, $previousType instanceof CommissionType ? $previousType : CommissionType::tryFrom((string) $previousType));
            $to = $this->display($field, $deal->getAttribute($field), $deal->commission_type);

            if ($from === $to) {
                continue;
            }

            $tracked[] = $field;

            DealEvent::query()->create([
                'deal_id' => $deal->id,
                'user_id' => Crm::userKey($by),
                'type' => DealEventType::Updated,
                'field' => $field,
                'from' => $from,
                'to' => $to,
                'created_at' => $now,
            ]);
        }

        return $tracked;
    }

    /**
     * The deal left a stage for another: the visits, the event of the stage and, when the kind of the
     * stage changes, the won, lost or reopened event.
     */
    public function moved(Deal $deal, Stage $from, Stage $to, ?Authenticatable $by, ?CarbonImmutable $at = null): ?DealEventType
    {
        $at ??= CarbonImmutable::now();

        StageVisit::query()->where('deal_id', $deal->id)->whereNull('left_at')->update(['left_at' => $at]);
        $this->enter($deal, $to, $by, $at);

        DealEvent::query()->create([
            'deal_id' => $deal->id,
            'user_id' => Crm::userKey($by),
            'type' => DealEventType::StageChanged,
            'field' => 'stage_id',
            'from' => $from->name,
            'to' => $to->name,
            'created_at' => $at,
        ]);

        $outcome = match (true) {
            $to->kind === StageKind::Won && $from->kind !== StageKind::Won => DealEventType::Won,
            $to->kind === StageKind::Lost && $from->kind !== StageKind::Lost => DealEventType::Lost,
            $to->kind === StageKind::Open && $from->kind !== StageKind::Open => DealEventType::Reopened,
            default => null,
        };

        if ($outcome !== null) {
            DealEvent::query()->create([
                'deal_id' => $deal->id,
                'user_id' => Crm::userKey($by),
                'type' => $outcome,
                'to' => $outcome === DealEventType::Lost ? $deal->lost_reason?->label() : null,
                'created_at' => $at,
            ]);
        }

        return $outcome;
    }

    private function enter(Deal $deal, Stage $stage, ?Authenticatable $by, CarbonImmutable $at): void
    {
        StageVisit::query()->create([
            'deal_id' => $deal->id,
            'pipeline_id' => $stage->pipeline_id,
            'stage_id' => $stage->id,
            'stage_name' => $stage->name,
            'entered_at' => $at,
            'entered_by' => Crm::userKey($by),
        ]);
    }

    /**
     * A value as the timeline shows it; the commission in the unit of its own type, before or after.
     */
    private function display(string $field, mixed $value, ?CommissionType $commissionType): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match (true) {
            $value instanceof BackedEnum && method_exists($value, 'label') => (string) $value->label(),
            $value instanceof DateTimeInterface => Format::date($value),
            in_array($field, ['owner_id', 'approver_id'], true) => Crm::userName(Crm::userModel()::query()->whereKey($value)->first()) ?: null,
            $field === 'company_id' => Company::query()->whereKey($value)->value('name'),
            str_ends_with($field, '_cents') => Format::money(is_numeric($value) ? (int) $value : 0),
            $field === 'commission_value' => $commissionType === CommissionType::Percent
                ? Format::number((is_numeric($value) ? (int) $value : 0) / 100, 2).'%'
                : Format::money(is_numeric($value) ? (int) $value : 0),
            is_bool($value) => $value ? (string) __('sales-crm::ui.yes') : (string) __('sales-crm::ui.no'),
            default => mb_substr(is_scalar($value) ? (string) $value : '', 0, 200),
        };
    }
}
