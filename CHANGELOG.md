# Changelog

All notable changes to `sellinnate/sales-crm` will be documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and
the project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.0.0] - unreleased

First stable release.

### Core

- Pipelines with stages (colour, probability, kind open/won/lost, rotting days,
  required fields, activity created on entry) and a default pipeline created
  from config on first use.
- Deals with one-off and recurring amounts, deposit, priority, source, tags,
  custom fields, next step, blocker, approval requests, won/lost reasons, stage
  history (`stage_visits`) and an event log (`deal_events`).
- Idempotent bridge from host models: `SalesCrm::for($workspace)->createDealFrom($model, [...])`.
- Companies and people with de-duplication (VAT number, domain, email, phone,
  name) that ignores free-mail domains and shared sites.
- Activities with due date/time, reminders (`sales-crm:remind-activities`) and
  the overdue/stale state of every deal; notes with @mentions; documents with
  versioned quotes and temporary signed links.
- Outbound email through Laravel's mailer with threading headers, templates
  with placeholders and a `SenderResolver` contract; inbound email through the
  `null`, `webhook` (HMAC-signed) and `imap` drivers or
  `SalesCrm::inbound()->receive()`, with thread and contact matching and
  auto-reply/bounce filtering.
- CSV/XLSX import with mapping suggestions (English and Italian headers),
  preview, queued run and a file of rejected rows; CSV/XLSX export with
  formula neutralisation.
- Reports: open pipeline by stage, forecast, won/lost and win rate, lost
  reasons, time in stage, deals needing attention, activities, owners, sources.
- Timeline of deals, companies and people with counters per filter and the
  `TimelineProvider` contract for host entries.
- Optional polymorphic workspaces (`WorkspaceResolver`: single shared CRM or
  relation-based), configurable user model, integer/UUID/ULID keys, table
  prefix and database connection.
- Authorization through the `Authorizer` contract, the `sales-crm.access` and
  `sales-crm.manage` gates and configurable policies; deal visibility per
  workspace or per owner.
- 22 events dispatched after commit and database/mail notifications for
  reminders, mentions and approval requests.
- Fluent PHP API (`SalesCrm` facade), `sales-crm:install` (config, migrations,
  `--demo` seed), `sales-crm:poll-inbox`.

### Interfaces

- Optional Livewire 3/4 UI: kanban board with drag & drop, filters, saved views
  and list view with bulk actions; deal panel and page; agenda; companies and
  people; reports; import wizard; settings for pipelines, stages, custom fields
  and email templates; initial guide. Own Blade components and precompiled
  Tailwind CSS, English and Italian translations.
- Optional MCP server with nine tools (requires `laravel/mcp`).
- Optional JSON API for pipelines, deals, notes and activities.

[Unreleased]: https://github.com/Sellinnate/laravel-sales-crm/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/Sellinnate/laravel-sales-crm/releases/tag/v1.0.0
