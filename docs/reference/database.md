---
title: "Database schema"
description: "The sixteen tables of the CRM, their purpose and key columns, the table prefix, the connection and the user and workspace key types."
---

# Database schema

The migration `create_sales_crm_tables` (published by `sales-crm:install`)
creates sixteen tables. This page describes each one so you can query them,
report on them or debug.

## Conventions

- **Prefix**: every table name starts with `sales-crm.database.table_prefix`
  (`crm_` by default). The names below use the default.
- **Connection**: `sales-crm.database.connection` (`null` = your default
  connection). The migration and the models use it.
- **Workspace columns**: most tables have a nullable polymorphic pair
  `workspace_type` (string) + `workspace_id`, indexed together; both are
  `null` in the shared CRM. Tables with unique indexes per workspace also have
  a not-null `workspace_key` (`"type|id"`, `""` for the shared CRM).
- **Key types**: `workspace_id` follows `database.workspace_key_type`
  (`int` → unsigned big integer, `uuid`, `ulid`, or `string` of 64 characters).
  User references (`owner_id`, `created_by`, `assigned_to`…) follow
  `database.user_key_type` (`int`, `uuid`, `ulid`) and, with
  `database.user_foreign_keys` (default `true`), have a foreign key to your
  users table with `ON DELETE SET NULL`. Without foreign keys they are indexed.
- **Money** is stored in **cents** (`*_cents`, unsigned big integers).
- CRM tables use auto-increment ids.

::: callout warning "Decide before the first migrate"
Prefix, connection and key types are read when the migration runs. Changing
them later doesn't touch existing tables.
:::

## Overview

| Table | Holds |
|---|---|
| `crm_pipelines` | Pipelines of each workspace |
| `crm_stages` | Stages of each pipeline |
| `crm_companies` | Companies |
| `crm_people` | People |
| `crm_deals` | Deals |
| `crm_deal_person` | People of each deal (with role and primary flag) |
| `crm_stage_visits` | Each stay of a deal in a stage |
| `crm_deal_events` | History of each deal |
| `crm_workspace_settings` | Settings of each workspace |
| `crm_activities` | Activities |
| `crm_notes` | Notes |
| `crm_documents` | Documents (metadata; files are on the disk) |
| `crm_emails` | Emails sent and received |
| `crm_email_templates` | Email templates |
| `crm_saved_views` | Saved views of the deals page |
| `crm_imports` | Imports and their reports |

## crm_pipelines

| Column | Notes |
|---|---|
| `id` | |
| `workspace_type`, `workspace_id` | |
| `name` | ≤ 80 |
| `position` | order |
| `is_active` | inactive pipelines are hidden from the board selector |
| `revision` | raised by every change of the board (open boards poll it) |
| `created_by` | user |
| timestamps | |

## crm_stages

| Column | Notes |
|---|---|
| `id` | |
| `pipeline_id` | → `crm_pipelines`, cascade on delete |
| `name` | ≤ 80 |
| `position` | column order |
| `color` | a `StageColor` value |
| `probability` | 0–100 |
| `kind` | `open`, `won`, `lost` |
| `rotting_days` | nullable |
| `required_fields` | JSON list of `RequiredField` values |
| `on_enter_activity` | JSON `{type, title, due_in_days}` |
| timestamps | |

Index: `pipeline_id, position`.

## crm_companies

| Column | Notes |
|---|---|
| `id`, `workspace_type`, `workspace_id` | |
| `name`, `legal_name` | |
| `name_key` | normalised name used for de-duplication (indexed) |
| `vat_number`, `vat_key` | VAT and its normalised key (indexed) |
| `website`, `domain` | domain derived from website or email (indexed) |
| `email`, `phone` | phone in E.164 |
| `address`, `city`, `region`, `postal_code`, `country` | `country` is ISO 3166-1 alpha-2 |
| `industry`, `notes` | |
| `created_by`, timestamps | |

## crm_people

