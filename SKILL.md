---
name: sales-crm
description: Use sellinnate/sales-crm in a Laravel app — create and move deals, match companies and people, schedule activities, send and receive deal emails, react to CRM events, configure workspaces, permissions, the Livewire UI, MCP tools and the JSON API.
---

# Sales CRM for Laravel (SKILL.md)

You are working in a Laravel application that uses `sellinnate/sales-crm`
(namespace `Sellinnate\SalesCrm`, facade `SalesCrm`, config `config/sales-crm.php`).
Follow these rules when writing code against it. Full docs:
https://laravel-sales-crm.selli.io

## 1. Golden rules

- **Write through the package, not through the models.** Create, update, move and
  delete deals with `SalesCrm::for($workspace)->createDeal()`,
  `SalesCrm::updateDeal()`, `SalesCrm::moveDeal()`, `SalesCrm::deleteDeal()`. They
  validate, authorize, keep positions and history consistent and dispatch events.
  `Deal::create()` skips all of that.
- **Always scope reads to the user.** `Deal::query()->visibleTo($user)` (or
  `SalesCrm::for($workspace)->deals()` for system code) — never an unscoped query
  in user-facing code.
- **Pass the acting user** (`$by`) whenever there is one: it is checked by the
  policies and recorded in history and events. System code (webhooks, jobs) may
  pass `null` where the signature allows it.
- **Amounts are in currency units** in input (`amount_once`, `recurring`) and
  stored in cents (`amount_once_cents`, `recurring_cents`).

## 2. Scope: shared CRM or workspace

```php
use Sellinnate\SalesCrm\Facades\SalesCrm;

$crm = SalesCrm::shared();        // app without workspaces
$crm = SalesCrm::for($team);      // a workspace model (Team, Account…) with workspaces enabled

$crm->deals();  $crm->pipelines();  $crm->companies();  $crm->people();  $crm->activities();
$crm->defaultPipeline();          // created from config on first use
```

## 3. Deals

```php
$deal = $crm->createDeal([
    'title' => 'Website redesign',
    'company_name' => 'Northwind Traders',          // matched or created
    'amount_once' => 12000, 'recurring' => 300, 'recurring_period' => 'monthly',
    'person' => ['first_name' => 'Laura', 'email' => 'laura@northwind.example'],
    // optional: pipeline_id, stage_id, owner_id, priority (p1..p4), source, tags, expected_close_date…
], $user);

// Idempotent bridge from one of your models (same origin → same deal):
$crm->createDealFrom($order, ['title' => 'Order #'.$order->id]);

SalesCrm::updateDeal($deal, ['next_step' => 'Send the quote'], $user);
SalesCrm::moveDeal($deal, $stage, $user, fill: ['offer_valid_until' => '2026-12-31']);
```

`moveDeal()` throws a `ValidationException` with one `requirements.<field>` error
per field the target stage requires and the deal lacks (and `lost_reason` for a
lost stage); `Sellinnate\SalesCrm\Exceptions\MissingRequirements::fieldsOf($e)`
lists them as `RequiredField` enums. Ask for them and pass them in `fill`.
Moving to a won/lost stage dispatches `DealWon` / `DealLost`.

## 4. Contacts, activities, notes, documents, email

```php
$company = $crm->company(['name' => 'Acme', 'website' => 'acme.example']);   // de-duplicated
$person  = $crm->person(['first_name' => 'Jane', 'email' => 'jane@acme.example'], $company);

SalesCrm::scheduleActivity($deal, ['type' => 'call', 'title' => 'Follow up', 'due_date' => '2026-11-02', 'due_time' => '10:00'], $user);
SalesCrm::completeActivity($activity, 'Interested, call back in May', $user);
SalesCrm::addNote($deal, 'Budget confirmed by @Jane Doe', $user);          // mentions notify users
SalesCrm::uploadDocument($deal, $uploadedFile, ['kind' => 'quote', 'valid_until' => '2026-12-31'], $user);
SalesCrm::sendEmail($deal, ['to' => ['jane@acme.example'], 'subject' => 'Our proposal', 'body' => '…'], $user);
```

Inbound emails: configure `SALES_CRM_INBOUND_DRIVER` (`webhook`, `imap`) or push
them yourself with `SalesCrm::inbound()->receive(InboundEmail::fromArray([...]))`.

## 5. Events

Listen to `Sellinnate\SalesCrm\Events\*` (dispatched after commit): `DealCreated`,
`DealUpdated`, `DealStageChanged`, `DealWon`, `DealLost`, `DealReopened`,
`DealDeleted`, `ActivityCreated`, `ActivityCompleted`, `ActivityDue`, `NoteAdded`,
`UserMentioned`, `DocumentUploaded`, `EmailQueued`, `EmailSent`, `EmailFailed`,
`EmailReceived`, `CompanyCreated`, `PersonCreated`, `ApprovalRequested`,
`ApprovalDecided`, `ImportFinished`. Most carry the model and `?Authenticatable $by`.

## 6. Extension points (contracts)

| Contract | Default | Bind your own to… |
|---|---|---|
| `Contracts\WorkspaceResolver` | `SingleWorkspaceResolver` (or `RelationWorkspaceResolver` via config) | split the CRM by tenant |
| `Contracts\Authorizer` | `DefaultAuthorizer` (gates `sales-crm.access` / `sales-crm.manage`) | custom permissions |
| `Contracts\SenderResolver` | `ConfigSenderResolver` | From/Reply-To/mailer per user or workspace |
| `Contracts\InboundDriver` | `null`, `webhook`, `imap` | read emails from another source |
| `Contracts\TimelineProvider` | none | show your calls/tickets/orders on the timeline |

Prefer defining the two gates over replacing the authorizer.

## 7. Common pitfalls

- Changing `database.user_key_type`/`workspace_key_type` after migrating does
  nothing: set them before the first `migrate`.
- Forgetting to schedule `sales-crm:remind-activities` (every minute): no reminders.
- Rendering the UI in your own layout without `<x-sales-crm::styles />` in `<head>`.
- Leaving the default gates in production when not every user should see the CRM.
