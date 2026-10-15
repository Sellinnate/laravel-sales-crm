---
title: "Deals"
description: "The central object of the CRM: creating, updating, moving and deleting deals, every accepted field, approval, evidence rules and deals born from your own records."
---

# Deals

A **deal** is a sale you're working on: "Website redesign for Acme, 12,000 €,
proposal sent, Jane Doe is the contact". It sits in one stage of one pipeline,
belongs to a company, has people, an owner, amounts, activities, notes,
documents, emails and a full history.

::: callout info "In plain words"
In other CRMs this is called an *opportunity*. One customer can have many deals
over time (a first project, a renewal, an upsell): the company is the customer,
the deal is one sale.
:::

## Create a deal

```php
use Sellinnate\SalesCrm\Facades\SalesCrm;

$deal = SalesCrm::for($team)->createDeal([
    'title'        => 'Annual maintenance',
    'company_name' => 'Northwind Traders',
    'amount_once'  => 2000,
    'recurring'    => 150,
    'recurring_period' => 'monthly',
    'priority'     => 'p2',
    'tags'         => ['renewal'],
    'person'       => ['first_name' => 'Laura', 'last_name' => 'Bianchi', 'email' => 'laura@northwind.example', 'role' => 'decision_maker'],
    'next_activity' => ['type' => 'call', 'title' => 'Kick-off call', 'due_date' => '2026-11-03', 'due_time' => '11:00'],
], by: $user);
```

What `createDeal()` does:

- Puts the deal in `pipeline_id` (default: the workspace's first pipeline,
  created if needed) at stage `stage_id` (default: the **first open stage**), on
  top of its column.
- Matches or creates the company from `company_name` (or uses `company_id`).
- Matches or creates the `person` and makes them the **primary contact**.
- Plans `next_activity` if given.
- Sets the **owner** to the author when they work in the workspace, unless you
  pass `owner_id` (or `owner_id => null` for no owner).
- Checks the stage's [required fields](/concepts/pipelines#required-fields).
- Records the history and fires `DealCreated`.

Without `$by` the deal is created by **the system**: no authorization, no
owner by default.

## Accepted fields {#fields}

All keys are optional except `title`. Only the keys you pass are validated and
written. **Amounts are in major units** (`1500` = 1,500.00) and stored as
cents.

### Core

| Key | Type / values |
|---|---|
| `title` | **required**, string ≤ 200 |
| `company_id` | id of a company of the same workspace |
| `company_name` | string ≤ 200: matched against existing companies, created if new |
| `owner_id` | key of a user who works in the workspace |
| `priority` | `p1`, `p2`, `p3`, `p4`, `excluded` |
| `source` | `inbound`, `outbound`, `referral`, `email`, `event`, `partner`, `website`, `integration`, `import`, `other` (default `other`) |
| `tags` | array of strings ≤ 30 chars, at most `sales-crm.deals.max_tags` |
| `external_ref` | string ≤ 64, **unique per workspace** (your own reference: an order number, an id in another system) |
| `custom_values` | array `key => value` of the workspace's [custom fields](#custom-fields) |

### Amounts and offer

| Key | Type / values |
|---|---|
| `amount_once` | number ≥ 0: one-off amount |
| `deposit` | number ≥ 0 |
| `recurring` | number ≥ 0: recurring amount |
| `recurring_period` | `monthly` or `yearly` (defaults to `yearly` when `recurring` is set) |
| `recurring_options` | text ≤ 2000 |
| `payment_terms` | text ≤ 2000 |
| `offer_details` | text ≤ 2000 |
| `offer_valid_until` | date |
| `expected_close_date` | date |

### Operational state

| Key | Type / values |
|---|---|
| `sub_status` | string ≤ 160: a free status inside the stage ("waiting for the CFO") |
| `next_step` | text ≤ 2000 |
| `blocker` | text ≤ 2000: what is stopping the deal |
| `missing_data` | text ≤ 2000 |
| `call_context` | text ≤ 2000: context for the next call |
| `closing_tip` | text ≤ 2000 |
| `last_contacted_at` | date: also moves the deal's last activity (never to a future date) |
| `data_verified_at` | date |

