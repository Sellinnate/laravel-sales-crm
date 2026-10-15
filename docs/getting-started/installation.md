---
title: "Installation"
description: "Add the Sales CRM package to a Laravel app, choose the key types, run the migrations, schedule the commands and add the optional packages."
---

# Installation

This page takes you from `composer require` to a working CRM at `/crm`. It takes
about ten minutes. You need an existing Laravel app with users who can log in.

## Requirements

| Requirement | Version | Why |
|---|---|---|
| PHP | **8.2+** | Enums, readonly properties, first-class callables. |
| Laravel | **11, 12 or 13** | Package discovery, queues, notifications, Eloquent. |
| Database | **MySQL, PostgreSQL or SQLite** | The CRM tables (window functions are used by the board, so use MySQL 8+). |
| Cache | any store that supports **atomic locks** (redis, database, memcached, file, dynamodb) | Moves on the board and de-duplication of contacts run under cache locks. |
| Livewire | **3 or 4**, optional | Only for the [web UI](/interfaces/ui). |

The PHP `intl` extension is recommended: with it amounts are formatted for the
configured locale and currency.

## Step 1: install with Composer

```bash
composer require sellinnate/sales-crm
```

Laravel's **package discovery** registers the service provider and the
`SalesCrm` facade automatically. Nothing to add to `config/app.php` or
`bootstrap/providers.php`.

## Step 2: publish the configuration

```bash
php artisan sales-crm:install --no-migrate
```

This copies `config/sales-crm.php` and the migration `create_sales_crm_tables`
into your app, without running it yet. The command accepts these options:

| Option | Effect |
|---|---|
| `--migrate` | Run the migrations right after publishing, without asking. |
| `--no-migrate` | Do not run the migrations and do not ask. |
| `--demo` | Seed demo data: the default pipeline with about thirty fictitious deals, companies, people, activities and notes. Refused in production unless you also pass `--force`. |
| `--force` | Overwrite files already published (and allow `--demo` in production). |

Without `--migrate` or `--no-migrate`, an interactive run asks "Run the
migrations now?". See [Artisan commands](/reference/commands) for details.

::: callout tip "Prefer vendor:publish?"
The install command is a shortcut. These tags do the same thing one by one:
`php artisan vendor:publish --tag=sales-crm-config` and
`php artisan vendor:publish --tag=sales-crm-migrations`.
:::

## Step 3: set the key types BEFORE migrating {#key-types}

The CRM stores references to your users (deal owners, assignees, authors) and,
optionally, to your workspace model (a Team, an Account…). The column types must
match the primary keys of those tables, and **they are fixed when the migration
runs**. Open `config/sales-crm.php` and check the `database` section:

```php
'database' => [
    'connection'         => env('SALES_CRM_DB_CONNECTION'),        // null = default connection
    'table_prefix'       => env('SALES_CRM_TABLE_PREFIX', 'crm_'),
    'user_key_type'      => 'int',   // int | uuid | ulid
    'workspace_key_type' => 'int',   // int | uuid | ulid
    'user_foreign_keys'  => true,    // foreign keys to your users table
],
```

- Your `users` table uses `$table->id()`? Keep `int`.
- Your `User` model uses `HasUuids` or `HasUlids`? Set `uuid` or `ulid`.
- Same reasoning for `workspace_key_type` if you plan to use
  [workspaces](/concepts/workspaces).
- If your users live on another connection or you don't want database-level
  foreign keys, set `user_foreign_keys` to `false`: the columns get a plain index
  instead.

The CRM finds your user model from the default auth guard. If that is not the
model of the people who work the CRM, set it explicitly:

```dotenv
SALES_CRM_USER_MODEL="App\Models\User"
```

::: callout warning "Changing key types later means a new migration"
Once the tables exist, editing `user_key_type` does nothing to them. Decide
before the first `migrate`. The same goes for `table_prefix`.
:::

## Step 4: run the migrations

```bash
php artisan migrate
```

This creates sixteen tables prefixed with `crm_` (pipelines, stages, deals,
companies, people, activities, notes, documents, emails and more). The
[Database schema](/reference/database) page describes each one.

Want to try it with data? Seed the demo:

```bash
php artisan sales-crm:install --no-migrate --demo
```

The demo needs at least one user in your database: owners are picked among
existing users, no user is created.

## Step 5: install the web UI (optional)

The UI is built with Livewire. If your app doesn't have it yet:

```bash
composer require livewire/livewire
```

