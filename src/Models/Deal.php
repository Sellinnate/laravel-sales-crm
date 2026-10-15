<?php

namespace Sellinnate\SalesCrm\Models;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Sellinnate\SalesCrm\Authorization\Access;
use Sellinnate\SalesCrm\Database\Factories\DealFactory;
use Sellinnate\SalesCrm\Enums\ApprovalStatus;
use Sellinnate\SalesCrm\Enums\CommissionType;
use Sellinnate\SalesCrm\Enums\DealSource;
use Sellinnate\SalesCrm\Enums\LostReason;
use Sellinnate\SalesCrm\Enums\PaymentStatus;
use Sellinnate\SalesCrm\Enums\Priority;
use Sellinnate\SalesCrm\Enums\RecurringPeriod;
use Sellinnate\SalesCrm\Enums\SignatureStatus;
use Sellinnate\SalesCrm\Models\Concerns\BelongsToWorkspace;
use Sellinnate\SalesCrm\Support\Crm;

/**
 * A deal: the central object of the CRM, in a stage of a pipeline, with its company and people,
 * amounts and offer, operational state, signature and payment with evidence, internal approval,
 * closing and commission.
 *
 * @property int $id
 * @property string $workspace_key
 * @property int $pipeline_id
 * @property int $stage_id
 * @property int $position
 * @property string $title
 * @property int|null $company_id
 * @property int|string|null $owner_id
 * @property Priority|null $priority
 * @property list<string>|null $tags
 * @property DealSource $source
 * @property string|null $origin_type
 * @property string|null $origin_id
 * @property string|null $external_ref
 * @property int|null $amount_once_cents
 * @property int|null $deposit_cents
 * @property int|null $recurring_cents
 * @property RecurringPeriod|null $recurring_period
 * @property int $first_year_value_cents
 * @property string|null $recurring_options
 * @property string|null $payment_terms
 * @property string|null $offer_details
 * @property CarbonImmutable|null $offer_valid_until
 * @property CarbonImmutable|null $expected_close_date
 * @property string|null $sub_status
 * @property string|null $next_step
 * @property string|null $blocker
 * @property string|null $missing_data
 * @property string|null $call_context
 * @property string|null $closing_tip
 * @property bool $approval_required
 * @property ApprovalStatus|null $approval_status
 * @property int|string|null $approver_id
 * @property string|null $approval_note
 * @property CarbonImmutable|null $approval_decided_at
 * @property SignatureStatus $signature_status
 * @property string|null $signature_evidence
 * @property CarbonImmutable|null $signature_verified_at
 * @property int|string|null $signature_verified_by
 * @property PaymentStatus $payment_status
 * @property int|null $amount_collected_cents
 * @property string|null $payment_evidence
 * @property CarbonImmutable|null $payment_verified_at
 * @property int|string|null $payment_verified_by
 * @property LostReason|null $lost_reason
 * @property string|null $lost_note
 * @property CarbonImmutable|null $won_at
 * @property CarbonImmutable|null $lost_at
 * @property CommissionType|null $commission_type
 * @property int|null $commission_value
 * @property string|null $commission_note
 * @property array<string, mixed>|null $custom_values
 * @property CarbonImmutable|null $stage_entered_at
 * @property CarbonImmutable|null $last_activity_at
 * @property CarbonImmutable|null $next_activity_at
 * @property CarbonImmutable|null $last_contacted_at
 * @property CarbonImmutable|null $data_verified_at
 * @property int|string|null $created_by
 * @property int|string|null $updated_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Pipeline $pipeline
 * @property-read Stage $stage
 * @property-read Company|null $company
 * @property-read Model|null $owner
 * @property-read Model|null $approver
 * @property-read Collection<int, Person> $people
 * @property-read Collection<int, DealEvent> $events
 * @property-read Collection<int, StageVisit> $stageVisits
 * @property-read Collection<int, Activity> $activities
 * @property-read Collection<int, Note> $notes
 * @property-read Collection<int, Document> $documents
 * @property-read Collection<int, Email> $emails
 */
class Deal extends CrmModel
{
    use BelongsToWorkspace;

    /** @use HasFactory<DealFactory> */
    use HasFactory;

    protected string $baseTable = 'deals';

    public bool $hasWorkspaceKey = true;