### Signature, payment, approval, commission, closing

| Key | Type / values |
|---|---|
| `signature_status` | `not_verified`, `to_sign`, `signed` |
| `signature_evidence` | string ≤ 500 (a link or reference) |
| `payment_status` | `not_verified`, `expected`, `partial`, `paid` |
| `amount_collected` | number ≥ 0 |
| `payment_evidence` | string ≤ 500 |
| `approval_required` | boolean |
| `approver_id` | key of a user who works in the workspace |
| `approval_status` | `pending`, `approved`, `rejected` |
| `approval_note` | string ≤ 1000 |
| `commission_type` | `amount` or `percent` |
| `commission_value` | number: an amount, or a percentage (≤ 100) |
| `commission_note` | string ≤ 255 |
| `lost_reason` | `price`, `timing`, `competitor`, `no_response`, `no_budget`, `not_in_target`, `postponed`, `other` |
| `lost_note` | string ≤ 1000 |

### Extra keys of `createDeal()`

| Key | Meaning |
|---|---|
| `pipeline_id`, `stage_id` | Where the deal starts. |
| `person` | Details of the primary contact (`first_name`, `last_name`, `email`, `mobile`, `phone`, `job_title`, `role`) or `['person_id' => 12]`. |
| `next_activity` | An activity to plan (`type`, `title`, `due_date`, `due_time`). |
| `offer_document` | An `UploadedFile` stored as the quote. |

## Update a deal

```php
$deal = SalesCrm::updateDeal($deal, [
    'amount_once'         => 13500,
    'expected_close_date' => '2026-12-15',
    'next_step'           => 'Send the revised proposal',
], $user);
```

Only the keys you pass change. The stage, pipeline, workspace and origin
**cannot** be changed here (they are ignored): use `moveDeal()` for the stage.
Every tracked change goes to the history and `DealUpdated` lists the names of
the changed attributes.

## Move a deal

```php
SalesCrm::moveDeal($deal, $stage, $user);                    // a Stage or its id
SalesCrm::moveDeal($deal, $stage, $user, fill: [...]);       // complete required fields
SalesCrm::moveDeal($deal, $stage, $user, afterId: $otherId); // position inside the column
```

