<?php

namespace Sellinnate\SalesCrm\Actions;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Sellinnate\SalesCrm\Authorization\Access;
use Sellinnate\SalesCrm\Enums\ActivityType;
use Sellinnate\SalesCrm\Events\ActivityCompleted;
use Sellinnate\SalesCrm\Events\ActivityCreated;
use Sellinnate\SalesCrm\Models\Activity;
use Sellinnate\SalesCrm\Models\Company;
use Sellinnate\SalesCrm\Models\Deal;
use Sellinnate\SalesCrm\Models\Person;
use Sellinnate\SalesCrm\Services\DealActivityState;
use Sellinnate\SalesCrm\Support\Crm;
use Sellinnate\SalesCrm\Workspaces\Workspace;

/**
 * The activities of the CRM: plan one on a deal, a company or a person, change it, mark it done with
 * its outcome or open it again, delete it, and log a call already made. The next and the last
 * activity of the deal follow ({@see DealActivityState}). Without an author the activity comes from
 * the automation of a stage.
 */
class ManageActivities
{
    public function __construct(private DealActivityState $state, private Access $access) {}

    /**
     * Plan or change an activity.
     *
     * @param  array<string, mixed>  $data  type, title, due_date (Y-m-d), due_time (H:i, empty for the whole day), remind_minutes, assigned_to, notes, person_id
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function save(Deal|Company|Person $target, array $data, ?Authenticatable $by, ?Activity $activity = null): Activity
    {
        if ($by !== null) {
            Gate::forUser($by)->authorize('update', $target);

            // The activity itself too: an activity of a hidden deal may also name a person the user sees.
            if ($activity !== null) {
                Gate::forUser($by)->authorize('update', $activity);
            }
        }

        if ($activity !== null && ! $this->belongsTo($activity, $target)) {
            throw ValidationException::withMessages(['activity' => __('sales-crm::validation.activity_target')]);
        }

        $workspace = $target->workspaceRef();
        $data = array_map(fn (mixed $value): mixed => is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value, $data);
        $people = Rule::exists(Person::class, 'id');
        $workspace === null ? $people->whereNull('workspace_type') : $people->where('workspace_type', $workspace->type)->where('workspace_id', $workspace->id);

        /** @var array{type: string, title: string, due_date: string, due_time?: string|null, remind_minutes?: int|string|null, assigned_to?: int|string|null, notes?: string|null, person_id?: int|string|null} $validated */
        $validated = Validator::make($data, [
            'type' => ['required', Rule::enum(ActivityType::class)],
            'title' => ['required', 'string', 'max:200'],
            'due_date' => ['required', 'date_format:Y-m-d'],
            'due_time' => ['nullable', 'date_format:H:i'],
            'remind_minutes' => ['nullable', 'integer', Rule::in(self::reminderOptions())],
            'assigned_to' => ['nullable', $this->memberOf($workspace)],
            'notes' => ['nullable', 'string', 'max:2000'],
            'person_id' => ['nullable', 'integer', $people],
        ], [], self::attributes())->validate();

        $hasTime = filled($validated['due_time'] ?? null);
        // «!»: zero seconds, so an activity saved again at the same hour keeps its deadline and reminder.
        $dueAt = CarbonImmutable::createFromFormat('!Y-m-d H:i', $validated['due_date'].' '.($hasTime ? $validated['due_time'] : self::dayOnlyHour()));
        // A new activity defaults to its author; a change keeps its assignee unless told otherwise
        // (an explicit empty value leaves it unassigned).
        $assignee = match (true) {
            filled($validated['assigned_to'] ?? null) => $validated['assigned_to'],
            $activity === null => $this->defaultAssignee($target, $by),
            array_key_exists('assigned_to', $data) => null,
            default => $activity->assigned_to,
        };

