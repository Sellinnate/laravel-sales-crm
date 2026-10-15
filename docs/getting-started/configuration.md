---
title: "Configuration"
description: "Every section of config/sales-crm.php explained: users, workspaces, authorization, database, money, pipeline, deals, activities, notifications, email, documents, import, UI, API, MCP and timeline."
---

# Configuration

All settings live in **`config/sales-crm.php`**, published by
`php artisan sales-crm:install` (or `vendor:publish --tag=sales-crm-config`).
This page walks through the file section by section, in the same order.

::: callout info "Config file or .env?"
Values that change between environments (credentials, mail addresses, feature
switches) read an `env()` variable: set them in `.env`. Structural choices (the
default stages, the list of free-mail domains) are plain PHP: edit the file.
After changing either in production, run `php artisan config:cache` again.
:::

## Environment variables at a glance

| Variable | Config key | Default |
|---|---|---|
| `SALES_CRM_USER_MODEL` | `user_model` | the model of the default auth provider |
| `SALES_CRM_DEAL_VISIBILITY` | `authorization.deal_visibility` | `workspace` |
| `SALES_CRM_DB_CONNECTION` | `database.connection` | default connection |
| `SALES_CRM_TABLE_PREFIX` | `database.table_prefix` | `crm_` |
| `SALES_CRM_CURRENCY` | `currency` | `EUR` |
| `SALES_CRM_LOCALE` | `locale` | `app.locale` |
| `SALES_CRM_PHONE_REGION` | `phone.default_region` | `IT` |
| `SALES_CRM_PHONE_CALLING_CODE` | `phone.default_calling_code` | `39` |
| `SALES_CRM_NOTIFICATIONS_QUEUE` | `notifications.queue` | default queue |
| `SALES_CRM_MAILER` | `email.mailer` | default mailer |
| `SALES_CRM_MAIL_FROM_ADDRESS` | `email.from.address` | `mail.from.address` |
| `SALES_CRM_MAIL_FROM_NAME` | `email.from.name` | `mail.from.name` |
| `SALES_CRM_MAIL_REPLY_TO` | `email.reply_to` | `user` |
| `SALES_CRM_EMAIL_QUEUE` | `email.queue` | default queue |
| `SALES_CRM_INBOUND_DRIVER` | `email.inbound.driver` | `null` |
| `SALES_CRM_INBOUND_SECRET` | `email.inbound.webhook_secret` | none (webhook closed) |
| `SALES_CRM_IMAP_HOST`, `_PORT`, `_ENCRYPTION`, `_USERNAME`, `_PASSWORD` | `email.inbound.imap.*` | port `993`, `ssl` |
| `SALES_CRM_DOCUMENTS_DISK` | `documents.disk` | `local` |
| `SALES_CRM_IMPORT_DISK` | `import.disk` | `local` |
| `SALES_CRM_IMPORT_QUEUE` | `import.queue` | default queue |
| `SALES_CRM_UI` | `ui.enabled` | `true` |
| `SALES_CRM_PREFIX` | `ui.prefix` | `crm` |
| `SALES_CRM_BRAND` | `ui.brand` | `Sales CRM` |
| `SALES_CRM_API` | `api.enabled` | `false` |
| `SALES_CRM_MCP` | `mcp.enabled` | `false` |

## Users

```php
'user_model' => env('SALES_CRM_USER_MODEL'),

'users' => [
    'name_attribute'  => 'name',
    'email_attribute' => 'email',
    'scope'           => null,
],
```

- **`user_model`**: the Eloquent model of the people who work the CRM (deal
  owners, activity assignees, approvers, note authors). When `null`, the model
  of your default auth guard's provider is used.
- **`name_attribute` / `email_attribute`**: the attributes read to display a
  user, to match `@Full Name` mentions in notes, and to recognise a user's email
  address in inbound mail.
