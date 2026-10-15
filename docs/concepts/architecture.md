---
title: "Architecture"
description: "How the headless core, the actions, the contracts and the optional interfaces (UI, MCP, JSON API) fit together."
---

# Architecture

This page gives you the mental model of the package: what lives where, how a
request flows through it, and which parts you can replace.

::: callout info "In plain words"
The CRM is a set of Eloquent models plus a set of **actions** (classes that do
one thing: create a deal, move a deal, add a note). Every screen, API endpoint
and AI tool calls the same actions. That's why the rules are always the same,
whoever triggers them.
:::

## The layers

```text
 ┌──────────────┐ ┌──────────────┐ ┌──────────────┐ ┌──────────────┐
 │  Your code   │ │   Web UI     │ │  MCP tools   │ │  JSON API    │
 │ SalesCrm::…  │ │  (Livewire)  │ │ (laravel/mcp)│ │ (/api/crm)   │
 └──────┬───────┘ └──────┬───────┘ └──────┬───────┘ └──────┬───────┘
        └────────────────┴────────┬───────┴────────────────┘
                                  ▼
                 ┌──────────────────────────────────┐
                 │  Actions                          │  CreateDeal, UpdateDeal, MoveDeal,
                 │  validation · authorization ·     │  ManageActivities, ManageNotes,
                 │  locks · history · events         │  SendEmail, ManageDocuments…
                 └───────────────┬──────────────────┘
                                 ▼
     ┌──────────────┐   ┌──────────────────┐   ┌───────────────────┐
     │  Contracts    │   │  Models (crm_*)  │   │  Services          │
     │  workspaces,  │   │  Deal, Stage,    │   │  board, reports,   │
     │  authorizer,  │   │  Company, Person │   │  timeline, import, │
     │  sender, …    │   │  Activity, Note… │   │  matcher…          │
     └──────────────┘   └──────────────────┘   └───────────────────┘
```

### The PHP API

`Sellinnate\SalesCrm\Facades\SalesCrm` is the front door for your code. It has
two levels:

- **`SalesCrm::for($workspace)` / `SalesCrm::shared()`** return a `CrmScope`:
  the CRM of one workspace, with query builders (`deals()`, `companies()`…) and
  creation methods (`createDeal()`, `createDealFrom()`, `company()`,
  `person()`).
- **Methods on the facade** act on an existing record: `moveDeal()`,
  `updateDeal()`, `addNote()`, `scheduleActivity()`, `sendEmail()`…

Full list: [PHP API reference](/reference/php-api).

### Actions

Every write goes through an action class in `Sellinnate\SalesCrm\Actions`. An
action:

1. **authorizes** the acting user with the CRM [policies](/concepts/authorization)
   (skipped when no user is given, i.e. the system is acting);
2. **validates** the input and throws Laravel's `ValidationException` with
   field-level errors;
3. takes the **locks** it needs (cache locks for the board order and for
   contact de-duplication) and opens a **database transaction**;
4. writes the change, the **history** (`crm_deal_events`, `crm_stage_visits`) and
   the derived values (next activity, last contact…);
5. fires an **event**, delivered **after the transaction commits**.

### Models

Eloquent models in `Sellinnate\SalesCrm\Models`: `Pipeline`, `Stage`, `Deal`,
`Company`, `Person`, `Activity`, `Note`, `Document`, `Email`, `EmailTemplate`,
`Import`, `SavedView`, `WorkspaceSetting`, `DealEvent`, `StageVisit`. Their
tables take the configured prefix (`crm_` by default) and connection. See the
[Database schema](/reference/database).

You can read them freely (`Deal::query()->open()->get()`), but **write through
the actions** so validation, history and events stay consistent.

### Services

Read-side helpers you can use directly:

| Service | Access | Purpose |
|---|---|---|
| `DealBoard` | `SalesCrm::board()` | Filtered deal queries and kanban columns with totals. |
| `Reports` | `SalesCrm::reports()` | The figures of the reports page. |
| `DealTimeline` | `SalesCrm::timeline()` | The merged timeline of a deal, company or person. |
| `Inbound` | `SalesCrm::inbound()` | Receive or poll inbound email. |
| `Access` | `SalesCrm::access()` | Workspace and permission checks. |

### Contracts

Five interfaces in `Sellinnate\SalesCrm\Contracts` are the extension points:

| Contract | Default | Decides |
|---|---|---|
| `WorkspaceResolver` | `SingleWorkspaceResolver` | Which workspaces exist and who works in them. |
| `Authorizer` | `DefaultAuthorizer` | Who may enter, configure and see which deals. |
| `SenderResolver` | `ConfigSenderResolver` | From, Reply-To and mailer of outgoing email. |
| `InboundDriver` | `null`, `imap`, `webhook` drivers | Where inbound email comes from. |
| `TimelineProvider` | none | Extra read-only entries in the timelines. |

See [Custom contracts](/extending/contracts) for how to implement each one.

## The interfaces

| Interface | Turned on by | Docs |
|---|---|---|
| Web UI | `livewire/livewire` installed and `sales-crm.ui.enabled` (default `true`) | [Web UI](/interfaces/ui) |
| MCP server | `laravel/mcp` installed and `sales-crm.mcp.enabled` | [MCP tools](/interfaces/mcp) |
| JSON API | `sales-crm.api.enabled` | [JSON API](/interfaces/api) |

None of them is required: the core works with nothing but your code.

## What the service provider registers

At boot the package:

- binds the contracts from the config (`workspaces.resolver`,
  `authorization.authorizer`) and the default `SenderResolver`;
- defines the gates `sales-crm.access` and `sales-crm.manage` **only if your app
  hasn't defined them** (default: any authenticated user);
- registers the policies listed in `sales-crm.authorization.policies`;
- subscribes the listener that sends the default notifications;
- registers the routes (UI pages, downloads, CSS, inbound webhook and, if
  enabled, the JSON API);
- registers the Livewire components (when Livewire is installed) and the MCP
  server (when enabled and `laravel/mcp` is installed);
- registers three Artisan commands: `sales-crm:install`,
  `sales-crm:remind-activities`, `sales-crm:poll-inbox`.

## A request, end to end

Dragging a card to **Proposal sent** on the board:

1. The Livewire board calls `MoveDeal` with the deal, the stage and the user.
2. `MoveDeal` asks the `DealPolicy` whether the user may update the deal.
3. It checks the stage's required fields; if some are missing it throws a
   `ValidationException` with `requirements.<field>` keys and the board opens
   the mini form.
4. Under the pipeline lock and inside a transaction it updates the deal,
   records the stage visit and the history, places the card and creates the
   stage's automatic activity.
5. After the commit, `DealStageChanged` fires (and `DealWon`, `DealLost` or
   `DealReopened` when relevant).
6. Other open boards notice the new version of the pipeline at their next poll
   and redraw.

The same steps happen for `SalesCrm::moveDeal()`, `POST /api/crm/deals/{id}/move`
and the `crm_deal_move` MCP tool.

## Best practices

- **Write through the facade or the actions**, never with
  `Deal::create()`/`$deal->update()`: you'd skip validation, history, events
  and locks.
- **Pass the real user** as `$by` for user actions; pass nothing only for
  genuine system actions (imports from your backend, integrations).
- **Listen to events** to react to CRM changes instead of model observers.

## Common pitfalls

- Using a cache store without atomic locks (or a per-server `file` cache on
  several servers): locks then don't protect concurrent moves. Use redis,
  database or memcached in multi-server setups.
- Querying `Deal::query()` and showing the result to a user: it ignores
  workspaces and visibility. Use `Deal::query()->visibleTo($user)`.
