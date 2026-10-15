---
title: "Quickstart"
description: "Create a deal from code, move it through the pipeline, plan and complete an activity, write a note and see it all on the board at /crm."
---

# Quickstart

In ten minutes you will create a deal from PHP, move it to another stage (and
see what happens when a stage requires fields), plan a follow-up call, write a
note and then find all of it in the web UI.

::: callout info "What you need"
- The package installed and migrated ([Installation](/getting-started/installation)).
- At least one user in your database.
- For the last step, `livewire/livewire` installed.
:::

Run the snippets in `php artisan tinker`, or paste them into a route or a
command: they are plain PHP.

## 1. Pick the CRM and the user

Every write needs to know **who** is doing it (for authorization, history and
the "owner" of the deal). Grab a user:

```php
use App\Models\User;
use Sellinnate\SalesCrm\Facades\SalesCrm;

$user = User::first();

// One CRM for the whole application (no workspaces yet).
$crm = SalesCrm::shared();
```

`SalesCrm::shared()` returns the CRM of an app without workspaces. In a
multi-tenant app you would write `SalesCrm::for($team)` instead; see
[Workspaces](/concepts/workspaces).

## 2. Create a deal

```php
$deal = $crm->createDeal([
    'title'        => 'Website redesign',
    'company_name' => 'Acme Ltd',
    'amount_once'  => 12000,                 // 12,000.00 in your currency
    'expected_close_date' => now()->addMonth()->toDateString(),
    'person' => [
        'first_name' => 'Jane',
        'last_name'  => 'Doe',
        'email'      => 'jane@acme.example',
    ],
], by: $user);

$deal->stage->name;      // "New lead": the first open stage of the default pipeline
$deal->company->name;    // "Acme Ltd"
$deal->owner_id;         // your user: the author becomes the owner
```

What happened behind the scenes:

- The **default pipeline** was created (the first time only) from
  `sales-crm.pipeline.default_stages`.
- **Acme Ltd** was looked up among existing companies (by VAT number, domain,
  then name) and created because it didn't exist. Run the snippet twice and the
  second deal reuses the same company: this is
  [de-duplication](/concepts/contacts#de-duplication).
- **Jane Doe** became the deal's **primary contact**.
- A `DealCreated` [event](/concepts/events) was fired.

Amounts are always given in **major units** (euros, dollars): the CRM stores
cents internally.

## 3. Move it, and meet required fields

In the default pipeline the stage **Proposal sent** requires an amount and an
offer validity date. Our deal has an amount but no validity, so the move is
refused:

```php
use Illuminate\Validation\ValidationException;

$proposal = $deal->pipeline->stages->firstWhere('name', 'Proposal sent');

try {
    SalesCrm::moveDeal($deal, $proposal, $user);
} catch (ValidationException $e) {
    $e->errors();
    // ["requirements.offer_valid_until" => ["To enter «Proposal sent» you need: offer valid until."]]
}
```

Every missing field is reported under `requirements.<field>`. Pass the missing
values with the move, in the `$fill` argument:

```php
$deal = SalesCrm::moveDeal($deal, $proposal, $user, fill: [
    'offer_valid_until' => now()->addDays(30)->toDateString(),
]);

$deal->stage->name;   // "Proposal sent"
```

The web UI does exactly the same: dropping a card on a stage that requires
fields opens a small form asking only for what is missing. Read more in
[Pipelines & stages](/concepts/pipelines#required-fields).

::: callout tip "Stage automation"
**Proposal sent** also has an `on_enter_activity`: entering it created a call
"Follow up on the proposal" due in three days. Check it:

```php
$deal->activities()->whereNull('done_at')->pluck('title');
// ["Follow up on the proposal"]
```
:::

## 4. Plan an activity and complete it

```php
$call = SalesCrm::scheduleActivity($deal, [
    'type'           => 'call',          // call | email | meeting | deadline | task
    'title'          => 'Walk Jane through the proposal',
    'due_date'       => now()->addDay()->toDateString(),   // Y-m-d
    'due_time'       => '10:30',                           // H:i, omit for "all day"
    'remind_minutes' => 15,                                // must be in activities.reminder_options
], $user);

// Later, once the call is done:
SalesCrm::completeActivity($call, 'Jane likes it, waiting for the CFO.', $user);
```

Completing a call, an email or a meeting also updates the deal's **last
contact**. Reminders are sent by `sales-crm:remind-activities`, which you
scheduled during installation.

## 5. Write a note

```php
SalesCrm::addNote($deal, 'Budget confirmed. @Mario Rossi can you prepare the contract?', $user);
```

If a user named *Mario Rossi* works in the CRM, he is **mentioned**: a
`UserMentioned` event fires and, by default, he gets a notification.

## 6. Open the board

Log in to your app and open **`/crm`**. You'll see the board with the
**Website redesign** card in **Proposal sent**. Click it to open the side panel
with the fields, the people, the open activities and the timeline (the stage
change, the completed call, the note).

Try dragging the card to **Won**: then try **Lost** and see the form asking for
the reason of the loss.

## 7. Bonus: a deal from one of your records

If deals come from something in your app (an order, a form entry), use
`createDealFrom()`. It creates **at most one deal per record**, so it's safe to
call from an event listener that might run twice:

```php
$deal = $crm->createDealFrom($order, [
    'title'       => 'Order #'.$order->id,
    'amount_once' => $order->total,
]);

// Called again for the same $order: returns the same deal.
```

## What you learned

- `SalesCrm::shared()` / `SalesCrm::for($workspace)` select a CRM.
- Writes take the acting user and go through validation, authorization and
  history.
- A stage can require fields; the error lists them under `requirements.*` and
  you pass them in `$fill`.
- Activities, notes and emails all end up on the deal's timeline.

## Next steps

- **[Configuration](/getting-started/configuration)**: shape the default
  pipeline, currency, email and UI.
- **[Deals](/concepts/deals)**: every field a deal accepts.
- **[PHP API](/reference/php-api)**: the full facade reference.
