<?php

namespace Sellinnate\SalesCrm\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Sellinnate\SalesCrm\Database\Factories\ActivityFactory;
use Sellinnate\SalesCrm\Enums\ActivityType;
use Sellinnate\SalesCrm\Models\Concerns\BelongsToWorkspace;
use Sellinnate\SalesCrm\Support\Crm;

/**
 * An activity (call, email, meeting, deadline, task) on a deal, a company or a person, with its due
 * date (with or without an hour), reminder, assignee and outcome once done.
 *
 * @property int $id
 * @property int|null $deal_id
 * @property int|null $company_id
 * @property int|null $person_id
 * @property ActivityType $type
 * @property string $title
 * @property string|null $notes
 * @property CarbonImmutable $due_at
 * @property bool $has_time
 * @property int|null $remind_minutes
 * @property CarbonImmutable|null $reminded_at
 * @property int|string|null $assigned_to
 * @property CarbonImmutable|null $done_at
 * @property int|string|null $done_by
 * @property string|null $outcome
 * @property int|string|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Deal|null $deal
 * @property-read Company|null $company
 * @property-read Person|null $person
 * @property-read Model|null $assignee
 * @property-read Model|null $doneBy
 */
class Activity extends CrmModel
{
    use BelongsToWorkspace;

    /** @use HasFactory<ActivityFactory> */
    use HasFactory;

    protected string $baseTable = 'activities';

    protected $fillable = [
        'workspace_type', 'workspace_id', 'deal_id', 'company_id', 'person_id', 'type', 'title', 'notes', 'due_at', 'has_time',
        'remind_minutes', 'reminded_at', 'assigned_to', 'done_at', 'done_by', 'outcome', 'created_by',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'has_time' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'deal_id' => 'integer',
            'company_id' => 'integer',
            'person_id' => 'integer',
            'type' => ActivityType::class,
            'due_at' => 'immutable_datetime',
            'has_time' => 'boolean',
            'remind_minutes' => 'integer',
            'reminded_at' => 'immutable_datetime',
            'done_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): ActivityFactory
    {
        return ActivityFactory::new();
    }

    /**
     * @return BelongsTo<Deal, $this>
     */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class, 'deal_id');
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'person_id');
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(Crm::userModel(), 'assigned_to');
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function doneBy(): BelongsTo
    {
        return $this->belongsTo(Crm::userModel(), 'done_by');
    }

    public function isDone(): bool
    {
        return $this->done_at !== null;
    }

    /**
     * Done, overdue, due today or planned: a deadline without an hour is due by the end of its day.
     */
    public function state(?CarbonImmutable $now = null): string
    {
        $now ??= CarbonImmutable::now();

        return match (true) {
            $this->done_at !== null => 'done',
            $this->deadline()->lt($now) => 'overdue',
            $this->due_at->isSameDay($now) => 'today',
            default => 'planned',
        };
    }

    /**
     * When the activity is late: at its hour, or at the end of its day when it has none.
     */
    public function deadline(): CarbonImmutable
    {
        return $this->has_time ? $this->due_at : $this->due_at->endOfDay();
    }

    /**
     * The moment the reminder is due, or null without a reminder.
     */
    public function remindAt(): ?CarbonImmutable
    {
        return $this->remind_minutes === null ? null : $this->due_at->subMinutes($this->remind_minutes);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull($this->qualifyColumn('done_at'));
    }

    /**
     * The open activities past their deadline (with `$late` false: the open ones still in time).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeLate(Builder $query, bool $late = true, ?CarbonImmutable $now = null): Builder
    {
        $now ??= CarbonImmutable::now();
        $hasTime = $this->qualifyColumn('has_time');
        $dueAt = $this->qualifyColumn('due_at');
        $condition = fn (Builder $past) => $past
            ->where(fn (Builder $timed) => $timed->where($hasTime, true)->where($dueAt, '<', $now))
            ->orWhere(fn (Builder $allDay) => $allDay->where($hasTime, false)->where($dueAt, '<', $now->startOfDay()));

        $query->whereNull($this->qualifyColumn('done_at'));

        return $late ? $query->where($condition) : $query->whereNot($condition);
    }

    /**
     * Seen with their deal (see BelongsToWorkspace::scopeVisibleTo()).
     */
    protected function followsDealVisibility(): bool
    {
        return true;
    }
}
