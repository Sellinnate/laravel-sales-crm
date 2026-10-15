---
title: "Web UI (Livewire)"
description: "A tour of the ready-made CRM interface: kanban board with drag and drop, deal panel, list view, agenda, companies, people, reports, import and settings."
---

# Web UI (Livewire)

Install Livewire and your app gets a complete CRM interface at **`/crm`**: no
controllers, views or front-end build to write.

```bash
composer require livewire/livewire
```

::: callout info "What you need"
- `livewire/livewire` 3 (^3.5) or 4.
- A logged-in user: the pages use the `web` and `auth` middleware by default and
  require the `sales-crm.access` gate.
- Nothing else: the CSS is precompiled and served by the package, and the
  Livewire scripts are included by the default layout.
:::

The UI is available in **English and Italian** (it follows your app locale),
has a **light and dark theme** (following the system, or a `dark` class on
`<html>`), and is usable with the keyboard.

## Pages

| Page | URL | Route name |
|---|---|---|
| Deals (board and list) | `/crm` | `sales-crm.deals` |
| A deal on its own page | `/crm/deals/{deal}` | `sales-crm.deals.show` |
| Agenda | `/crm/agenda` | `sales-crm.agenda` |
| Companies | `/crm/companies` | `sales-crm.companies` |
| A company | `/crm/companies/{company}` | `sales-crm.companies.show` |
| People | `/crm/people` | `sales-crm.people` |
| A person | `/crm/people/{person}` | `sales-crm.people.show` |
| Reports | `/crm/reports` | `sales-crm.reports` |
| Import (managers) | `/crm/import` | `sales-crm.import` |
| Settings | `/crm/settings` | `sales-crm.settings` |

