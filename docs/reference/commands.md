---
title: "Artisan commands"
description: "The three Artisan commands of the package: sales-crm:install, sales-crm:remind-activities and sales-crm:poll-inbox, with their options and scheduling."
---

# Artisan commands

## sales-crm:install

Publishes the configuration and the migration, optionally runs the migrations
and seeds demo data.

```bash
php artisan sales-crm:install [--migrate] [--no-migrate] [--demo] [--force]
```

| Option | Effect |
|---|---|
| `--migrate` | Run `migrate` after publishing, without asking. |
| `--no-migrate` | Don't run the migrations and don't ask. |
| `--demo` | Seed demo data (`DemoSeeder`): the default pipeline of the shared CRM with about thirty fictitious deals across the stages, plus companies, people, activities and notes. Owners are picked among existing users; no user is created. |
| `--force` | Overwrite the config and migration files already published. Also allows `--demo` in production. |

What it does, in order:

1. `vendor:publish --tag=sales-crm-config` → `config/sales-crm.php`
2. `vendor:publish --tag=sales-crm-migrations` → `database/migrations/…_create_sales_crm_tables.php`
3. Runs `migrate` if `--migrate`, or asks "Run the migrations now?" in an
   interactive terminal (unless `--no-migrate`).
4. With `--demo`, seeds the demo data (refused in production without
   `--force`).
5. Prints the next steps.

::: callout tip "Set key types before migrating"
Run with `--no-migrate` first, check `database.user_key_type`,
`workspace_key_type` and `table_prefix` in the published config, then
`php artisan migrate`. See [Installation](/getting-started/installation#key-types).
:::

## sales-crm:remind-activities

Sends the reminders of the activities that are due.

```bash
php artisan sales-crm:remind-activities
```

No options. For each open activity with a reminder (`remind_minutes`), an
assignee and a reminder time that has come (and a due date within the next two
days), it:

- marks the activity as reminded, so overlapping runs never send twice;
- skips it if the reminder is older than `sales-crm.activities.stale_minutes`
  or the assignee no longer works in the workspace;
- fires `ActivityDue` (which, by default, notifies the assignee).

Output: `N reminder(s) sent.`

**Schedule it every minute:**

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('sales-crm:remind-activities')->everyMinute();
```

## sales-crm:poll-inbox

Reads new emails from the inbound driver and stores those that belong to the
CRM (replies to CRM threads, emails from known people, BCC-dropbox emails).

```bash
php artisan sales-crm:poll-inbox [--driver=imap]
```

| Option | Effect |
|---|---|
| `--driver=` | The driver to poll. Default: `sales-crm.email.inbound.driver`. Built-in: `null`, `imap`, `webhook` (push-based, polling reads nothing), plus any driver you registered with `extend()`. |

Output: `N email(s) read, M stored on the CRM.` One broken email never stops
the others (it's reported to your error handler).

**Schedule it** when you use a polling driver (IMAP or a custom one):

```php
Schedule::command('sales-crm:poll-inbox')->everyFiveMinutes()->withoutOverlapping();
```

See [Email](/concepts/email#inbound-email).

## Publishing tags

Not commands of the package, but handy with `php artisan vendor:publish`:

| Tag | Publishes |
|---|---|
| `sales-crm-config` | `config/sales-crm.php` |
| `sales-crm-migrations` | the `create_sales_crm_tables` migration |
| `sales-crm-views` | the Blade views to `resources/views/vendor/sales-crm` |
| `sales-crm-translations` | the translations to `lang/vendor/sales-crm` |
