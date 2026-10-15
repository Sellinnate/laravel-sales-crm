---
title: "JSON API"
description: "The optional REST API of the CRM: list pipelines, list, show, create, update, move and delete deals, add notes and activities."
---

# JSON API

An optional REST API lets other systems (a website form, a mobile app, an
automation platform) read and work the deals. It uses the same actions and
permissions as the UI.

## Enable it

```dotenv
SALES_CRM_API=true
```

```php
// config/sales-crm.php (defaults)
'api' => [
    'enabled'    => env('SALES_CRM_API', false),
    'prefix'     => 'api/crm',
    'middleware' => ['api', 'auth:sanctum'],
],
```

With the default middleware, clients authenticate with a
[Laravel Sanctum](https://laravel.com/docs/sanctum) token:

```bash
curl https://app.example.test/api/crm/pipelines \
  -H "Authorization: Bearer 1|abc123..." \
  -H "Accept: application/json"
```

The user behind the token must pass the `sales-crm.access` gate, and every
request acts with their permissions (workspaces, deal visibility, policies).

::: callout warning "Always send Accept: application/json"
Without it, Laravel answers validation errors with a redirect instead of a 422
JSON response.
:::

## Endpoints

All paths are relative to the prefix (`/api/crm`).

| Method | Path | Route name | Purpose |
|---|---|---|---|
| GET | `/pipelines` | `sales-crm.api.pipelines.index` | Pipelines and their stages |
| GET | `/deals` | `sales-crm.api.deals.index` | List deals with filters |
| POST | `/deals` | `sales-crm.api.deals.store` | Create a deal |
| GET | `/deals/{deal}` | `sales-crm.api.deals.show` | Show a deal |
| PATCH | `/deals/{deal}` | `sales-crm.api.deals.update` | Update fields |
| DELETE | `/deals/{deal}` | `sales-crm.api.deals.destroy` | Delete (managers) |
| POST | `/deals/{deal}/move` | `sales-crm.api.deals.move` | Move to a stage |
| POST | `/deals/{deal}/notes` | `sales-crm.api.deals.notes.store` | Add a note |
| POST | `/deals/{deal}/activities` | `sales-crm.api.deals.activities.store` | Plan an activity |

### GET /pipelines

```json
{
  "data": [
    {
      "id": 1,
      "name": "Sales pipeline",
      "active": true,
      "workspace": null,
      "stages": [
        { "id": 1, "name": "New lead", "kind": "open", "probability": 10, "position": 0, "rotting_days": 7, "required_fields": [] },
        { "id": 4, "name": "Proposal sent", "kind": "open", "probability": 60, "position": 3, "rotting_days": 14, "required_fields": ["amount", "offer_valid_until"] }
      ]
    }
  ]
}
```

`workspace` is the workspace key (`"App\\Models\\Team|7"`) when workspaces are
on, `null` otherwise.

### GET /deals

Query parameters (all optional):

| Parameter | Values |
|---|---|
| `search` | free text (title, company, people, email, phone, external reference) |
| `quick[]` | `mine`, `p1`, `no_activity`, `stale`, `offer_expired`, `approval` |
| `owner_id` | a user id, or `none` |
| `priority` | `p1`…`p4`, `excluded` |
| `source` | a deal source |
| `tag` | a tag |
| `pipeline_id`, `stage_id` | integers |
| `closed` | `all` (default) or `recent` (hide deals closed more than `closed_visible_days` ago) |
| `per_page` | 1–100, default 25 |
| `page` | page number |

Deals are sorted by last update, newest first.

```json
{
  "data": [ { "id": 42, "title": "Website redesign", "...": "..." } ],
  "meta": { "current_page": 1, "last_page": 3, "per_page": 25, "total": 61 }
}
```

### POST /deals

The body accepts every [deal field](/concepts/deals#fields) plus
`pipeline_id`, `stage_id`, `person` and `next_activity`. When workspaces are on
and no `pipeline_id` is given, `workspace` (the key `"type|id"`) is required.

```bash
curl -X POST https://app.example.test/api/crm/deals \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{
    "title": "Website redesign",
    "company_name": "Acme Ltd",
    "amount_once": 12000,
    "expected_close_date": "2026-12-15",
    "source": "website",
    "external_ref": "FORM-2041",
    "person": { "first_name": "Jane", "last_name": "Doe", "email": "jane@acme.example" }
  }'
```

Response `201 Created` with `{"data": <deal>}`.

### GET /deals/{deal}

`{"data": <deal>}`, or 403 if the user can't see it.

### PATCH /deals/{deal}

Only the keys you send change. The stage can't be changed here (use `/move`).

```json
{ "amount_once": 13500, "next_step": "Send the revised proposal" }
```

Response: `{"data": <deal>}`.

### DELETE /deals/{deal}

Deletes the deal with its activities, notes, emails, documents and history.
Only users who manage the workspace. Response `204 No Content`.

### POST /deals/{deal}/move

| Field | |
|---|---|
| `stage_id` | **required**, a stage of the deal's pipeline |
| `after_id` | optional, place the deal after this deal in the column (top when omitted) |
| `fill` | optional object with the fields the stage requires, and `lost_reason` / `lost_note` for a lost stage |

```json
{ "stage_id": 8, "fill": { "lost_reason": "price", "lost_note": "Went with a cheaper offer" } }
```

When fields are missing the answer is 422 with one error per field under
`requirements.<field>`:

```json
{
  "message": "To enter «Proposal sent» you need: offer valid until.",
  "errors": {
    "requirements.offer_valid_until": ["To enter «Proposal sent» you need: offer valid until."]
  }
}
```

Retry with `"fill": {"offer_valid_until": "2026-11-30"}`.

### POST /deals/{deal}/notes

```json
{ "body": "Budget confirmed by the CFO.", "pinned": false }
```

Response `201` with `{"data": {"id": 15, "body": "...", "created_at": "2026-10-15T09:30:00+00:00"}}`.

### POST /deals/{deal}/activities

```json
{ "type": "call", "title": "Follow up", "due_date": "2026-10-20", "due_time": "10:00", "remind_minutes": 15 }
```

Accepted keys: `type`, `title`, `due_date`, `due_time`, `remind_minutes`,
`assigned_to`, `notes`, `person_id` (see [Activities](/concepts/activities)).
Response `201` with `{"data": {"id": 31, "type": "call", "title": "Follow up", "due_at": "2026-10-20T10:00:00+00:00"}}`.

## The deal format {#deal-format}

Deals are returned in the same shape by the API and the MCP tools. Amounts are
in **major units**; keys with a `null` or empty value are left out.

```json
{
  "id": 42,
  "title": "Website redesign",
  "workspace": { "key": "App\\Models\\Team|7", "name": "Acme Sales" },
  "pipeline": { "id": 1, "name": "Sales pipeline" },
  "stage": { "id": 4, "name": "Proposal sent", "kind": "open", "probability": 60 },
  "status": "open",
  "company": { "id": 9, "name": "Acme Ltd" },
  "owner": { "id": 5, "name": "Mario Rossi" },
  "priority": "p2",
  "source": "website",
  "amount_once": 12000,
  "deposit": 2000,
  "recurring": { "amount": 150, "period": "monthly" },
  "first_year_value": 13800,
  "weighted_value": 8280,
  "currency": "EUR",
  "expected_close_date": "2026-12-15",
  "offer_valid_until": "2026-11-30",
  "next_step": "Send the revised proposal",
  "next_activity_at": "2026-10-20T10:00:00+00:00",
  "last_activity_at": "2026-10-15T09:30:00+00:00",
  "signature_status": "not_verified",
  "payment_status": "not_verified",
  "approval_status": "pending",
  "people": [ { "id": 3, "name": "Jane Doe", "email": "jane@acme.example", "phone": "+447700900123", "primary": true } ],
  "external_ref": "FORM-2041",
  "origin": { "type": "App\\Models\\Booking", "id": "88" },
  "tags": ["web"],
  "custom_values": { "region": "North" },
  "url": "https://app.example.test/crm/deals/42",
  "created_at": "2026-10-01T08:00:00+00:00",
  "updated_at": "2026-10-15T09:30:00+00:00"
}
```

Other keys that appear when set: `won_at`, `lost_at`, `lost_reason`.
`workspace` is present only when workspaces are on; `url` only when the UI
routes are registered.

## Errors

| Status | When |
|---|---|
| 401 | Not authenticated (missing or invalid token). |
| 403 | The user doesn't pass the access gate, or a policy refuses (e.g. deleting without manager rights). |
| 404 | The deal or pipeline doesn't exist (or a pipeline isn't visible to the user). |
| 422 | Validation failed: `{"message": "...", "errors": {"field": ["..."]}}`. |

## Best practices

- **Send `external_ref`** with deals coming from another system, and look them
  up with `search` before creating: `external_ref` is unique per workspace and
  the API answers 422 on a duplicate.
- **Call `/pipelines` once** and cache the stage ids.
- **Use a dedicated user** (and token) per integration, with the permissions it
  needs.

## Common pitfalls

- Sending amounts in cents: the API expects major units (`12000`, not
  `1200000`).
- Putting `lost_reason` at the top level of a `/move` request: it belongs inside
  `fill`.
- `SALES_CRM_API=true` with `config:cache` from before: run
  `php artisan config:cache` again (and `route:cache` if you cache routes).
