<?php

namespace Sellinnate\SalesCrm\Listeners;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Sellinnate\SalesCrm\Events\ActivityDue;
use Sellinnate\SalesCrm\Events\ApprovalRequested;
use Sellinnate\SalesCrm\Events\UserMentioned;
use Sellinnate\SalesCrm\Notifications\ActivityDueNotification;
use Sellinnate\SalesCrm\Notifications\ApprovalRequestedNotification;
use Sellinnate\SalesCrm\Notifications\MentionedNotification;
use Sellinnate\SalesCrm\Support\Crm;

/**
 * The default notifications of the CRM, sent from its events when `sales-crm.notifications.channels`
 * is not empty. Listen to the same events to notify in your own way.
 */
class SendNotifications
{
    public function subscribe(Dispatcher $events): void
    {
        $events->listen(ActivityDue::class, [self::class, 'activityDue']);
        $events->listen(UserMentioned::class, [self::class, 'mentioned']);
        $events->listen(ApprovalRequested::class, [self::class, 'approvalRequested']);
    }

    public function activityDue(ActivityDue $event): void
    {
        $assignee = $event->activity->assignee;

        if ($this->enabled() && $assignee !== null && method_exists($assignee, 'notify')) {
            $assignee->notify(new ActivityDueNotification($event->activity));
        }
    }

    public function mentioned(UserMentioned $event): void
    {
        if ($this->enabled() && method_exists($event->user, 'notify')) {
            $event->user->notify(new MentionedNotification($event->note, Crm::userName($event->by) ?: null));
        }
    }

    public function approvalRequested(ApprovalRequested $event): void
    {
        $approver = $event->deal->approver;

        if ($this->enabled() && $approver instanceof Model && method_exists($approver, 'notify')) {
            $approver->notify(new ApprovalRequestedNotification($event->deal, Crm::userName($event->by) ?: null));
        }
    }

    private function enabled(): bool
    {
        $channels = config('sales-crm.notifications.channels', []);

        return is_array($channels) && $channels !== [];
    }
}
