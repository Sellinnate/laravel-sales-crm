---
title: "Events & notifications"
description: "React to what happens in the CRM with Laravel events (dispatched after the database commit) and configure or replace the built-in notifications."
---

# Events & notifications

Everything that happens in the CRM fires a Laravel **event**: a deal created,
moved, won or lost, an activity due, an email received… Listen to them to sync
another system, post to Slack, update a dashboard or trigger your own
automation. On top of three of them the CRM sends **notifications** to your
users.

::: callout info "In plain words"
An *event* is a message the CRM broadcasts inside your app ("deal 42 was
won"). A *listener* is your code that reacts to it. You never need to modify
the package to add behaviour: you listen.
:::

## Dispatched after the commit

Every CRM event implements Laravel's `ShouldDispatchAfterCommit`. When the
action runs inside a database transaction (they all do), the event is
delivered **only after the transaction commits**. Your listener never sees a
deal that is then rolled back, and the data it reads is already saved.

## Listening

```php
// app/Providers/AppServiceProvider.php
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Sellinnate\SalesCrm\Events\DealWon;

public function boot(): void
{
    Event::listen(function (DealWon $event) {
        $deal = $event->deal;

        Log::info(sprintf(
            '%s won "%s" (%s)',
            $event->by?->name ?? 'The system',
            $deal->title,
            number_format($deal->first_year_value_cents / 100, 2),
        ));
    });
}
```

Or with a listener class, queued if the work is slow:

```php
namespace App\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Sellinnate\SalesCrm\Events\DealStageChanged;

class SyncDealToErp implements ShouldQueue
{
    public function handle(DealStageChanged $event): void
    {
        // $event->deal, $event->from, $event->to, $event->by
    }
}
```

Laravel's event discovery picks up listeners in `app/Listeners` automatically;
otherwise register them with `Event::listen(DealStageChanged::class, SyncDealToErp::class)`.

Most events carry `$by`: the user who acted, or `null` when the system did
(an automation, an import job, `createDealFrom()` without a user, the
reminders command).

## The events at a glance

| Area | Events |
|---|---|
| Deals | `DealCreated`, `DealUpdated`, `DealStageChanged`, `DealWon`, `DealLost`, `DealReopened`, `DealDeleted` |
| Approval | `ApprovalRequested`, `ApprovalDecided` |
| Contacts | `CompanyCreated`, `PersonCreated` |
| Activities | `ActivityCreated`, `ActivityCompleted`, `ActivityDue` |
| Notes & documents | `NoteAdded`, `UserMentioned`, `DocumentUploaded` |
| Email | `EmailQueued`, `EmailSent`, `EmailFailed`, `EmailReceived` |
| Import | `ImportFinished` |

All live in `Sellinnate\SalesCrm\Events`. The
[Events reference](/reference/events) lists each one with its properties and
when it fires.

::: callout tip "A move fires two events"
Moving a deal to a won stage fires `DealStageChanged` **and** `DealWon`. Listen
to `DealStageChanged` for "any move", to `DealWon`/`DealLost`/`DealReopened`
for outcomes.
:::

## Built-in notifications

The package subscribes a listener (`SendNotifications`) that turns three events
into notifications for your users:

| Event | Notification | Sent to |
|---|---|---|
| `ActivityDue` | `ActivityDueNotification` | the activity's assignee |
| `UserMentioned` | `MentionedNotification` | the mentioned user |
| `ApprovalRequested` | `ApprovalRequestedNotification` | the deal's approver |

They are **queued** (on `sales-crm.notifications.queue`), sent after the commit,
and each re-checks when delivered that it still makes sense (the activity is
still open and assigned to that user, the approval is still pending and theirs,
the user still works in the workspace). The texts of notes and emails are
**never** included: only a title, a line and a link to the record.

They use the channels in `sales-crm.notifications.channels` (default
`['database', 'mail']`). Your user model needs Laravel's `Notifiable` trait
(it's in the default `User` model), and the `database` channel needs the
notifications table (`php artisan make:notifications-table`).

The database payload contains `title`, `description`, `url` and the related ids
(`activity_id`, `deal_id` or `note_id`), handy for an in-app notification
bell.

### Changing or replacing them

- **Mail only**: `'channels' => ['mail']`.
- **No built-in notifications**: `'channels' => []`, then listen to the events
  and notify your way:

```php
use Illuminate\Support\Facades\Event;
use Sellinnate\SalesCrm\Events\UserMentioned;
use App\Notifications\CrmMentionOnSlack;

Event::listen(function (UserMentioned $event) {
    $event->user->notify(new CrmMentionOnSlack($event->note, $event->by));
});
```

- **Translations**: the notification texts are in the `notifications` file of
  the package translations (`php artisan vendor:publish --tag=sales-crm-translations`).

## Best practices

- **Queue slow listeners** (`ShouldQueue`): CRM events are dispatched in the
  user's request.
- **Re-read the model** in queued listeners if you need the latest state; the
  event carries the model as it was when dispatched (serialized by id).
- **Use `DealDeleted::$attributes`** to know what was deleted: the model no
  longer exists.

## Common pitfalls

- Listening to Eloquent model events (`Deal::created`) instead of CRM events:
  they fire inside the transaction and also for internal writes, without the
  acting user.
- Expecting `CompanyCreated`/`PersonCreated` when a deal or an import only
  *matches* an existing company or person: they fire for new records only.
- Missing notifications table with the default channels: the queued
  notifications fail.
