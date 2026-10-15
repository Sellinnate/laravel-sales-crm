---
title: "Activities & reminders"
description: "Plan calls, meetings, emails, deadlines and tasks on deals, companies and people, complete them with an outcome and get reminders on time."
---

# Activities & reminders

An **activity** is something someone has to do on a date: call Jane on
Tuesday at 10:30, send the proposal by Friday, meet the board next week. Every
activity has a type, a title, a due date and (optionally) a time, an assignee
and a reminder. Once done, it gets an **outcome** and moves to the timeline.

::: callout info "In plain words"
Activities are the CRM's to-do list. The board warns you about deals with no
next activity or with an overdue one, so nothing falls through the cracks.
:::

## Types

| Type | Value | Counts as a contact with the customer |
|---|---|---|
| Call | `call` | yes |
| Email | `email` | yes |
| Meeting | `meeting` | yes |
| Deadline | `deadline` | no |
| Task | `task` | no |

Completing a *contact* activity also sets the deal's **last contact** date.

## Plan an activity

Activities can be planned on a **deal**, a **company** or a **person**:

```php
use Sellinnate\SalesCrm\Facades\SalesCrm;

$activity = SalesCrm::scheduleActivity($deal, [
    'type'           => 'meeting',
    'title'          => 'Demo with the marketing team',
    'due_date'       => '2026-11-10',   // Y-m-d, required
    'due_time'       => '15:00',        // H:i, omit or null for "all day"
    'remind_minutes' => 60,             // one of sales-crm.activities.reminder_options
    'assigned_to'    => $colleague->id, // a user who works in the workspace
    'notes'          => 'Bring the case studies.',
    'person_id'      => $jane->id,      // deals only: who the activity is with
], $user);
```

| Key | Rules |
|---|---|
| `type` | required: `call`, `email`, `meeting`, `deadline`, `task` |
| `title` | required, ≤ 200 characters |
| `due_date` | required, `Y-m-d` |
| `due_time` | optional, `H:i` |
| `remind_minutes` | optional, one of `reminder_options` (default `0`, `15`, `60`, `1440`) |
| `assigned_to` | optional, key of a user who works in the workspace |
| `notes` | optional, ≤ 2000 characters |
| `person_id` | optional, a person of the same workspace |

**Default assignee** (when `assigned_to` is left out of a new activity): the
author when they work in the workspace, otherwise the deal's owner (if they
still work there), otherwise nobody. An explicit empty `assigned_to` leaves the
activity unassigned; changing an activity without `assigned_to` keeps its
assignee.

### All-day activities

Without `due_time`, an activity is **all day**: it becomes *overdue* only at the
end of its day, and its reminder is computed from
`sales-crm.activities.day_only_hour` (`09:00` by default). With
`remind_minutes => 1440` you get a reminder at 09:00 the day before.

## Complete an activity

```php
SalesCrm::completeActivity($activity, 'Positive: they want a proposal by Friday.', $user);
```

The outcome (≤ 2000 characters, optional) appears on the timeline. Completing a
call, email or meeting moves the deal's last contact to now.

### Other operations

Reopening, deleting and logging a call already made are available on the
action class used by the UI:

```php
use Sellinnate\SalesCrm\Actions\ManageActivities;

$activities = app(ManageActivities::class);

$activities->reopen($activity, $user);
$activities->delete($activity, $user);
$activities->logCall($deal, ['person_id' => $jane->id, 'outcome' => 'Left a voicemail'], $user);
$activities->save($deal, [...], $user, $activity);   // change an existing activity
```

## Activity states

```php
$activity->state();     // "done", "overdue", "today" or "planned"
$activity->isDone();
$activity->deadline();  // the due time, or the end of the day for all-day activities
$activity->remindAt();  // when the reminder is due, or null

use Sellinnate\SalesCrm\Models\Activity;

Activity::query()->visibleTo($user)->open()->where('assigned_to', $user->id)->orderBy('due_at')->get();
Activity::query()->visibleTo($user)->late()->get();   // open and past their deadline
```

Each deal keeps `next_activity_at` (the earliest open activity) and
`last_activity_at` up to date, so the board can show "no next activity",
"overdue" and "stale" without extra queries.

## Reminders {#reminders}

Reminders are sent by a command that must run **every minute**:

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('sales-crm:remind-activities')->everyMinute();
```

On each run the command looks for open activities with a reminder, an assignee
and a reminder time that has come, and for each one:

1. marks it as reminded (so two overlapping runs never send it twice);
2. skips it if the reminder is older than `sales-crm.activities.stale_minutes`
   (60 by default; the agenda still shows it) or if the assignee no longer
   works in the workspace;
3. fires the `ActivityDue` event.

By default `ActivityDue` sends the **assignee** an `ActivityDueNotification` on
the channels of `sales-crm.notifications.channels`. The notification is queued
and checks again, when delivered, that the activity is still open, still
assigned to them, at the same time and with the same reminder.

Changing an activity's time, reminder or assignee re-arms its reminder.

::: callout tip "Your own reminder channel"
Set `sales-crm.notifications.channels` to `[]` and listen to `ActivityDue` to
send reminders your way (Slack, push, SMS):

```php
use Illuminate\Support\Facades\Event;
use Sellinnate\SalesCrm\Events\ActivityDue;

Event::listen(function (ActivityDue $event) {
    $activity = $event->activity;
    // $activity->assignee, $activity->deal, $activity->title, $activity->due_at
});
```
:::

## The agenda

The **Agenda** page of the UI lists the current user's activities overdue, due
today and in the next seven days, each with a link to its deal, company or
person and a one-click "done". The navigation shows a counter of activities due
today or overdue.

## Events

| Event | When |
|---|---|
| `ActivityCreated` | An activity is planned (`$by` is `null` when created by a stage automation). |
| `ActivityCompleted` | An activity is marked done. |
| `ActivityDue` | Its reminder is due (fired by the command). |

See the [Events reference](/reference/events).

## Best practices

- **Plan the next activity before leaving a deal.** The "no activity" quick
  filter finds open deals without one.
- **Write an outcome** when completing: it's what colleagues read on the
  timeline.
- **Use stage automation** for follow-ups that always happen (a call three days
  after the proposal).

## Common pitfalls

- `remind_minutes` not in `reminder_options`: validation error. Add the value to
  the config if you need it.
- Reminders never arrive: the scheduler isn't running the command, the activity
  has no assignee, or there is no queue worker for the notifications.
- `due_date` in another format (`10/11/2026`): it must be `Y-m-d`.