| Column | Notes |
|---|---|
| `id`, `workspace_type`, `workspace_id` | |
| `company_id` | → `crm_companies`, set null on delete |
| `first_name`, `last_name`, `job_title` | |
| `email` | lowercase (indexed) |
| `mobile`, `phone` | E.164 (indexed) |
| `phone_source`, `notes` | |
| `created_by`, timestamps | |

## crm_deals

| Group | Columns |
|---|---|
| Identity | `id`, `workspace_type`, `workspace_id`, `workspace_key`, `title` |
| Placement | `pipeline_id` (→ pipelines, cascade), `stage_id` (→ stages, **no action**: a stage with deals can't be deleted), `position` |
| Relations | `company_id` (→ companies, set null), `owner_id` (user) |
| Classification | `priority`, `tags` (JSON), `source` |
| Origin | `origin_type`, `origin_id` (the record of your app the deal was born from), `external_ref` |
| Amounts | `amount_once_cents`, `deposit_cents`, `recurring_cents`, `recurring_period`, `first_year_value_cents` (stored, kept in sync on save) |
| Offer | `recurring_options`, `payment_terms`, `offer_details`, `offer_valid_until`, `expected_close_date` |
| Operational | `sub_status`, `next_step`, `blocker`, `missing_data`, `call_context`, `closing_tip` |
| Approval | `approval_required`, `approval_status`, `approver_id` (user), `approval_note`, `approval_decided_at` |
| Signature | `signature_status`, `signature_evidence`, `signature_verified_at`, `signature_verified_by` (user) |
| Payment | `payment_status`, `amount_collected_cents`, `payment_evidence`, `payment_verified_at`, `payment_verified_by` (user) |
| Closing | `lost_reason`, `lost_note`, `won_at`, `lost_at` |
| Commission | `commission_type`, `commission_value` (cents for an amount, basis points for a percent), `commission_note` |
| Custom | `custom_values` (JSON) |
| Activity | `stage_entered_at`, `last_activity_at`, `next_activity_at`, `last_contacted_at`, `data_verified_at` |
| Audit | `created_by`, `updated_by` (users), timestamps |

Indexes: unique `workspace_key, external_ref`; unique `origin_type, origin_id`
(one deal per origin record); `pipeline_id, stage_id, position`;
`next_activity_at`; `expected_close_date`.

## crm_deal_person

| Column | Notes |
|---|---|
| `id` | |
| `deal_id` | → deals, cascade |
| `person_id` | → people, cascade |
| `role` | a `PersonRole` value, nullable |
| `is_primary` | the contact shown on the card |
| timestamps | |

Unique: `deal_id, person_id`.

## crm_stage_visits

One row per stay of a deal in a stage, for "time in stage" reports.

| Column | Notes |
|---|---|
| `id`, `deal_id` (cascade), `pipeline_id` (cascade) | |
| `stage_id` | → stages, set null on delete (the visit survives) |
| `stage_name` | the stage's name, kept for deleted stages |
| `entered_at`, `left_at` | `left_at` is null for the current stage |
| `entered_by` | user |

## crm_deal_events

The history of each deal (shown as "changes" on the timeline).

| Column | Notes |
|---|---|
| `id`, `deal_id` (cascade) | |
| `user_id` | who made the change (null: the system) |
| `type` | `created`, `stage_changed`, `updated`, `won`, `lost`, `reopened` |
| `field` | the changed field, for updates |
| `from`, `to` | JSON old and new values |
| `created_at` | |

## crm_workspace_settings

| Column | Notes |
|---|---|
| `id`, `workspace_type`, `workspace_id`, `workspace_key` (unique) | |
| `entry_stage_id` | → stages, set null: where `createDealFrom()` deals land |
| `custom_fields` | JSON definitions `{key, label, type, options?}` |
| `options` | JSON, free options |
| `updated_by`, timestamps | |

## crm_activities

| Column | Notes |
|---|---|
| `id`, `workspace_type`, `workspace_id` | |
| `deal_id`, `company_id` | cascade on delete |
| `person_id` | set null on delete: the person the activity is on (or with, for deal activities) |
| `type`, `title`, `notes` | |
| `due_at`, `has_time` | `has_time = false` for all-day activities |
| `remind_minutes`, `reminded_at` | |
| `assigned_to` | user |
| `done_at`, `done_by`, `outcome` | |
| `created_by`, timestamps | |

Indexes: `assigned_to, done_at, due_at`; `deal_id, done_at, due_at`;
`done_at, reminded_at, due_at`.

## crm_notes

| Column | Notes |
|---|---|
| `id`, `workspace_type`, `workspace_id` | |
| `deal_id`, `company_id`, `person_id` | cascade on delete (one of them is set) |
| `user_id` | author |
| `body` | text |
| `mentions` | JSON list of mentioned user keys |
| `pinned_at` | |
| timestamps | |

## crm_documents

| Column | Notes |
|---|---|
| `id`, `workspace_type`, `workspace_id` | |
| `deal_id`, `company_id` | cascade on delete |
| `kind` | a `DocumentKind` value |
| `name` | original file name |
| `disk`, `path` | where the file is stored |
| `mime`, `size` | |
| `version` | quote version per deal |
| `document_date`, `valid_until` | |
| `uploaded_by`, timestamps | |

## crm_emails

| Column | Notes |
|---|---|
| `id`, `workspace_type`, `workspace_id`, `workspace_key` | |
| `deal_id` | cascade on delete |
| `person_id` | set null on delete |
| `direction` | `out` or `in` |
| `status` | `queued`, `sending`, `sent`, `failed`, `received` |
| `from_email`, `from_name`, `reply_to` | |
| `to`, `cc` | JSON lists |
| `subject`, `body_text` | plain text |
| `message_id`, `in_reply_to`, `references` | threading |
| `sent_by` | user |
| `sent_at`, `error` | |
| timestamps | |

Indexes: unique `workspace_key, message_id`; `message_id`;
`deal_id, sent_at`; `person_id, sent_at`.

## crm_email_templates

| Column | Notes |
|---|---|
| `id`, `workspace_type`, `workspace_id`, `workspace_key` | |
| `name`, `subject`, `body` | unique `workspace_key, name` |
| `created_by`, timestamps | |

## crm_saved_views

| Column | Notes |
|---|---|
| `id` | |
| `user_id` | owner (not null; cascade when the user is deleted, with user foreign keys) |
| `workspace_type`, `workspace_id`, `workspace_key` | |
| `name`, `shared` | |
| `state` | JSON state of the deals page |
| timestamps | |

Indexes: unique `user_id, workspace_key, name`; `workspace_key, shared`.

## crm_imports

| Column | Notes |
|---|---|
| `id`, `workspace_type`, `workspace_id` | |
| `user_id` | who started it |
| `pipeline_id` | → pipelines, set null |
| `original_filename`, `disk`, `stored_path`, `sheet` | |
| `status` | `uploaded`, `mapped`, `running`, `done`, `failed` |
| `mapping` | JSON columns, stages, default stage, month-first |
| `total_rows`, `created_count`, `updated_count`, `rejected_count` | |
| `rejected`, `warnings` | JSON reports |
| `error`, `started_at`, `finished_at` | |
| timestamps | |

## Deleting data

- Deleting a **deal** removes its people links, stage visits, history,
  activities, notes, documents and emails (database cascades; stored files are
  removed after the commit).
- Deleting a **pipeline** cascades to its stages and deals (the CRM only
  allows deleting pipelines without deals).
- Deleting one of **your users** sets their references to `null` (with user
  foreign keys) and deletes their saved views.
- Deleting one of **your workspace models** does **not** delete CRM rows (the
  workspace morph has no foreign key): clean up CRM data yourself if needed.

## Rolling back

The migration's `down()` drops all sixteen tables. There is no partial
rollback.
