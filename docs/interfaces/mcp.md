---
title: "MCP tools"
description: "Expose the CRM to AI agents through laravel/mcp: nine tools to read pipelines, search, create, update and move deals, add notes and plan activities, acting as the authenticated user."
---

# MCP tools

The package ships an **MCP server** with nine tools, so an AI assistant can work
the CRM for a user: "What's due today?", "Move the Acme deal to Negotiation",
"Log that I called Jane and plan a follow-up on Friday".

::: callout info "In plain words"
**MCP** (Model Context Protocol) is an open standard that lets AI assistants
call tools in other applications. Your Laravel app becomes an MCP server; the
assistant (an MCP client) connects with the user's token and calls the tools.
Every call acts **as that user**, with exactly their permissions.
:::

## Enable it

```bash
composer require laravel/mcp
```

```dotenv
SALES_CRM_MCP=true
```

```php
// config/sales-crm.php (defaults)
'mcp' => [
    'enabled'    => env('SALES_CRM_MCP', false),
    'path'       => 'mcp/crm',
    'middleware' => ['auth:sanctum'],
],
```

The server `Sales CRM` is registered at **`POST /mcp/crm`** (Streamable HTTP
transport) behind the configured middleware. The default `auth:sanctum` means
clients authenticate with a **Sanctum personal access token** sent as
`Authorization: Bearer <token>`; install and set up
[Laravel Sanctum](https://laravel.com/docs/sanctum) or change the middleware to
your own guard.

```php
$token = $user->createToken('crm-assistant')->plainTextToken;
```

::: callout tip "Register it yourself"
Prefer to keep `SALES_CRM_MCP=false` and register the server in your own
`routes/ai.php`, with your middleware:

```php
use Laravel\Mcp\Facades\Mcp;
use Sellinnate\SalesCrm\Mcp\SalesCrmServer;

Mcp::web('/mcp/crm', SalesCrmServer::class)->middleware(['auth:sanctum', 'throttle:60,1']);
```
:::

Test it with the MCP Inspector shipped with `laravel/mcp`:

```bash
php artisan mcp:inspector mcp/crm
```

## How the tools behave

- They act as the **authenticated user** of the MCP request. A user without the
  `sales-crm.access` gate gets "You are not allowed to use the CRM."
- They see only what the user sees (workspaces and deal visibility) and every
  write goes through the same actions as the UI: validation, policies, history,
  events.
- **Amounts are in major units** (1500 = 1,500.00).
- Errors are returned as tool errors the agent can act on: validation errors as
  `field: message` (for a stage's missing fields, `requirements.<field>: …`),
  "You are not allowed to do this on this record.", "Record not found (or not
  visible to you)."

The server's instructions tell the agent to start with `crm_pipelines`, use
`crm_deals_list` to find deals and `crm_deal_get` for details, and to pass a
stage's missing fields in `fill`.

## The tools

### crm_pipelines (read-only)

Lists the pipelines the user works in, with their stages (id, name, kind,
probability, required fields) and, per stage, the number and first-year value
of the open deals. Call it first to learn the stage ids.

| Argument | Type | |
|---|---|---|
| `workspace` | string | Workspace key (`"type\|id"`) to limit the result; omit for all. |

### crm_deals_list (read-only)

Searches the deals the user sees, most recently updated first.

| Argument | Type | |
|---|---|---|
| `search` | string | Free text: title, company, people, email, phone, external reference. |
| `quick` | string[] | Any of `mine`, `p1`, `no_activity`, `stale`, `offer_expired`, `approval` (combined). |
| `owner_id` | string | Owner user id, or `"none"`. |
| `priority` | string | `p1`, `p2`, `p3`, `p4`, `excluded`. |
| `source` | string | A [deal source](/concepts/deals#fields). |
| `tag` | string | A tag of the deal. |
| `pipeline_id` | integer | |
| `stage_id` | integer | |
| `include_closed` | boolean | Also won and lost deals older than the board shows. |
| `limit` | integer | 1–50, default 25. |

Returns `{"count": n, "deals": [ ... ]}` with each deal in the
[deal format](/interfaces/api#deal-format) of the JSON API.

### crm_deal_get (read-only)

| Argument | Type | |
|---|---|---|
| `deal_id` | integer | **required** |

Returns the deal plus `sub_status`, `blocker`, `signature_status`,
`payment_status`, `approval_status`, its `open_activities` and the latest 20
`timeline` entries (notes, activities done, emails, changes, documents).

### crm_deal_create

Creates a deal. Without `pipeline_id` the default pipeline of the workspace is
used, without `stage_id` its first open stage. `company_name` is matched against
existing companies; `person` becomes the primary contact. If the stage requires
fields, the error lists them.

| Argument | Type | |
|---|---|---|
| `title` | string ≤ 200 | **required** |
| `workspace` | string | Workspace key (`"type\|id"`). When workspaces are on and neither this nor `pipeline_id` is given, the user's first workspace is used. |
| `pipeline_id`, `stage_id` | integer | |
| `company_name` | string ≤ 200 | |
| `person` | object | `{first_name, last_name, email, mobile, job_title}` |
| `owner_id` | string | |
| `amount_once`, `recurring` | number ≥ 0 | |
| `recurring_period` | string | `monthly`, `yearly` |
| `expected_close_date` | string | `Y-m-d` |
| `priority` | string | `p1`…`p4`, `excluded` |
| `source` | string | a [deal source](/concepts/deals#fields) |
| `next_step` | string | |
| `external_ref` | string ≤ 64 | |
| `tags` | string[] | |

### crm_deal_update

Changes fields of a deal (only those given). The stage changes with
`crm_deal_move`.

| Argument | Type | |
|---|---|---|
| `deal_id` | integer | **required** |
| `fields` | object | **required**: any [deal field](/concepts/deals#fields), e.g. `{"amount_once": 15000, "next_step": "Send contract"}` |

### crm_deal_move

Moves a deal to another stage of its pipeline. When the stage requires fields
the deal lacks, the error lists them as `requirements.<field>`: pass them in
`fill` and retry.

| Argument | Type | |
|---|---|---|
| `deal_id` | integer | **required** |
| `stage_id` | integer | **required**, a stage of the deal's pipeline |
| `lost_reason` | string | needed for a lost stage: `price`, `timing`, `competitor`, `no_response`, `no_budget`, `not_in_target`, `postponed`, `other` |
| `lost_note` | string ≤ 1000 | |
| `fill` | object | `amount_once`, `recurring`, `owner_id`, `company_name`, `offer_valid_until`, `expected_close_date`, `payment_terms`, `person {first_name, last_name, email}`, `next_activity {type, title, due_date, due_time}` |

### crm_note_add

| Argument | Type | |
|---|---|---|
| `deal_id` | integer | **required** |
| `body` | string ≤ 5000 | **required**; colleagues mentioned as `@Full Name` are notified |
| `pinned` | boolean | |

### crm_activity_add

Plans an activity on a deal, or logs one already done.

| Argument | Type | |
|---|---|---|
| `deal_id` | integer | **required** |
| `type` | string | **required**: `call`, `email`, `meeting`, `deadline`, `task` |
| `title` | string ≤ 200 | **required** |
| `due_date` | string | **required**, `Y-m-d` |
| `due_time` | string | `H:i` |
| `remind_minutes` | integer | one of `sales-crm.activities.reminder_options` |
| `assigned_to` | string | user id (default: the user) |
| `notes` | string ≤ 2000 | |
| `done` | boolean | `true` to log it as already done |
| `outcome` | string ≤ 2000 | the outcome when `done` |

### crm_activities_due (read-only)

The open activities assigned to the user that are overdue or due today (or
within `days`), with their deal; at most 100.

| Argument | Type | |
|---|---|---|
| `days` | integer | 0–30, default 0 (today) |

## Example conversation

> **User:** Move the Northwind maintenance deal to Signature.
>
> The agent calls `crm_deals_list {"search": "Northwind"}`, then
> `crm_pipelines` to find the Signature stage id, then
> `crm_deal_move {"deal_id": 42, "stage_id": 7}`. The tool answers
> `requirements.owner: To enter «Signature» you need: owner.` The agent asks
> who the owner is (or uses the user), and retries with
> `"fill": {"owner_id": "5"}`.

## Best practices

- **Give each assistant its own token** (`createToken('crm-assistant')`) so you
  can revoke it.
- **Add rate limiting** to the middleware.
- **Rely on the user's permissions**: there is no separate "AI" role; the agent
  can do exactly what the user can do in the UI.

## Common pitfalls

- `SALES_CRM_MCP=true` without `laravel/mcp`: the server is not registered.
- 401 from `/mcp/crm`: missing or invalid Bearer token, or Sanctum not set up.
- With workspaces, an agent creating a deal without `workspace` or
  `pipeline_id` puts it in the user's **first** workspace: tell it which one.