Log in and open **`/crm`**. The UI ships its own precompiled CSS, served by the
package: there is **no npm build step** in your app. See
[Web UI](/interfaces/ui) for a tour and [Customizing the UI](/extending/ui) to
render it inside your own layout.

## Step 6: schedule the commands

Activity reminders and inbound email polling are Artisan commands. Add them to
the scheduler in `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

// Reminders of the activities (required for reminders to be sent).
Schedule::command('sales-crm:remind-activities')->everyMinute();

// Only if you read replies over IMAP (SALES_CRM_INBOUND_DRIVER=imap).
Schedule::command('sales-crm:poll-inbox')->everyFiveMinutes()->withoutOverlapping();
```

Make sure the scheduler itself runs (`* * * * * php artisan schedule:run` in
cron, or `php artisan schedule:work` locally).

## Step 7: run a queue worker

Emails sent from the deals, the notifications and the imports are **queued
jobs**. With `QUEUE_CONNECTION=sync` they run immediately (fine locally); in
production run a worker:

```bash
php artisan queue:work
```

You can send each kind to its own queue with `SALES_CRM_EMAIL_QUEUE`,
`SALES_CRM_NOTIFICATIONS_QUEUE` and `SALES_CRM_IMPORT_QUEUE`.

## Step 8: notifications table

By default users are notified (reminders, mentions, approval requests) on the
`database` and `mail` channels. The `database` channel needs Laravel's
notifications table and the `Notifiable` trait on your user model:

```bash
php artisan make:notifications-table
php artisan migrate
```

Don't want notifications at all, or only by mail? Change
`sales-crm.notifications.channels` (see [Configuration](/getting-started/configuration#notifications)).

## Optional packages

Each optional feature turns on when its package is installed. Nothing else to
configure.

| Package | Enables |
|---|---|
| `livewire/livewire` (^3.5 or ^4.0) | The [web UI](/interfaces/ui). |
| `openspout/openspout` | XLSX [import and export](/concepts/import-export) (CSV works without it). |
| `laravel/mcp` | The [MCP tools](/interfaces/mcp) for AI agents. |
| `directorytree/imapengine` | The `imap` [inbound driver](/concepts/email#inbound-email). |
| `giggsey/libphonenumber-for-php` | Full phone number validation and E.164 normalisation. |

```bash
composer require openspout/openspout giggsey/libphonenumber-for-php
```

## Step 9: restrict who uses the CRM

Until you decide otherwise **every authenticated user can use and configure the
CRM**. Define the two gates in a service provider to restrict it:

```php
// app/Providers/AppServiceProvider.php
use Illuminate\Support\Facades\Gate;

public function boot(): void
{
    Gate::define('sales-crm.access', fn ($user) => $user->is_sales);
    Gate::define('sales-crm.manage', fn ($user, $workspace = null) => $user->is_admin);
}
```

[Authorization](/concepts/authorization) explains the rules in detail.

## Verify the install

```bash
php artisan tinker
```

```php
use Sellinnate\SalesCrm\Facades\SalesCrm;

$pipeline = SalesCrm::shared()->defaultPipeline();   // created on first use
$pipeline->stages->pluck('name');
// ["New lead", "Qualified", "Meeting / demo", "Proposal sent", "Negotiation", "Signature", "Won", "Lost"]
```

If you see the stages, the core works. Open `/crm` to check the UI.

## Troubleshooting

| Symptom | Fix |
|---|---|
| `sales-crm: set sales-crm.user_model…` | The CRM could not find your user model: set `SALES_CRM_USER_MODEL`. |
| Migration fails on a foreign key to `users` | The key type doesn't match (`uuid` vs `int`), or users are on another connection: fix `user_key_type` or set `user_foreign_keys` to `false`. |
| `/crm` returns 404 | Livewire is not installed, `SALES_CRM_UI=false`, or your routes are cached from before the install (`php artisan route:clear`). |
| `/crm` redirects to `/login` | The UI uses the `web` and `auth` middleware: log in first, or change `sales-crm.ui.middleware`. |
| Emails stay "queued" | No queue worker is running. |
| No reminders | The scheduler doesn't run `sales-crm:remind-activities`, or the activity has no reminder set. |
| Queued notifications fail | The `notifications` table is missing: run `php artisan make:notifications-table`. |

## Next steps

1. **[Quickstart](/getting-started/quickstart)**: work a deal end to end.
2. **[Configuration](/getting-started/configuration)**: every option.