The prefix (`crm`), an optional domain and the middleware come from
`sales-crm.ui` ([Configuration](/getting-started/configuration#web-ui)). Link to
the CRM from your app with the route names:

```blade
<a href="{{ route('sales-crm.deals') }}">CRM</a>
<a href="{{ route('sales-crm.deals.show', $deal) }}">{{ $deal->title }}</a>
```

## The deals board

The home of the CRM: one column per stage of the selected pipeline, one card
per deal.

- **Drag and drop** cards between columns to move them, or within a column to
  reorder them. The order is saved and shared.
- Dropping on a stage with **required fields** opens a small form asking only
  for what's missing (amount, owner, person, next activity, quote…); dropping on
  a **lost** stage asks the reason.
- Each column header shows the **count**, the **total** and the **weighted
  total** of its deals. Columns load `sales-crm.deals.cards_per_column` cards
  (50) and a **Show more** button loads the next batch.
- Cards show the company, value, owner, primary contact and flags: **overdue**
  or **missing** next activity, **stale** deal (idle beyond the stage's rotting
  days), **expired** or **expiring** offer, approval pending. You choose which
  fields cards show.
- **Quick add** creates a deal from the board (title, company, contact,
  amounts, expected close date, owner, priority, source, stage), with the same
  de-duplication and stage requirements as the API.
- **Quick filters**: *Mine*, *P1*, *No activity*, *Stale*, *Offer expired*,
  *Approval*; plus search (title, company, people, email, phone, external
  reference), owner, priority, source, tag and "closed: recent / all". Won and
  lost deals leave the board after `sales-crm.deals.closed_visible_days`.
- **Saved views** store the current filters, view and columns under a name,
  personal or **shared** with the workspace.
- The board **refreshes itself** when someone else changes the pipeline
  (checked every `sales-crm.ui.poll_seconds` while the tab is visible).

The state of the page (pipeline, view, filters, sort, open deal) is kept in the
URL, so a filtered board can be bookmarked or shared.

### Keyboard shortcuts

| Key | Action |
|---|---|
| `N` | New deal |
| `/` | Focus the search |
| `K` / `L` | Kanban or list view |
| `[` / `]` | Move the focused card to the previous / next stage |
| `Enter` | Open the focused card |
| `Esc` | Close the panel or dialog |
| `?` | Show the shortcuts |

## The list view

The same deals as a table: sortable columns (title, value, next activity,
expected close, offer validity; `?sort=updated` or `?sort=created` in the URL
for the most recently changed or created), a column chooser (including
custom fields), **inline edit** of fields, **bulk actions** on selected deals
(move to a stage, change owner, change priority, delete) and **Export** to
CSV/XLSX. Bulk actions apply deal by deal: deals that can't take the change (a
stage requiring fields they lack, no permission) are skipped and listed with
the reason.

## The deal panel

Clicking a card opens the **side panel** (the same content is the full page at
`/crm/deals/{id}`, the target of links in notifications and emails):

- **Next step and next activity** on top.
- **Fields**: stage, amounts (one-off, recurring, deposit), offer, dates,
  priority, tags, source, operational notes, signature and payment with
  evidence, approval, commission, custom fields.
- **People** of the deal: add an existing or new person, set the role, make
  primary, remove.
- **Quick actions**: write a note (with `@mentions`), plan an activity, **log a
  call** already made, send an **email** (with templates and variables, or
  reply to an email of the timeline), upload a **document**.
- **Open activities**, with complete / edit / delete.
- The **timeline** with a tab and a counter per kind (notes, activities,
  emails, changes, documents and your [providers](/concepts/timeline)). Notes
  can be pinned, emails replied to, done activities reopened.
- **Delete** the deal (managers).

## Agenda

The current user's activities **overdue**, **today** and in the **next seven
days**, each with its deal, company or person and a one-click "done". The
navigation shows the number of activities due today or overdue.

## Companies and people

Searchable lists of the workspace's companies and people, with a form to create
one (a possible **duplicate** is shown instead of creating it, unless the user
confirms it's a different one). Each company and person has a page with its
details, its deals (and people, for a company), notes, activities, documents
(companies) and timeline.

## Reports

The dashboard described in [Reports](/concepts/reports): open pipeline,
forecast, won/lost and win rate, lost reasons, time per stage, attention
points, activities, owners and sources, filtered by workspace, owner and
period.

## Import

The four-step wizard of [Import & export](/concepts/import-export), visible to
managers.

## Settings

Everybody can see the settings of their workspace; **managers** change them:

- **Pipelines**: create, rename, activate/deactivate, delete (empty ones).
- **Stages**: name, colour, kind, probability, rotting days, required fields,
  automatic activity on enter; reorder; delete (empty ones).
- **Custom fields** of the deals (text, number, date, yes/no, choice).
- **Email templates** with variables.
- **Entry stage** for deals created by integrations (`createDealFrom()`).

## Other routes

Registered with the UI (under the same prefix and middleware unless noted):

| Route | Purpose |
|---|---|
| `GET /crm/documents/{document}` (`sales-crm.documents.download`) | Download a document (policy checked). |
| `GET /crm/export/deals` (`sales-crm.deals.export`) | Export the deal list. |
| `GET /crm/imports/{import}/rejected` (`sales-crm.imports.rejected`) | Rejected rows of an import as CSV. |
| `GET /crm/shared/quotes/{document}` (`sales-crm.documents.signed`) | Signed, temporary link to a quote; no login, rate limited. |
| `GET /crm/assets/sales-crm.css` (`sales-crm.assets.css`) | The precompiled stylesheet (long cache, versioned URL). |
| `POST /sales-crm/inbound` (`sales-crm.inbound`) | The [inbound email webhook](/concepts/email#webhook) (closed without a secret). |

## Turning the UI off

```dotenv
SALES_CRM_UI=false
```

The pages and the downloads under the prefix are no longer registered. The
headless core, the API and the MCP tools keep working. Without Livewire
installed the pages are simply not registered.

## Best practices

- **Link the CRM from your app's navigation** with `route('sales-crm.deals')`.
- **Render the CRM inside your own layout** if you want your header and
  navigation around it: see [Customizing the UI](/extending/ui).
- **Teach the team the shortcuts** (`?` shows them): moving cards with `[` and
  `]` is fast.

## Common pitfalls

- `/crm` gives 404: Livewire isn't installed, `SALES_CRM_UI` is `false`, or the
  route cache is stale (`php artisan route:clear`).
- `/crm` gives 403: the user doesn't pass the `sales-crm.access` gate.
- Styles missing in your own layout: add `<x-sales-crm::styles />` to its
  `<head>`.
