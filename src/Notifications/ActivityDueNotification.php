<?php

namespace Sellinnate\SalesCrm\Notifications;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Sellinnate\SalesCrm\Authorization\Access;
use Sellinnate\SalesCrm\Models\Activity;
use Sellinnate\SalesCrm\Support\Crm;
use Sellinnate\SalesCrm\Support\Format;

/**
 * The reminder of an activity, to its assignee. Delivered from the queue only while the activity is
 * still open, assigned to them, at the same time and with the same reminder, and they still work in
 * the workspace.
 */
class ActivityDueNotification extends CrmNotification
{
    public int|string|null $assignedTo;

    public string $dueAt;

    public ?int $remindMinutes;

    public function __construct(public Activity $activity)
    {
        parent::__construct();
        $this->assignedTo = $activity->assigned_to;
        $this->dueAt = $activity->due_at->toIso8601String();
        $this->remindMinutes = $activity->remind_minutes;
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        $current = Activity::query()->whereKey($this->activity->getKey())->whereNull('done_at')->first();

        return $notifiable instanceof Authenticatable
            && $current !== null
            && Crm::sameUser($current->assigned_to, Crm::userKey($notifiable))
            && Crm::sameUser($current->assigned_to, $this->assignedTo)
            && $current->due_at->equalTo(CarbonImmutable::parse($this->dueAt))
            && $current->remind_minutes === $this->remindMinutes
            && app(Access::class)->worksOn($notifiable, $current);
    }

    protected function title(): string
    {
        return (string) __('sales-crm::notifications.activity_due', ['type' => $this->activity->type->label(), 'title' => $this->activity->title]);
    }

    protected function line(): string
    {
        $due = $this->activity->has_time ? Format::dateTime($this->activity->due_at) : Format::date($this->activity->due_at);

        return (string) __('sales-crm::notifications.activity_due_line', ['due' => $due]).($this->activity->deal !== null ? ' · '.$this->activity->deal->title : '');
    }

    protected function url(): ?string
    {
        return $this->activity->deal_id !== null ? self::route('sales-crm.deals.show', ['deal' => $this->activity->deal_id]) : self::route('sales-crm.agenda');
    }

    protected function data(): array
    {
        return ['activity_id' => $this->activity->id, 'deal_id' => $this->activity->deal_id];
    }
}