A move writes the stage visit (used for "time in stage" in reports), the
history, `won_at`/`lost_at`, runs the [stage automation](/concepts/pipelines#stage-automation)
and fires `DealStageChanged` plus `DealWon`, `DealLost` or `DealReopened`.
Without `afterId` the deal goes to the top of the column.

## Delete a deal

```php
SalesCrm::deleteDeal($deal, $user);
```

Only users who can **manage** the workspace may delete deals. The deal's
history, activities, notes, emails and documents (including the stored files)
are deleted with it; the company and people stay. `DealDeleted` carries the
deal's id and its attributes as they were.

## People on a deal

```php
SalesCrm::addPerson($deal, ['first_name' => 'Marco', 'last_name' => 'Rossi', 'email' => 'marco@northwind.example', 'role' => 'influencer'], $user);
SalesCrm::addPerson($deal, ['person_id' => 42, 'is_primary' => true], $user);
```

A person is either an existing one (`person_id`) or new details, matched against
existing people first. Roles: `decision_maker`, `influencer`, `operational`,
`other`. The first person added becomes **primary** (shown on the card); the
people relation returns the primary first:

```php
$deal->people;                     // primary first
$deal->primaryPerson();
$deal->people->first()->pivot->role;
```

## Signature and payment need evidence {#evidence}

To prevent "signed" or "paid" being set by mistake:

- `signature_status = signed` requires a `signature_evidence` text **or** a
  document of kind `contract` or `signature_evidence` on the deal.
- `payment_status = partial` or `paid` requires `amount_collected` **and** a
  `payment_evidence` text or a `payment_evidence` document.

The CRM records who verified it and when (`signature_verified_by/at`,
`payment_verified_by/at`). The last evidence document of a signed or paid deal
cannot be deleted.

## Internal approval {#approval}

Some deals need a manager's OK (a discount, unusual terms):

```php
SalesCrm::updateDeal($deal, ['approval_required' => true, 'approver_id' => $manager->id, 'approval_note' => '15% discount'], $user);
// → approval_status becomes "pending", ApprovalRequested fires, the approver is notified

SalesCrm::updateDeal($deal, ['approval_status' => 'approved'], $manager);
// → ApprovalDecided fires
```

The rules:

- Only the chosen **approver** or a **manager** decides (approves, rejects or
  takes back a decision).
- The deal's **owner never approves their own deal**, even if they are a manager.
- Nobody names themselves approver except a manager.
- Changing the approver resets a decision to `pending`.

The **Approval** quick filter on the board lists the deals waiting for a
decision.

## Commission

`commission_type = amount` stores a fixed amount; `percent` stores a
percentage of the first-year value (`commission_value => 10` means 10%).
`$deal->commissionCents()` returns the commission in cents.

## Custom fields {#custom-fields}

Managers define up to `sales-crm.deals.custom_fields_limit` extra fields per
workspace in **Settings** (key, label, type `text`, `number`, `date`, `boolean`
or `choice` with options). Values are passed under `custom_values`:

```php
SalesCrm::updateDeal($deal, ['custom_values' => ['contract_length' => 24, 'region' => 'North']], $user);
```

Values you pass are merged with those already there; a `null` value removes
one. Removing a field definition keeps the old values on the deals, hidden.

## Deals born from your records {#deals-born-from-your-records}

When a deal originates from something in your app (an order, a booking, a form
submission), use `createDealFrom()`:

```php
// app/Providers/AppServiceProvider.php, in boot()
use App\Events\BookingConfirmed;
use Illuminate\Support\Facades\Event;
use Sellinnate\SalesCrm\Facades\SalesCrm;

Event::listen(BookingConfirmed::class, function (BookingConfirmed $event) {
    SalesCrm::for($event->booking->team)->createDealFrom($event->booking, [
        'title'        => 'Booking #'.$event->booking->id,
        'company_name' => $event->booking->company_name,
        'person'       => ['email' => $event->booking->email],
    ]);
});
```

- It is **idempotent**: one deal per origin record. Calling it again returns
  the existing deal (also under concurrency, thanks to a unique index).
- Without `stage_id`/`pipeline_id` the deal enters the workspace's **entry
  stage** (set in Settings), or else the first open stage of the default
  pipeline.
- `source` defaults to `integration`.
- Required fields of the stage are not enforced.
- `$deal->origin` returns your model; `origin_type`/`origin_id` store it.

## Reading deals

```php
use Sellinnate\SalesCrm\Models\Deal;

// Everything of a workspace (no permission filter):
SalesCrm::for($team)->deals()->open()->get();

// What a given user may see (workspaces + visibility rules):
Deal::query()->visibleTo($user)->open()->with('stage', 'company')->get();

// With the board's filters:
SalesCrm::board()->query($user, ['quick' => ['mine', 'stale'], 'search' => 'acme'])->get();
```

Useful helpers on a `Deal`: `isOpen()`, `isWon()`, `isLost()`,
`weightedCents()`, `mrrCents()`, `arrCents()`, `commissionCents()`,
`offerState()` (`expired`, `expiring` or `null`), `staleDays()`,
`daysInStage()`, `nextActivityState()` (`none`, `overdue`, `today`, `planned`).
Relations: `pipeline`, `stage`, `company`, `owner`, `approver`, `origin`,
`people`, `activities`, `notes`, `documents`, `emails`, `events`,
`stageVisits`.

## Best practices

- **Use `external_ref`** to keep the id of the deal in another system: it is
  unique per workspace and searchable.
- **Use `createDealFrom()`** for anything automatic: it can't create duplicates.
- **Always pass amounts in major units**; read `*_cents` columns divided by 100.
- **Set `next_step` and plan a next activity** on every open deal: the board
  flags deals without one.

## Common pitfalls

- Passing `stage_id` to `updateDeal()`: it is silently ignored. Use `moveDeal()`.
- Passing `owner_id` of a user who doesn't work in the workspace: validation
  error "The selected user does not work in this CRM workspace."
- Setting `payment_status => 'paid'` without `amount_collected` and evidence:
  validation error on `payment_evidence`.
