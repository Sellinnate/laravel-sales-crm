---
title: "Sales CRM for Laravel"
description: "Pipelines, deals, companies, people, activities and email for any Laravel app: a headless CRM core with an optional Livewire UI."
image: "https://laravel-sales-crm.selli.io/assets/banner.png"
ogImage: "https://laravel-sales-crm.selli.io/assets/banner.png"
---

![Sales CRM for Laravel](/assets/banner.png)

# Sales CRM for Laravel

**Sales CRM** (`sellinnate/sales-crm`) adds a complete **sales pipeline CRM to
your existing Laravel application**: a kanban board of deals, companies and
people, activities with reminders, notes, documents, email sent from the deals
(and replies read back), CSV/XLSX import and export, reports and a single
timeline per record. It lives inside your app, uses your users and your
database, and can be split by team, client or account.

::: callout tip "New to CRMs? The five words you need"
- A **deal** is a sale you are working on ("Website redesign for Acme, 12,000 €").
- A **pipeline** is the path a deal follows, split into **stages**
  ("New lead" → "Proposal sent" → "Won").
- **Companies** and **people** are who you sell to.
- An **activity** is something to do on a date (call, meeting, task).
- The **timeline** is the history of everything that happened on a deal.

Every concept has its own page under **Concepts** in the sidebar.
:::

## What you can build with it

- **An internal sales tool** for your team, inside the admin area you already
  have, with the same login.
- **A CRM per customer in a SaaS**: every team, client or account of your app
  gets its own isolated pipelines and contacts
  ([Workspaces](/concepts/workspaces)).
- **Automatic deals from your own data**: an order, a form entry or a booked
  appointment becomes a deal, once, with
  [`createDealFrom()`](/concepts/deals#deals-born-from-your-records).
- **An AI sales assistant**: expose the CRM to an AI agent as
  [MCP tools](/interfaces/mcp) that act with the permissions of the logged-in
  user.
- **Integrations** with other systems through the optional
  [JSON API](/interfaces/api) and the [events](/concepts/events) the CRM fires.

## Headless core, optional interfaces

The package has one **headless core** (models, actions, rules, events) and three
optional ways to use it. *Headless* means the core has no screens: you drive it
from PHP.

| Layer | What it is | Needs |
|---|---|---|
| **PHP API** | The `SalesCrm` facade: create, move and update deals, add notes, plan activities, send emails… | nothing extra |
| **Web UI** | A ready-made CRM at `/crm`: kanban board, deal panel, list, agenda, companies, people, reports, import, settings | `livewire/livewire` 3 or 4 |
| **MCP tools** | Nine tools an AI agent can call | `laravel/mcp` |
| **JSON API** | REST endpoints for pipelines and deals | Sanctum (or any auth guard you configure) |

All four go through **the same actions**, so validation, authorization, history
and events are identical whether a deal is moved by drag and drop, by an API
call or by an AI agent. Read [Architecture](/concepts/architecture) for the full
picture.

## What's in the box

- **Pipelines and stages** with probability, colour, "rotting" days, fields
  required to enter a stage and an activity created automatically on entering.
- **Deals** with one-off and recurring amounts, offer validity, priority, tags,
  source, operational notes, signature and payment status with evidence,
  internal approval, commission and custom fields.
- **Companies and people** with automatic de-duplication (by VAT number,
  domain, name, email and phone).
- **Activities** (call, email, meeting, deadline, task) with reminders and an
  agenda.
- **Notes** with `@mentions`, pinned notes and **documents** (quotes, contracts,
  evidence) on private storage.
- **Email** sent from a deal through Laravel's mailer, with templates and
  variables; **inbound** replies matched back to the deal (IMAP, webhook or your
  own code).
- **Import** of deals from CSV/XLSX with column mapping and preview; **export**
  of the deal list to CSV/XLSX.
- **Reports**: open pipeline, forecast, win rate, lost reasons, time per stage,
  owners and sources.
- **Multi-tenancy, authorization and 22 events** you can listen to.
- **English and Italian** translations, light and dark theme.

## A first taste

```php
use Sellinnate\SalesCrm\Facades\SalesCrm;

$crm = SalesCrm::shared();             // one CRM for the whole app

$deal = $crm->createDeal([
    'title'        => 'Website redesign',
    'company_name' => 'Acme Ltd',      // matched or created
    'amount_once'  => 12000,           // major units: 12,000.00
    'person'       => ['first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@acme.example'],
], by: $user);

SalesCrm::scheduleActivity($deal, [
    'type'     => 'call',
    'title'    => 'Discovery call',
    'due_date' => now()->addDay()->toDateString(),
    'due_time' => '10:00',
], $user);
```

Then open `/crm` in the browser and you'll find the deal on the board.

## Requirements

| | Supported |
|---|---|
| PHP | 8.2+ |
| Laravel | 11, 12, 13 |
| Database | MySQL, PostgreSQL, SQLite |
| Web UI | Livewire 3 or 4 (optional) |

## Where to go next

1. **[Installation](/getting-started/installation)**: add the package to your app.
2. **[Quickstart](/getting-started/quickstart)**: create, move and follow up a deal in ten minutes.
3. **[Configuration](/getting-started/configuration)**: every option explained.
4. **[Architecture](/concepts/architecture)**: how the pieces fit together.

::: callout info "What is not included"
The package is a sales CRM, not a marketing or telephony suite: there is no mass
mailing, no calling or call recording and no marketing automation. You can bring
calls, tickets or orders from your own systems into the timeline with a
[timeline provider](/concepts/timeline).
:::