    protected $fillable = [
        'workspace_type', 'workspace_id', 'pipeline_id', 'stage_id', 'position', 'title', 'company_id', 'owner_id', 'priority', 'tags',
        'source', 'origin_type', 'origin_id', 'external_ref',
        'amount_once_cents', 'deposit_cents', 'recurring_cents', 'recurring_period', 'recurring_options', 'payment_terms',
        'offer_details', 'offer_valid_until', 'expected_close_date', 'sub_status', 'next_step', 'blocker', 'missing_data',
        'call_context', 'closing_tip', 'approval_required', 'approval_status', 'approver_id', 'approval_note',
        'approval_decided_at', 'signature_status', 'signature_evidence', 'signature_verified_at', 'signature_verified_by',
        'payment_status', 'amount_collected_cents', 'payment_evidence', 'payment_verified_at', 'payment_verified_by',
        'lost_reason', 'lost_note', 'won_at', 'lost_at', 'commission_type', 'commission_value', 'commission_note',
        'custom_values', 'stage_entered_at', 'last_activity_at', 'next_activity_at', 'last_contacted_at', 'data_verified_at',
        'created_by', 'updated_by',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'position' => 0,
        'source' => 'other',
        'first_year_value_cents' => 0,
        'approval_required' => false,
        'signature_status' => 'not_verified',
        'payment_status' => 'not_verified',
    ];

    /**
     * The value of the first year is stored, so board totals and sorting stay in SQL.
     */
    protected static function booted(): void
    {
        static::saving(function (Deal $deal): void {
            $deal->first_year_value_cents = $deal->computeFirstYearValue();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pipeline_id' => 'integer',
            'stage_id' => 'integer',
            'company_id' => 'integer',
            'position' => 'integer',
            'priority' => Priority::class,
            'tags' => 'array',
            'source' => DealSource::class,
            'amount_once_cents' => 'integer',
            'deposit_cents' => 'integer',
            'recurring_cents' => 'integer',
            'recurring_period' => RecurringPeriod::class,
            'first_year_value_cents' => 'integer',
            'offer_valid_until' => 'immutable_date',
            'expected_close_date' => 'immutable_date',
            'approval_required' => 'boolean',
            'approval_status' => ApprovalStatus::class,
            'approval_decided_at' => 'immutable_datetime',
            'signature_status' => SignatureStatus::class,
            'signature_verified_at' => 'immutable_datetime',
            'payment_status' => PaymentStatus::class,
            'amount_collected_cents' => 'integer',
            'payment_verified_at' => 'immutable_datetime',
            'lost_reason' => LostReason::class,
            'won_at' => 'immutable_datetime',
            'lost_at' => 'immutable_datetime',
            'commission_type' => CommissionType::class,
            'commission_value' => 'integer',
            'custom_values' => 'array',
            'stage_entered_at' => 'immutable_datetime',
            'last_activity_at' => 'immutable_datetime',
            'next_activity_at' => 'immutable_datetime',
            'last_contacted_at' => 'immutable_datetime',
            'data_verified_at' => 'immutable_date',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): DealFactory
    {
        return DealFactory::new();
    }

    /**
     * @return BelongsTo<Pipeline, $this>
     */
    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class, 'pipeline_id');
    }

