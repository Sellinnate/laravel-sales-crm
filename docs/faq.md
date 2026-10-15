---
title: "FAQ"
description: "Answers to common questions: Livewire, multi-tenancy, UUID users, table prefix, Octane, limiting users to the sales team, inbound email, and what is not included."
---

# FAQ

## Does it work without Livewire?

Yes. Livewire is only needed for the [web UI](/interfaces/ui). Without it the
headless core works through the [PHP API](/reference/php-api), the
[JSON API](/interfaces/api) and the [MCP tools](/interfaces/mcp), and you can
build your own screens on top of the models and actions. The UI pages are
simply not registered.

## Can I use my own front end (Inertia, React, Vue, Filament…)?

Yes. Call the facade from your controllers (or enable the JSON API) and turn the
bundled UI off with `SALES_CRM_UI=false` if you don't want it. You keep all the
rules (required fields, de-duplication, permissions, events) because they live
in the actions, not in the UI.

## Does it support multi-tenancy?

Yes, through [workspaces](/concepts/workspaces). Configure
`RelationWorkspaceResolver` with your Team/Account model and its relations, or
write your own `WorkspaceResolver`. Each workspace has its own pipelines,
contacts, settings and templates, and users only see the workspaces they belong
to. Without workspaces the app has one shared CRM.

## My users have UUID (or ULID) primary keys. Is that supported?

Yes. Set `sales-crm.database.user_key_type` to `uuid` or `ulid` **before
running the migration**. The same goes for `workspace_key_type` if your
workspace model uses UUIDs. See [Installation](/getting-started/installation#key-types).

## Can I change the table prefix?

Yes: `SALES_CRM_TABLE_PREFIX` (default `crm_`), **before** migrating. If the
tables already exist, the setting alone won't rename them: write a migration
that renames the sixteen tables, then change the prefix.

## Can the CRM tables live in another database?

Yes: set `SALES_CRM_DB_CONNECTION` to a connection of `config/database.php`.
If your users table is on a different connection, set
`sales-crm.database.user_foreign_keys` to `false` (cross-database foreign keys
aren't possible).

## Does it work with Laravel Octane?

The workspace resolver, the authorizer and the access service are bound as
**scoped** services, so they are rebuilt for every request (no user leaks from
one request to the next). Concurrency is handled with cache locks: use a cache
store shared by all your servers (Redis, database, Memcached) and supporting
atomic locks.

## How do I limit owners and assignees to the sales team?

Add a local scope to your user model and name it in `sales-crm.users.scope`:

```php
// app/Models/User.php
public function scopeSalesTeam($query)
{
    return $query->where('department', 'sales');
}
```

```php
// config/sales-crm.php
'users' => ['name_attribute' => 'name', 'email_attribute' => 'email', 'scope' => 'salesTeam'],
```

Only those users can then be picked as owner, assignee or approver, and
mentioned in notes. To limit who can **open** the CRM, define the
`sales-crm.access` gate ([Authorization](/concepts/authorization)).

## Can salespeople see only their own deals?

Yes: `SALES_CRM_DEAL_VISIBILITY=owner`. Users who can't manage the workspace see
only the deals they own and the unassigned ones; managers see everything.

## Who can delete deals and change the pipeline?

Users who pass the `sales-crm.manage` gate for the workspace. Until you define
that gate, every authenticated user can.

## Where do inbound emails go?

To the deal whose thread they answer (by `In-Reply-To`/`References`); else to
the person who sent them and their most recent open deal; else, when one of
your users sent them to a person of the CRM (BCC dropbox), they're recorded as
outbound. Auto-replies, bounces and unrelated mail are ignored. Details in
[Email](/concepts/email#inbound-email).

## Why don't customer replies show up?

Usually because replies go to the user's personal mailbox (`reply_to = user`),
which the CRM doesn't read. Use a dedicated inbox as Reply-To
(`SALES_CRM_MAIL_REPLY_TO`) and read it with the IMAP driver or a webhook.

## Can I send newsletters or email campaigns?

No. Emails are written one at a time from a deal, to at most
`sales-crm.email.max_recipients` addresses. Use a dedicated marketing tool for
campaigns.

## Can it make or record phone calls?

No, there's no telephony. Users can **log a call** they made, and you can show
calls from your telephony system on the timelines with a
[timeline provider](/concepts/timeline#timeline-providers).

## Does it generate quotes or invoices?

No. You upload quotes and contracts as [documents](/concepts/notes-documents);
the latest quote can be sent with a signed link (`{{quote_link}}`), and its
validity becomes the deal's offer validity.

## Can I use several currencies?

One currency per installation: `SALES_CRM_CURRENCY` (ISO 4217, default `EUR`).
Amounts are stored without a currency column.

## Which languages does the UI support?

English and Italian ship with the package; the UI follows the app locale. Add
other languages by publishing the translations
(`php artisan vendor:publish --tag=sales-crm-translations`) and creating a new
folder under `lang/vendor/sales-crm/`. See [Customizing the UI](/extending/ui#translations).

## Can I add my own fields to deals?

Yes: managers define **custom fields** per workspace in Settings (text, number,
date, yes/no, choice). They're validated, shown in the panel and the list,
importable and exported. From code they're passed under `custom_values`.

## How do I react when a deal is won?

Listen to the `DealWon` event. All [events](/concepts/events) are dispatched
after the database commit.

## Are reminders sent automatically?

Only if the scheduler runs `sales-crm:remind-activities` every minute and a
queue worker delivers the notifications. See
[Activities & reminders](/concepts/activities#reminders).

## Is demo data available?

`php artisan sales-crm:install --demo` seeds fictitious deals, companies,
people, activities and notes into the shared CRM (never in production unless
forced). With workspaces, seed them from code; see
[Workspaces](/concepts/workspaces#demo-data-with-workspaces).

## What is not included?

- Mass mailing, campaigns and marketing automation.
- Telephony (calling, recording); bring calls in with a timeline provider.
- Quote/invoice generation and e-signature.
- Calendar synchronisation.
- Multiple currencies in one installation.

The package focuses on the sales pipeline: deals, contacts, activities, notes,
documents, one-to-one email, import/export and reports.
