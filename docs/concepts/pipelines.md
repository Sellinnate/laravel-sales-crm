---
title: "Pipelines & stages"
description: "How pipelines and stages work: kinds, probability, rotting days, required fields, stage automation and the default pipeline."
---

# Pipelines & stages

A **pipeline** is the path your deals follow from first contact to signature.
It is split into **stages**, shown as the columns of the kanban board. A
workspace can have several pipelines (for example "New business" and
"Renewals"); every deal belongs to exactly one pipeline and one stage.

::: callout info "In plain words"
Think of the pipeline as a to-do board for sales: each column is a step
("Qualified", "Proposal sent", "Negotiation"), and each card is a deal moving
left to right until it is **won** or **lost**.
:::

## The default pipeline

You don't have to create anything: the first time a workspace (or the shared
CRM) is used, it gets a pipeline named after `sales-crm.pipeline.default_name`
with the stages of `sales-crm.pipeline.default_stages`:

| Stage | Kind | Probability | Rotting days | Requires | On enter |
|---|---|---|---|---|---|
| New lead | open | 10% | 7 | | |
| Qualified | open | 20% | 10 | | |
| Meeting / demo | open | 40% | 10 | | |
| Proposal sent | open | 60% | 14 | amount, offer valid until | call "Follow up on the proposal" in 3 days |
| Negotiation | open | 75% | 14 | | |
| Signature | open | 90% | 7 | amount, owner | |
| Won | won | 100% | | | |
| Lost | lost | 0% | | | |

From code:

```php
use Sellinnate\SalesCrm\Facades\SalesCrm;

$pipeline = SalesCrm::for($team)->defaultPipeline();   // created if missing
$pipelines = SalesCrm::for($team)->pipelines()->get(); // active first, then by position
```