    /**
     * @return BelongsTo<Stage, $this>
     */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(Stage::class, 'stage_id');
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Crm::userModel(), 'owner_id');
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(Crm::userModel(), 'approver_id');
    }

    /**
     * The record of the host the deal was born from (an appointment, an order, a form entry…).
     *
     * @return MorphTo<Model, $this>
     */
    public function origin(): MorphTo
    {
        return $this->morphTo('origin');
    }

    /**
     * The people of the deal with their role; the primary one first.
     *
     * @return BelongsToMany<Person, $this>
     */
    public function people(): BelongsToMany
    {
        $pivot = Crm::table('deal_person');

        return $this->belongsToMany(Person::class, $pivot, 'deal_id', 'person_id')
            ->withPivot(['role', 'is_primary'])
            ->withTimestamps()
            ->orderByDesc($pivot.'.is_primary')
            ->orderBy($pivot.'.id');
    }

    /**
     * @return HasMany<DealEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(DealEvent::class, 'deal_id');
    }

    /**
     * @return HasMany<StageVisit, $this>
     */
    public function stageVisits(): HasMany
    {
        return $this->hasMany(StageVisit::class, 'deal_id');
    }

    /**
     * @return HasMany<Activity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class, 'deal_id');
    }

    /**
     * @return HasMany<Note, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(Note::class, 'deal_id');
    }

    /**
     * @return HasMany<Document, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'deal_id');
    }

    /**
     * @return HasMany<Email, $this>
     */
    public function emails(): HasMany
    {
        return $this->hasMany(Email::class, 'deal_id');
    }

    /**
     * The primary person, or the first one, from the loaded people.
     */
    public function primaryPerson(): ?Person
    {
        return $this->people->first();
    }

    /**
     * The value of the first year in cents: the one-off amount plus the recurring one billed over a year.
     */
    public function computeFirstYearValue(): int
    {
        $recurring = $this->recurring_cents !== null && $this->recurring_period !== null
            ? $this->recurring_cents * $this->recurring_period->timesPerYear()
            : 0;

        return (int) ($this->amount_once_cents ?? 0) + $recurring;
    }

    /**
     * Whether the deal has an amount at all (a deal without one has no value, not «0»).
     */
    public function isPriced(): bool
    {
        return $this->amount_once_cents !== null || $this->recurring_cents !== null;
    }

    /**
     * The monthly recurring revenue in cents.
     */
    public function mrrCents(): ?int
    {
        if ($this->recurring_cents === null || $this->recurring_period === null) {
            return null;
        }

        return (int) round($this->recurring_cents * $this->recurring_period->timesPerYear() / 12);
    }

    /**
     * The annual recurring revenue in cents.
     */
    public function arrCents(): ?int
    {
        return $this->recurring_cents === null || $this->recurring_period === null
            ? null
            : $this->recurring_cents * $this->recurring_period->timesPerYear();
    }

    /**
     * The value of the first year weighted by the probability of the stage.
     */
    public function weightedCents(): int
    {
        return (int) round($this->first_year_value_cents * $this->stage->probability / 100);
    }

    /**
     * The commission in cents: the fixed amount, or the percent of the first-year value.
     */
    public function commissionCents(): ?int
    {
        return match ($this->commission_type) {
            CommissionType::Amount => $this->commission_value,
            CommissionType::Percent => $this->commission_value === null ? null : (int) round($this->first_year_value_cents * $this->commission_value / 10000),
            null => null,
        };
    }

    public function isOpen(): bool
    {
        return $this->won_at === null && $this->lost_at === null;
    }

    public function isWon(): bool
    {
        return $this->won_at !== null;
    }

    public function isLost(): bool
    {
        return $this->lost_at !== null;
    }

    /**
     * The state of the next activity: overdue, today, planned or none (a warning for an open deal).
     */
    public function nextActivityState(?CarbonImmutable $now = null): string
    {
        $now ??= CarbonImmutable::now();

        return match (true) {
            $this->next_activity_at === null => 'none',
            $this->next_activity_at->lt($now) => 'overdue',
            $this->next_activity_at->isSameDay($now) => 'today',
            default => 'planned',
        };
    }

    /**
     * Whether the next activity has an hour: a whole-day one is due at the end of its day.
     */
    public function nextActivityHasTime(): bool
    {
        return $this->next_activity_at !== null && $this->next_activity_at->format('H:i:s') !== '23:59:59';
    }

    /**
     * Whether the offer of an open deal is expired or about to expire.
     */
    public function offerState(?CarbonImmutable $today = null): ?string
    {
        if ($this->offer_valid_until === null || ! $this->isOpen()) {
            return null;
        }

        $today = ($today ?? CarbonImmutable::now())->startOfDay();
        $days = max(0, (int) config('sales-crm.deals.offer_expiring_days', 7));

        return match (true) {
            $this->offer_valid_until->lt($today) => 'expired',
            $this->offer_valid_until->lte($today->addDays($days)) => 'expiring',
            default => null,
        };
    }

    /**
     * Whole days spent in the current stage.
     */
    public function daysInStage(?CarbonImmutable $now = null): int
    {
        $since = $this->stage_entered_at ?? $this->created_at;

        return $since === null ? 0 : (int) $since->startOfDay()->diffInDays(($now ?? CarbonImmutable::now())->startOfDay());
    }

    /**
     * The idle days of an open deal beyond the rotting days of its stage, or null when not stale.
     */
    public function staleDays(?CarbonImmutable $now = null): ?int
    {
        $rotting = $this->stage->rotting_days;
        $since = $this->last_activity_at ?? $this->stage_entered_at ?? $this->created_at;

        if (! $this->isOpen() || $rotting === null || $since === null) {
            return null;
        }

        $idle = (int) $since->startOfDay()->diffInDays(($now ?? CarbonImmutable::now())->startOfDay());

        return $idle > $rotting ? $idle : null;
    }

    /**
     * Scope to the deals the user sees: their workspaces and, with owner visibility, their own.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleTo(Builder $query, ?Authenticatable $user): Builder
    {
        app(Access::class)->constrainDeals($query, $user);

        return $query;
    }

    /**
     * Scope to the deals neither won nor lost.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull($this->qualifyColumn('won_at'))->whereNull($this->qualifyColumn('lost_at'));
    }
}
