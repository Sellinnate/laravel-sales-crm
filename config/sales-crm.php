<?php

use Sellinnate\SalesCrm\Authorization\DefaultAuthorizer;
use Sellinnate\SalesCrm\Workspaces\SingleWorkspaceResolver;

return [

    /*
    |--------------------------------------------------------------------------
    | Users
    |--------------------------------------------------------------------------
    |
    | The Eloquent model of the people who work the CRM: deal owners, activity
    | assignees, approvers and the authors of notes. It defaults to the model of
    | the default auth provider. `name_attribute` and `email_attribute` are read
    | to show users and to mention them in notes (@Jane Doe).
    |
    */

    'user_model' => env('SALES_CRM_USER_MODEL'),

    'users' => [
        'name_attribute' => 'name',
        'email_attribute' => 'email',
    ],

    /*
    |--------------------------------------------------------------------------
    | Workspaces (optional multi-tenancy)
    |--------------------------------------------------------------------------
    |
    | Every CRM record may belong to a polymorphic "workspace" (your Team,
    | Company, Client or Account model). Without workspaces the whole app shares
    | one CRM. The resolver decides which workspaces a user sees and who works in
    | each one. Built in:
    |
    | - SingleWorkspaceResolver (default): one shared CRM, no workspace columns used.
    | - RelationWorkspaceResolver: workspaces read from relations you configure below.
    |
    | Or bind your own implementation of
    | Sellinnate\SalesCrm\Contracts\WorkspaceResolver.
    |
    */

    'workspaces' => [
        'resolver' => SingleWorkspaceResolver::class,

        // RelationWorkspaceResolver only.
        'model' => null,                 // e.g. App\Models\Team::class
        'user_relation' => null,         // relation/method on the user returning their workspaces, e.g. 'teams'
        'members_relation' => null,      // relation on the workspace returning its users, e.g. 'users'
        'label_attribute' => 'name',
    ],

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    |
    | The authorizer answers "who sees and changes what". The default one asks
    | two gates you can redefine in your AuthServiceProvider/AppServiceProvider:
    |
    |   Gate::define('sales-crm.access', fn ($user) => ...);             // enter the CRM
    |   Gate::define('sales-crm.manage', fn ($user, $workspace) => ...); // configure it
    |
    | Both allow every authenticated user until you define them. With
    | `deal_visibility` set to "owner", users who cannot manage the CRM only see
    | the deals they own (and the unassigned ones).
    |
    | The policies are regular Laravel policies: extend them and map your class
    | here to change a single rule.
    |
    */

    'authorization' => [
        'authorizer' => DefaultAuthorizer::class,
        'access_ability' => 'sales-crm.access',
        'manage_ability' => 'sales-crm.manage',
        'deal_visibility' => env('SALES_CRM_DEAL_VISIBILITY', 'workspace'), // workspace | owner

        'policies' => [
            Sellinnate\SalesCrm\Models\Pipeline::class => Sellinnate\SalesCrm\Policies\PipelinePolicy::class,
            Sellinnate\SalesCrm\Models\Deal::class => Sellinnate\SalesCrm\Policies\DealPolicy::class,
            Sellinnate\SalesCrm\Models\Company::class => Sellinnate\SalesCrm\Policies\CompanyPolicy::class,
            Sellinnate\SalesCrm\Models\Person::class => Sellinnate\SalesCrm\Policies\PersonPolicy::class,
            Sellinnate\SalesCrm\Models\Activity::class => Sellinnate\SalesCrm\Policies\ActivityPolicy::class,
            Sellinnate\SalesCrm\Models\Note::class => Sellinnate\SalesCrm\Policies\NotePolicy::class,
            Sellinnate\SalesCrm\Models\Document::class => Sellinnate\SalesCrm\Policies\DocumentPolicy::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Database
    |--------------------------------------------------------------------------
    |
    | The prefix of every CRM table (crm_deals, crm_pipelines…). Key types must
    | match your users and workspace tables: int, uuid or ulid. Set them before
    | running the migrations.
    |
    */

    'database' => [
        'connection' => env('SALES_CRM_DB_CONNECTION'),
        'table_prefix' => env('SALES_CRM_TABLE_PREFIX', 'crm_'),
        'user_key_type' => 'int',
        'workspace_key_type' => 'int',
        'user_foreign_keys' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Money and formatting
    |--------------------------------------------------------------------------
    */

    'currency' => env('SALES_CRM_CURRENCY', 'EUR'),

    'locale' => env('SALES_CRM_LOCALE'), // number/date formatting, defaults to app.locale

    'phone' => [
        // Region used to read national numbers when giggsey/libphonenumber-for-php
        // is installed, and calling code prepended to national numbers without it.
        'default_region' => env('SALES_CRM_PHONE_REGION', 'IT'),
        'default_calling_code' => env('SALES_CRM_PHONE_CALLING_CODE', '39'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Deals and pipelines
    |--------------------------------------------------------------------------
    |
    | The pipeline every workspace starts with the first time it is opened. Edit
    | it from the settings page, or change the stages created for new workspaces
    | here. `required_fields` comes from a closed catalogue (see RequiredField).
    |
    */

    'pipeline' => [
        'default_name' => 'Sales pipeline',
        'default_stages' => [
            ['name' => 'New lead', 'color' => 'neutral', 'probability' => 10, 'kind' => 'open', 'rotting_days' => 7],
            ['name' => 'Qualified', 'color' => 'info', 'probability' => 20, 'kind' => 'open', 'rotting_days' => 10],
            ['name' => 'Meeting / demo', 'color' => 'brand', 'probability' => 40, 'kind' => 'open', 'rotting_days' => 10],
            [
                'name' => 'Proposal sent', 'color' => 'accent', 'probability' => 60, 'kind' => 'open', 'rotting_days' => 14,
                'required_fields' => ['amount', 'offer_valid_until'],
                'on_enter_activity' => ['type' => 'call', 'title' => 'Follow up on the proposal', 'due_in_days' => 3],
            ],
            ['name' => 'Negotiation', 'color' => 'warning', 'probability' => 75, 'kind' => 'open', 'rotting_days' => 14],
            ['name' => 'Signature', 'color' => 'info', 'probability' => 90, 'kind' => 'open', 'rotting_days' => 7, 'required_fields' => ['amount', 'owner']],
            ['name' => 'Won', 'color' => 'success', 'probability' => 100, 'kind' => 'won'],
            ['name' => 'Lost', 'color' => 'danger', 'probability' => 0, 'kind' => 'lost'],
        ],
    ],

    'deals' => [
        'cards_per_column' => 50,          // "Show more" loads the next batch
        'closed_visible_days' => 90,       // won/lost deals older than this leave the board
        'offer_expiring_days' => 7,
        'max_tags' => 10,
        'custom_fields_limit' => 20,
    ],

    'companies' => [
        // Hosts shared by many companies: never used to deduplicate by domain.
        'shared_site_hosts' => ['facebook.com', 'instagram.com', 'linkedin.com', 'google.com', 'sites.google.com', 'wixsite.com', 'business.site', 'linktr.ee'],
        'free_mail_domains' => [
            'gmail.com', 'googlemail.com', 'yahoo.com', 'yahoo.it', 'hotmail.com', 'hotmail.it', 'outlook.com', 'outlook.it',
            'live.com', 'live.it', 'msn.com', 'icloud.com', 'me.com', 'mac.com', 'aol.com', 'proton.me', 'protonmail.com',
            'gmx.com', 'gmx.net', 'libero.it', 'virgilio.it', 'tiscali.it', 'alice.it', 'tim.it', 'fastwebnet.it', 'email.it',
            'inwind.it', 'yandex.com', 'mail.com', 'zoho.com',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Activities and reminders
    |--------------------------------------------------------------------------
    |
    | Schedule `sales-crm:remind-activities` every minute to send reminders.
    | Reminders older than `stale_minutes` are skipped (the agenda shows them).
    |
    */

    'activities' => [
        'reminder_options' => [0, 15, 60, 1440],
        'day_only_hour' => '09:00',
        'stale_minutes' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | Channels of the notifications sent to your users (activity reminders,
    | mentions, approval requests). Set an empty array to rely on the events only.
    |
    */

    'notifications' => [
        'channels' => ['database', 'mail'],
        'queue' => env('SALES_CRM_NOTIFICATIONS_QUEUE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Email
    |--------------------------------------------------------------------------
    |
    | Outgoing emails leave through Laravel's mailer. `reply_to` decides where
    | the answers go: "user" (the sender's address), "from", or an address read
    | by an inbound driver so replies land on the deal's timeline.
    |
    | Inbound drivers: "null" (off), "imap" (polls a mailbox, needs
    | directorytree/imapengine) and "webhook" (POST JSON to the inbound route,
    | signed with `webhook_secret`). Hosts can also push emails with
    | SalesCrm::inbound()->receive(InboundEmail::fromArray([...])).
    |
    */

    'email' => [
        'enabled' => true,
        'mailer' => env('SALES_CRM_MAILER'),
        'from' => [
            'address' => env('SALES_CRM_MAIL_FROM_ADDRESS'),
            'name' => env('SALES_CRM_MAIL_FROM_NAME'),
        ],
        'from_name' => 'user', // "user" (Jane Doe), "config" (from.name / mail.from.name)
        'reply_to' => env('SALES_CRM_MAIL_REPLY_TO', 'user'),
        'max_recipients' => 10,
        'queue' => env('SALES_CRM_EMAIL_QUEUE'),

        'inbound' => [
            'driver' => env('SALES_CRM_INBOUND_DRIVER', 'null'),
            'webhook_secret' => env('SALES_CRM_INBOUND_SECRET'),
            'webhook_path' => 'sales-crm/inbound',
            'imap' => [
                'host' => env('SALES_CRM_IMAP_HOST'),
                'port' => (int) env('SALES_CRM_IMAP_PORT', 993),
                'encryption' => env('SALES_CRM_IMAP_ENCRYPTION', 'ssl'),
                'username' => env('SALES_CRM_IMAP_USERNAME'),
                'password' => env('SALES_CRM_IMAP_PASSWORD'),
                'folders' => ['INBOX'],
                'sent_folders' => [],
                'batch' => 50,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Documents
    |--------------------------------------------------------------------------
    */

    'documents' => [
        'disk' => env('SALES_CRM_DOCUMENTS_DISK', 'local'),
        'directory' => 'sales-crm',
        'max_kilobytes' => 20480,
        'mimes' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'txt', 'csv', 'png', 'jpg', 'jpeg', 'webp'],
        'signed_link_days' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Import and export
    |--------------------------------------------------------------------------
    |
    | CSV works out of the box; XLSX needs openspout/openspout.
    |
    */

    'import' => [
        'disk' => env('SALES_CRM_IMPORT_DISK', 'local'),
        'directory' => 'sales-crm/imports',
        'max_kilobytes' => 10240,
        'queue' => env('SALES_CRM_IMPORT_QUEUE'),
        'preview_rows' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Web UI (optional, needs livewire/livewire)
    |--------------------------------------------------------------------------
    |
    | The routes of the Livewire UI. `layout` is the Blade layout the pages
    | render in: the package one is standalone; to use yours, render `{{ $slot }}`
    | and add `<x-sales-crm::styles />` in its <head>.
    |
    */

    'ui' => [
        'enabled' => env('SALES_CRM_UI', true),
        'prefix' => env('SALES_CRM_PREFIX', 'crm'),
        'domain' => null,
        'middleware' => ['web', 'auth'],
        'layout' => 'sales-crm::layouts.app',
        'home_url' => '/',
        'poll_seconds' => 15,
        'brand' => env('SALES_CRM_BRAND', 'Sales CRM'),
    ],

    /*
    |--------------------------------------------------------------------------
    | JSON API (optional)
    |--------------------------------------------------------------------------
    */

    'api' => [
        'enabled' => env('SALES_CRM_API', false),
        'prefix' => 'api/crm',
        'middleware' => ['api', 'auth:sanctum'],
    ],

    /*
    |--------------------------------------------------------------------------
    | MCP tools (optional, needs laravel/mcp)
    |--------------------------------------------------------------------------
    |
    | When enabled and laravel/mcp is installed, the CRM server is registered at
    | `path` with the given middleware. You can also register
    | Sellinnate\SalesCrm\Mcp\SalesCrmServer yourself.
    |
    */

    'mcp' => [
        'enabled' => env('SALES_CRM_MCP', false),
        'path' => 'mcp/crm',
        'middleware' => ['auth:sanctum'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Timeline providers
    |--------------------------------------------------------------------------
    |
    | Classes implementing Sellinnate\SalesCrm\Contracts\TimelineProvider whose
    | read-only entries are mixed into the timeline of deals, companies and
    | people (calls from your telephony, tickets, orders…).
    |
    */

    'timeline' => [
        'providers' => [],
        'limit' => 80,
    ],

];