To change the stages new workspaces start with, edit `default_stages` in the
config ([Configuration](/getting-started/configuration#default-pipeline)). To
change an existing pipeline, use the **Settings** page of the UI.

## Stage properties

| Property | Meaning |
|---|---|
| **name** | Up to 80 characters. |
| **color** | One of `neutral`, `brand`, `info`, `accent`, `warning`, `success`, `danger` (the dot of the column). |
| **kind** | `open` (work in progress), `won` or `lost`. |
| **probability** | 0–100: the chance a deal in this stage closes. Used for the **weighted value**. Always 100 for won and 0 for lost. |
| **rotting days** | Open stages only: after this many days without activity a deal is **stale** (highlighted, and found by the "stale" quick filter). |
| **required fields** | What a deal must have to enter the stage. |
| **on enter activity** | An activity created automatically when a deal enters the stage. |

### Kinds: open, won, lost

- Entering a **won** stage sets the deal's `won_at`; entering a **lost** stage
  sets `lost_at` and **requires a lost reason** (`price`, `timing`,
  `competitor`, `no_response`, `no_budget`, `not_in_target`, `postponed`,
  `other`).
- Moving a won or lost deal back to an open stage **reopens** it (`won_at`,
  `lost_at`, the lost reason and note are cleared).
- A deal is *open* when it has neither `won_at` nor `lost_at`:
  `Deal::query()->open()`.

### Weighted value

Each deal has a **first-year value**: the one-off amount plus one year of the
recurring amount (a monthly 500 counts 6,000). The **weighted value** is that
value multiplied by the stage probability. The board shows both totals for each
column, and reports use them for the forecast.

## Required fields {#required-fields}

A stage can refuse deals that are not ready for it. The catalogue is closed
(enum `Sellinnate\SalesCrm\Enums\RequiredField`):

| Value | Satisfied when the deal… |
|---|---|
| `company` | has a company. |
| `person` | has at least one person. |
| `owner` | has an owner. |
| `amount` | has a one-off **or** recurring amount greater than zero. |
| `expected_close_date` | has an expected close date. |
| `offer_valid_until` | has an offer validity date. |
| `payment_terms` | has payment terms. |
| `next_activity` | has at least one open activity. |
| `offer_document` | has a document of kind `quote`. |

The check runs on **every** way of moving a deal (board, API, MCP, your code),
and also when a deal is created directly in a stage. When something is missing
the action throws a `ValidationException` with one error per field under
`requirements.<field>` (and `lost_reason` for a lost stage):

```php
use Illuminate\Validation\ValidationException;
use Sellinnate\SalesCrm\Exceptions\MissingRequirements;

try {
    SalesCrm::moveDeal($deal, $signatureStage, $user);
} catch (ValidationException $e) {
    $missing = MissingRequirements::fieldsOf($e);   // list of RequiredField enums
    // [RequiredField::Owner]
}

// Complete the missing fields with the move:
SalesCrm::moveDeal($deal, $signatureStage, $user, fill: ['owner_id' => $user->id]);
```

What you can put in `$fill`:

| Missing field | Keys in `$fill` |
|---|---|
| company | `company_name` |
| person | `person` (array: `first_name`, `last_name`, `email`, `mobile`, …) |
| owner | `owner_id` |
| amount | `amount_once` and/or `recurring` + `recurring_period` |
| expected close date | `expected_close_date` |
| offer valid until | `offer_valid_until` |
| payment terms | `payment_terms` |
| next activity | `next_activity` (array: `type`, `title`, `due_date`, `due_time`) |
| offer document | `offer_document` (an `UploadedFile`) and `offer_valid_until` |
| lost reason | `lost_reason`, optional `lost_note` |

In the UI, dropping a card on such a stage opens a **mini form** with only the
missing fields.

::: callout tip "Imports and integrations skip the check"
`createDealFrom()` and the importer create deals *as they are*: the required
fields of the target stage are not enforced (the importer lists them as
warnings in its report), so data from other systems is never rejected for a
missing field. A lost stage still needs a lost reason (`createDealFrom()`
refuses without one; the importer uses `other` and adds a warning), and moving
those deals afterwards checks the requirements as usual.
:::

## Stage automation {#stage-automation}

`on_enter_activity` creates an activity each time a deal **enters** the stage:

```php
'on_enter_activity' => ['type' => 'call', 'title' => 'Follow up on the proposal', 'due_in_days' => 3],
```

The activity is assigned to the deal's owner (while they still work in the
workspace), is due `due_in_days` from today and fires an `ActivityCreated`
event with `$by = null` (the system).

## Managing pipelines and stages

Managers (users passing the `sales-crm.manage` gate) edit pipelines from
**Settings** in the UI. The rules, enforced by the actions:

- A new pipeline starts with the default stages.
- A workspace always keeps **at least one active pipeline**; only a pipeline
  **without deals** can be deleted.
- A pipeline always keeps **one open, one won and one lost stage**.
- A new open stage is inserted before the closed ones.
- The **kind** of a stage that has deals cannot change; only an **empty** stage
  can be deleted.
- Rotting days: 1–365. Automation due days: 0–365.

## Querying

```php
use Sellinnate\SalesCrm\Models\Pipeline;

$pipeline = Pipeline::query()->visibleTo($user)->active()->ordered()->first();

$pipeline->stages;                 // ordered by position
$pipeline->firstOpenStage();       // where new deals land
$stage->requiredFields();          // list<RequiredField>
$stage->isOpen();
```

For column totals use the board service:

```php
$board   = SalesCrm::board();
$columns = $board->columns($pipeline, $board->query($user, ['closed' => 'recent'], $pipeline));

foreach ($columns as $column) {
    $column['stage']->name;   // stage
    $column['count'];         // deals matching
    $column['value'];         // first-year value, in cents
    $column['weighted'];      // weighted value, in cents
}
```

## Best practices

- **Keep stages few and meaningful** (5–8 open stages). Each one should mean a
  verifiable step, not a feeling.
- **Use required fields as quality gates** at the stages where data matters
  (amount before the proposal, owner before signature).
- **Set rotting days** so stale deals surface on their own.
- **Calibrate probabilities** once you have a few months of data: the reports
  show the real win rate.

## Common pitfalls

- Expecting `default_stages` changes to apply to pipelines that already exist.
- Trying to delete a stage with deals: move them first.
- Moving a deal to a stage of **another pipeline**: refused (`stage_id`
  validation error). A deal stays in its pipeline.