        return Crm::db()->transaction(function () use ($target, $validated, $by, $activity, $hasTime, $dueAt, $assignee): Activity {
            $created = $activity === null;
            $activity ??= (new Activity([...$this->targetColumns($target), 'created_by' => Crm::userKey($by)]))->setWorkspace($target->workspaceRef());

            $activity->fill([
                'type' => $validated['type'],
                'title' => $validated['title'],
                'notes' => $validated['notes'] ?? null,
                'due_at' => $dueAt,
                'has_time' => $hasTime,
                'remind_minutes' => isset($validated['remind_minutes']) ? (int) $validated['remind_minutes'] : null,
                'assigned_to' => $assignee,
            ]);

            if ($target instanceof Deal && array_key_exists('person_id', $validated)) {
                $activity->person_id = isset($validated['person_id']) ? (int) $validated['person_id'] : null;
            }

            // A new time, reminder or assignee: the reminder goes again.
            if ($activity->isDirty(['due_at', 'remind_minutes', 'assigned_to'])) {
                $activity->reminded_at = null;
            }

            $activity->save();
            $this->refreshDeal($activity);

            if ($created) {
                ActivityCreated::dispatch($activity, $by);
            }

            return $activity;
        });
    }

    /**
     * Mark the activity done, with its outcome; a call, an email or a meeting is also the last contact.
     *
     * @throws AuthorizationException
     */
    public function complete(Activity $activity, ?string $outcome, Authenticatable $by): Activity
    {
        Gate::forUser($by)->authorize('update', $activity);

        return Crm::db()->transaction(function () use ($activity, $outcome, $by): Activity {
            $now = CarbonImmutable::now();

            $activity->forceFill([
                'done_at' => $now,
                'done_by' => Crm::userKey($by),
                'outcome' => filled($outcome) ? mb_substr(trim((string) $outcome), 0, 2000) : null,
            ])->save();

            $this->refreshDeal($activity, $now, $activity->type->isContact() ? $now : null);

            ActivityCompleted::dispatch($activity, $by);

            return $activity;
        });
    }

    /**
     * Open a done activity again.
     *
     * @throws AuthorizationException
     */
    public function reopen(Activity $activity, Authenticatable $by): Activity
    {
        Gate::forUser($by)->authorize('update', $activity);

        return Crm::db()->transaction(function () use ($activity): Activity {
            $activity->forceFill(['done_at' => null, 'done_by' => null, 'reminded_at' => null])->save();
            $this->refreshDeal($activity);

            return $activity;
        });
    }

    /**
     * @throws AuthorizationException
     */
    public function delete(Activity $activity, Authenticatable $by): void
    {
        Gate::forUser($by)->authorize('delete', $activity);

        Crm::db()->transaction(function () use ($activity): void {
            $activity->delete();
            $this->refreshDeal($activity);
        });
    }

    /**
     * Log a call already made from the deal: a call activity done now, with its outcome.
     *
     * @param  array{person_id?: int|string|null, outcome?: string|null}  $data
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function logCall(Deal $deal, array $data, Authenticatable $by): Activity
    {
        $person = filled($data['person_id'] ?? null) ? Person::query()->inWorkspace($deal->workspaceRef())->find((int) $data['person_id']) : null;
        $now = CarbonImmutable::now();

        if (blank($data['outcome'] ?? null)) {
            throw ValidationException::withMessages(['outcome' => __('sales-crm::validation.call_outcome')]);
        }

        return Crm::db()->transaction(function () use ($deal, $data, $by, $person, $now): Activity {
            $activity = $this->save($deal, [
                'type' => ActivityType::Call->value,
                'title' => Str::limit($person !== null ? (string) __('sales-crm::ui.call_with', ['name' => $person->name]) : (string) __('sales-crm::ui.call'), 199, '…'),
                'due_date' => $now->toDateString(),
                'due_time' => $now->format('H:i'),
                'assigned_to' => Crm::userKey($by),
                'person_id' => $person?->id,
            ], $by);

            return $this->complete($activity, (string) $data['outcome'], $by);
        });
    }

    /**
     * The reminders a user may choose, in minutes before the due time.
     *
     * @return list<int>
     */
    public static function reminderOptions(): array
    {
        $options = config('sales-crm.activities.reminder_options', [0, 15, 60, 1440]);

        return array_values(array_map(intval(...), is_array($options) ? $options : [0, 15, 60, 1440]));
    }

    /**
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        $attributes = [];

        foreach (['type', 'title', 'due_date', 'due_time', 'remind_minutes', 'assigned_to', 'notes', 'person_id'] as $field) {
            $attributes[$field] = mb_strtolower((string) __('sales-crm::fields.activity.'.$field));
        }

        return $attributes;
    }

    private static function dayOnlyHour(): string
    {
        $hour = (string) config('sales-crm.activities.day_only_hour', '09:00');

        return preg_match('/^\d{2}:\d{2}$/', $hour) === 1 ? $hour : '09:00';
    }

    private function refreshDeal(Activity $activity, ?CarbonImmutable $activityAt = null, ?CarbonImmutable $contactAt = null): void
    {
        if ($activity->deal_id !== null) {
            $this->state->refresh($activity->deal_id, $activityAt, $contactAt);
        }
    }

    /**
     * The author when they work in the workspace, else the owner of the deal while they still do, else nobody.
     */
    private function defaultAssignee(Deal|Company|Person $target, ?Authenticatable $by): int|string|null
    {
        $workspace = $target->workspaceRef();

        if ($by !== null && $this->access->isMember($by, $workspace)) {
            return Crm::userKey($by);
        }

        $owner = $target instanceof Deal ? $target->owner_id : null;

        return $owner !== null && $this->access->isMember($owner, $workspace) ? $owner : null;
    }

    /**
     * @return array{deal_id?: int, company_id?: int, person_id?: int}
     */
    private function targetColumns(Deal|Company|Person $target): array
    {
        return match (true) {
            $target instanceof Deal => ['deal_id' => $target->id],
            $target instanceof Company => ['company_id' => $target->id],
            default => ['person_id' => $target->id],
        };
    }

    private function belongsTo(Activity $activity, Deal|Company|Person $target): bool
    {
        return match (true) {
            $target instanceof Deal => $activity->deal_id === $target->id,
            $target instanceof Company => $activity->company_id === $target->id,
            default => $activity->person_id === $target->id,
        };
    }

    private function memberOf(?Workspace $workspace): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($workspace): void {
            if ($value !== null && $value !== '' && (! is_scalar($value) || ! $this->access->isMember((string) $value, $workspace))) {
                $fail((string) __('sales-crm::validation.member'));
            }
        };
    }
}