- **`scope`**: the name of an optional [local scope](https://laravel.com/docs/eloquent#local-scopes)
  on your user model that limits **who can be picked** as owner, assignee or
  approver. For example `'salesTeam'` calls `scopeSalesTeam()`:

```php
// app/Models/User.php
public function scopeSalesTeam($query)
{
    return $query->where('department', 'sales');
}
```

## Workspaces

```php
'workspaces' => [
    'resolver'         => SingleWorkspaceResolver::class,
    // RelationWorkspaceResolver only:
    'model'            => null,     // e.g. App\Models\Team::class
    'user_relation'    => null,     // e.g. 'teams'
    'members_relation' => null,     // e.g. 'users'
    'label_attribute'  => 'name',
],
```

A **workspace** is an optional slice of the CRM tied to a model of your app (a
Team, a Client, an Account). `SingleWorkspaceResolver` keeps one shared CRM;
`RelationWorkspaceResolver` reads workspaces from your relations. Full
explanation and examples: [Workspaces](/concepts/workspaces).

## Authorization

```php
'authorization' => [
    'authorizer'      => DefaultAuthorizer::class,
    'access_ability'  => 'sales-crm.access',
    'manage_ability'  => 'sales-crm.manage',
    'deal_visibility' => env('SALES_CRM_DEAL_VISIBILITY', 'workspace'), // workspace | owner
    'policies' => [
        Pipeline::class => PipelinePolicy::class,
        Deal::class     => DealPolicy::class,
        // Company, Person, Activity, Note, Document…
    ],
],
```

- **`authorizer`**: the class implementing the `Authorizer` contract.
- **`access_ability` / `manage_ability`**: the names of the two gates asked by
  the default authorizer. If your app hasn't defined them, every authenticated
  user passes both.
- **`deal_visibility`**: `workspace` (everyone in a workspace sees all its deals)
  or `owner` (users who cannot manage only see their own deals and the
  unassigned ones).
- **`policies`**: the Laravel policies registered for the CRM models; map your
  own subclass to change one rule.

See [Authorization](/concepts/authorization).

## Database

```php
'database' => [
    'connection'         => env('SALES_CRM_DB_CONNECTION'),
    'table_prefix'       => env('SALES_CRM_TABLE_PREFIX', 'crm_'),
    'user_key_type'      => 'int',
    'workspace_key_type' => 'int',
    'user_foreign_keys'  => true,
],
```

- **`connection`**: the database connection of the CRM tables (`null` = default).
- **`table_prefix`**: prepended to every table name (`crm_deals`, `crm_stages`…).
- **`user_key_type`**: `int`, `uuid` or `ulid`, matching your users table.
- **`workspace_key_type`**: `int`, `uuid` or `ulid` (a `string` of up to 64
  characters is also accepted), matching your workspace table.
- **`user_foreign_keys`**: create foreign keys to the users table (set to
  `false` if users live elsewhere).

::: callout warning "Set these before migrating"
The migration reads these values when it runs. Changing them afterwards does not
alter existing tables. See [Installation](/getting-started/installation#key-types).
:::

## Money and formatting

```php
'currency' => env('SALES_CRM_CURRENCY', 'EUR'),
'locale'   => env('SALES_CRM_LOCALE'),       // defaults to app.locale

'phone' => [
    'default_region'       => env('SALES_CRM_PHONE_REGION', 'IT'),
    'default_calling_code' => env('SALES_CRM_PHONE_CALLING_CODE', '39'),
],
```

- **`currency`**: ISO 4217 code used to display amounts (one currency per
  installation).
- **`locale`**: the locale of number and date formatting.
- **`phone.default_region`**: with `giggsey/libphonenumber-for-php` installed,
  national numbers are read as numbers of this region.
- **`phone.default_calling_code`**: without that package, the calling code
  prepended to numbers written without `+` or `00`.

Phone numbers are stored in **E.164** format (`+15551234567`) so that the same
number written differently is recognised as the same person.

## Deals and pipelines

### Default pipeline {#default-pipeline}

```php
'pipeline' => [
    'default_name'   => 'Sales pipeline',
    'default_stages' => [
        ['name' => 'New lead', 'color' => 'neutral', 'probability' => 10, 'kind' => 'open', 'rotting_days' => 7],
        // ...
        [
            'name' => 'Proposal sent', 'color' => 'accent', 'probability' => 60, 'kind' => 'open', 'rotting_days' => 14,
            'required_fields'   => ['amount', 'offer_valid_until'],
            'on_enter_activity' => ['type' => 'call', 'title' => 'Follow up on the proposal', 'due_in_days' => 3],
        ],
        // ...
        ['name' => 'Won',  'color' => 'success', 'probability' => 100, 'kind' => 'won'],
        ['name' => 'Lost', 'color' => 'danger',  'probability' => 0,   'kind' => 'lost'],
    ],
],
```

The pipeline every workspace (or the shared CRM) gets **the first time it is
used**. Changing this list affects new workspaces only; existing pipelines are
edited from the settings page. Each stage accepts:

| Key | Values |
|---|---|
| `name` | up to 80 characters |
| `color` | `neutral`, `brand`, `info`, `accent`, `warning`, `success`, `danger` |
| `probability` | 0–100 (forced to 100 for `won`, 0 for `lost`) |
| `kind` | `open`, `won`, `lost` |
| `rotting_days` | days without activity after which an open deal is "stale" (open stages only) |
| `required_fields` | any of `company`, `person`, `owner`, `amount`, `expected_close_date`, `offer_valid_until`, `payment_terms`, `next_activity`, `offer_document` |
| `on_enter_activity` | `['type' => call/email/meeting/deadline/task, 'title' => '…', 'due_in_days' => n]` |

::: callout warning "Keep one open, one won and one lost stage"
If the configured list doesn't contain at least one stage of each kind, the CRM
falls back to three stages (New, Won, Lost). Details in
[Pipelines & stages](/concepts/pipelines).
:::

### Deals

```php
'deals' => [
    'cards_per_column'    => 50,   // "Show more" loads the next batch
    'closed_visible_days' => 90,   // won/lost deals older than this leave the board
    'offer_expiring_days' => 7,
    'max_tags'            => 10,
    'custom_fields_limit' => 20,
],
```

- **`cards_per_column`**: cards loaded per column on the board (minimum 5).
- **`closed_visible_days`**: won and lost deals closed more than this many days
  ago are hidden from the board and list (unless the "all" filter is chosen).
- **`offer_expiring_days`**: an offer expiring within these days is flagged as
  "expiring".
- **`max_tags`**: maximum tags per deal (each tag up to 30 characters).
- **`custom_fields_limit`**: maximum custom fields per workspace.

### Companies

```php
'companies' => [
    'shared_site_hosts' => ['facebook.com', 'instagram.com', 'linkedin.com', /* … */],
    'free_mail_domains' => ['gmail.com', 'outlook.com', 'yahoo.com', /* … */],
],
```

Used by de-duplication. A company's **domain** comes from its website, unless
the host is a platform shared by many companies (`shared_site_hosts`), or else
from its email, unless it's a free mail provider (`free_mail_domains`).
Subdomains of listed hosts are excluded too. See
[Companies & people](/concepts/contacts#de-duplication).

## Activities and reminders

```php
'activities' => [
    'reminder_options' => [0, 15, 60, 1440],
    'day_only_hour'    => '09:00',
    'stale_minutes'    => 60,
],
```

- **`reminder_options`**: the allowed values of `remind_minutes` (minutes before
  the due time; `0` = at the due time, `1440` = a day before).
- **`day_only_hour`**: the hour used for an activity without a time (the
  reminder is computed from it).
- **`stale_minutes`**: reminders older than this are skipped instead of sent
  late (the agenda still shows the activity).

Reminders are sent by `sales-crm:remind-activities`, which must be scheduled
[every minute](/getting-started/installation).

## Notifications {#notifications}

```php
'notifications' => [
    'channels' => ['database', 'mail'],
    'queue'    => env('SALES_CRM_NOTIFICATIONS_QUEUE'),
],
```

The channels of the built-in notifications (activity due, mention, approval
requested). Use `['mail']` if you have no notifications table, or `[]` to send
none and rely on the [events](/concepts/events) only.

## Email

```php
'email' => [
    'enabled'        => true,
    'mailer'         => env('SALES_CRM_MAILER'),
    'from'           => ['address' => env('SALES_CRM_MAIL_FROM_ADDRESS'), 'name' => env('SALES_CRM_MAIL_FROM_NAME')],
    'from_name'      => 'user',     // "user" or "config"
    'reply_to'       => env('SALES_CRM_MAIL_REPLY_TO', 'user'),
    'max_recipients' => 10,
    'queue'          => env('SALES_CRM_EMAIL_QUEUE'),

    'inbound' => [
        'driver'         => env('SALES_CRM_INBOUND_DRIVER', 'null'),   // null | webhook | imap
        'webhook_secret' => env('SALES_CRM_INBOUND_SECRET'),
        'webhook_path'   => 'sales-crm/inbound',
        'imap' => [
            'host' => env('SALES_CRM_IMAP_HOST'), 'port' => (int) env('SALES_CRM_IMAP_PORT', 993),
            'encryption' => env('SALES_CRM_IMAP_ENCRYPTION', 'ssl'),
            'username' => env('SALES_CRM_IMAP_USERNAME'), 'password' => env('SALES_CRM_IMAP_PASSWORD'),
            'folders' => ['INBOX'], 'sent_folders' => [], 'batch' => 50,
        ],
    ],
],
```

Outbound:

- **`enabled`**: `false` disables sending email from the deals.
- **`mailer`**: a mailer of `config/mail.php` (`null` = default).
- **`from.address` / `from.name`**: the From of CRM emails; fall back to
  `mail.from.*`. An address is mandatory to send.
- **`from_name`**: `user` shows the sender's name ("Jane Doe"), `config` uses
  `from.name`.
- **`reply_to`**: `user` (replies go to the sender's own address), `from` (no
  Reply-To header), or an **email address** read by an inbound driver so replies
  land on the deal's timeline.
- **`max_recipients`**: maximum addresses in To + Cc of one email.
- **`queue`**: the queue of the delivery job.

Inbound:

- **`driver`**: `null` (off), `imap` (polls a mailbox) or `webhook` (receives
  JSON POSTs).
- **`webhook_secret`**: the HMAC secret of the webhook; without it the webhook
  answers 404.
- **`webhook_path`**: the URL path of the webhook (`POST /sales-crm/inbound`).
- **`imap.*`**: connection to the mailbox; `folders` are read for incoming mail,
  `sent_folders` for emails users sent from their own client, `batch` is the
  number of messages read per folder per run.

Everything about email: [Email](/concepts/email).

## Documents

```php
'documents' => [
    'disk'             => env('SALES_CRM_DOCUMENTS_DISK', 'local'),
    'directory'        => 'sales-crm',
    'max_kilobytes'    => 20480,
    'mimes'            => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'txt', 'csv', 'png', 'jpg', 'jpeg', 'webp'],
    'signed_link_days' => 30,
],
```

- **`disk` / `directory`**: where uploaded files are stored. Use a **private**
  disk: files are only downloaded through the CRM, after a permission check.
- **`max_kilobytes`** and **`mimes`**: upload limits.
- **`signed_link_days`**: validity of the signed link to a quote sent with the
  `{{quote_link}}` email variable.

## Import and export

```php
'import' => [
    'disk'          => env('SALES_CRM_IMPORT_DISK', 'local'),
    'directory'     => 'sales-crm/imports',
    'max_kilobytes' => 10240,
    'queue'         => env('SALES_CRM_IMPORT_QUEUE'),
    'preview_rows'  => 10,
],
```

Where uploaded sheets are kept, their size limit, the queue of the import job
and how many rows the preview shows. CSV works out of the box; XLSX needs
`openspout/openspout`. See [Import & export](/concepts/import-export).

## Web UI {#web-ui}

```php
'ui' => [
    'enabled'      => env('SALES_CRM_UI', true),
    'prefix'       => env('SALES_CRM_PREFIX', 'crm'),
    'domain'       => null,
    'middleware'   => ['web', 'auth'],
    'layout'       => 'sales-crm::layouts.app',
    'home_url'     => '/',
    'poll_seconds' => 15,
    'brand'        => env('SALES_CRM_BRAND', 'Sales CRM'),
],
```

- **`enabled`**: register the UI pages (they also need Livewire).
- **`prefix`** / **`domain`**: where the pages live (`/crm`, or a subdomain).
- **`middleware`**: applied to every page and download.
- **`layout`**: the Blade layout pages render in. To use yours, render
  `{{ $slot }}` and add `<x-sales-crm::styles />` to its `<head>`.
- **`home_url`**: the "back to the app" link in the CRM header (`null` hides it).
- **`poll_seconds`**: how often an open board checks for changes made by others
  (minimum 5).
- **`brand`**: the name shown in the header and the page title.

See [Web UI](/interfaces/ui) and [Customizing the UI](/extending/ui).

## JSON API

```php
'api' => [
    'enabled'    => env('SALES_CRM_API', false),
    'prefix'     => 'api/crm',
    'middleware' => ['api', 'auth:sanctum'],
],
```

Off by default. See [JSON API](/interfaces/api).

## MCP tools

```php
'mcp' => [
    'enabled'    => env('SALES_CRM_MCP', false),
    'path'       => 'mcp/crm',
    'middleware' => ['auth:sanctum'],
],
```

Off by default; also needs `laravel/mcp`. See [MCP tools](/interfaces/mcp).

## Timeline providers

```php
'timeline' => [
    'providers' => [],
    'limit'     => 80,
],
```

- **`providers`**: classes implementing `TimelineProvider` whose entries are
  mixed into the timelines (calls, tickets, orders from your systems).
- **`limit`**: the maximum number of entries a timeline shows (minimum 10).

See [Timeline](/concepts/timeline).

## Best practices

- **Decide key types and prefix first**, then migrate.
- **Restrict access** with the `sales-crm.access` and `sales-crm.manage` gates
  before going live: by default everyone who can log in can use and configure
  the CRM.
- **Use a private disk** for documents and imports (never `public`).
- **Set a dedicated reply-to address** with an inbound driver if you want
  replies on the timeline.
- **Run the scheduler and a queue worker** in production.

## Common pitfalls

- Editing `default_stages` and expecting existing pipelines to change: they
  don't. Use the settings page.
- Forgetting `config:cache` after changing `.env` in production.
- Setting `reply_to` to `user` and wondering why replies never appear: they go to
  the user's personal mailbox, which the CRM doesn't read.
