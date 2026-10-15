---
title: "PHP API (facade)"
description: "Reference of the SalesCrm facade and of CrmScope: every method, its arguments, what it returns and what it throws."
---

# PHP API (facade)

The `SalesCrm` facade (`Sellinnate\SalesCrm\Facades\SalesCrm`, alias
`SalesCrm`) is the entry point of the headless core. It proxies to
`Sellinnate\SalesCrm\SalesCrm`, which you can also inject.

```php
use Sellinnate\SalesCrm\Facades\SalesCrm;
```

## Conventions

- **`$by`** is the acting user (`Illuminate\Contracts\Auth\Authenticatable`).
  When it's given, the action is authorized with the CRM
  [policies](/concepts/authorization) and the user is recorded as author. Where
  `$by` is optional, omitting it means **the system** acts: no authorization.
- **Amounts** are in major units (`1500` = 1,500.00); models store `*_cents`.
- **Dates** are `Y-m-d` strings; times `H:i`.
- Invalid input throws `Illuminate\Validation\ValidationException` (field-level
  errors, 422 in HTTP). A refused permission throws
  `Illuminate\Auth\Access\AuthorizationException`.
- Every write runs in a database transaction and fires its
  [event](/reference/events) after the commit.

## Selecting a CRM

### `SalesCrm::for($workspace): CrmScope`

The CRM of a workspace. `$workspace` is one of your models (a `Team`…), a
`Sellinnate\SalesCrm\Workspaces\Workspace` value object, or `null` for the
shared CRM.

### `SalesCrm::shared(): CrmScope`

The shared CRM of an application without workspaces. Same as `for(null)`.

## CrmScope

`Sellinnate\SalesCrm\CrmScope`, returned by `for()` and `shared()`. Its
`workspace` property is the `?Workspace` it targets.

::: callout warning "Queries are not permission-filtered"
The query methods return **all** records of the workspace. To show data to a
user, use the models' `visibleTo($user)` scope instead, or check
`SalesCrm::access()->works($user, $workspace)` first.
:::

| Method | Returns | Description |
|---|---|---|
| `deals()` | `Builder<Deal>` | Deals of the workspace. |
| `pipelines()` | `Builder<Pipeline>` | Pipelines, active first, then by position. |
| `companies()` | `Builder<Company>` | Companies of the workspace. |
| `people()` | `Builder<Person>` | People of the workspace. |
| `activities()` | `Builder<Activity>` | Activities of the workspace. |
| `defaultPipeline(?Authenticatable $by = null)` | `Pipeline` | The first pipeline, creating the default one if none exists. |
| `settings()` | `WorkspaceSetting` | The workspace settings (entry stage, custom fields). |
| `createDeal(array $data, ?Authenticatable $by = null)` | `Deal` | Create a deal. |
| `createDealFrom(Model $origin, array $data, ?Authenticatable $by = null)` | `Deal` | Create (once) the deal born from one of your records. |
| `company(array $data, ?Authenticatable $by = null)` | `Company` | Find or create a company. |
| `person(array $data, ?Company $company = null, ?Authenticatable $by = null)` | `Person` | Find or create a person. |

### `createDeal(array $data, ?Authenticatable $by = null): Deal`

```php
$deal = SalesCrm::for($team)->createDeal([
    'title' => 'Website redesign',
    'company_name' => 'Acme Ltd',
    'amount_once' => 12000,
], by: $user);
```

`$data` accepts every [deal field](/concepts/deals#fields) (`title` required)
plus:

| Key | |
|---|---|
| `pipeline_id` | default: the workspace's first pipeline (created if needed) |
| `stage_id` | default: the pipeline's first open stage |
| `person` | details of the primary contact, or `['person_id' => …]`, with optional `role` |
| `next_activity` | `['type', 'title', 'due_date', 'due_time']` |
| `offer_document` | an `UploadedFile` stored as the quote |

Without `owner_id` the owner is `$by` when they work in the workspace. Throws a
`ValidationException` with `requirements.<field>` keys when the stage requires
fields the deal lacks (and `lost_reason` for a lost stage). Fires
`DealCreated`.

### `createDealFrom(Model $origin, array $data, ?Authenticatable $by = null): Deal`

Idempotent: returns the existing deal if one was already created from
`$origin` (unique on `origin_type` + `origin_id`). Without `stage_id` /
`pipeline_id`, the deal enters the workspace's entry stage (Settings), else the
first open stage of the default pipeline. `source` defaults to `integration`.
The stage's required fields are not enforced.

### `company(array $data, ?Authenticatable $by = null): Company`

Matches by VAT number, then domain, then normalised name; completes a match
with missing details, never overwrites. Keys: `name`, `legal_name`,
`vat_number`, `website`, `email`, `phone`, `address`, `city`, `region`,
`postal_code`, `country`, `industry`. See
[de-duplication](/concepts/contacts#de-duplication).

### `person(array $data, ?Company $company = null, ?Authenticatable $by = null): Person`

Matches by email, then phone, then first + last name within `$company`. Keys:
`first_name`, `last_name`, `job_title`, `email`, `mobile`, `phone`,
`phone_source`.

## Facade methods on records

### `updateDeal(Deal $deal, array $data, Authenticatable $by): Deal`

Changes only the keys given ([deal fields](/concepts/deals#fields)).
`pipeline_id`, `stage_id`, `workspace_type`, `workspace_id`, `origin_type` and
`origin_id` are ignored. Requires `update` permission. Fires `DealUpdated`
(and `ApprovalRequested` / `ApprovalDecided` when relevant).

### `moveDeal(Deal $deal, Stage|int $stage, Authenticatable $by, array $fill = [], ?int $afterId = null): Deal`

Moves the deal to a stage **of its pipeline** (`stage_id` validation error
otherwise). `$fill` completes the fields the stage requires (deal fields,
`person`, `next_activity`, `offer_document`, `lost_reason`, `lost_note`).
`$afterId` places the deal after another deal of the column (top when
`null`). Fires `DealStageChanged` and `DealWon` / `DealLost` / `DealReopened`.

```php
SalesCrm::moveDeal($deal, $stageId, $user, fill: ['amount_once' => 5000, 'owner_id' => $user->id]);
```

### `deleteDeal(Deal $deal, Authenticatable $by): void`

Deletes the deal with its history, activities, notes, emails and documents
(files included). Requires the user to manage the workspace. Fires
`DealDeleted`.

### `addPerson(Deal $deal, array $data, ?Authenticatable $by = null): Person`

Adds an existing person (`person_id`) or the details of one to match or create
(`first_name`, `last_name`, `job_title`, `email`, `mobile`, `phone`,
`phone_source`). Optional `role` (`decision_maker`, `influencer`,
`operational`, `other`) and `is_primary`. The first person of a deal becomes
primary.

### `addNote(Deal|Company|Person $target, string $body, Authenticatable $by, bool $pinned = false): Note`

Up to 5,000 characters; `@Full Name` mentions users of the workspace. Fires
`NoteAdded` and `UserMentioned`.

### `scheduleActivity(Deal|Company|Person $target, array $data, ?Authenticatable $by = null): Activity`

Keys: `type` (`call`, `email`, `meeting`, `deadline`, `task`), `title`,
`due_date` (`Y-m-d`), `due_time` (`H:i`, optional), `remind_minutes` (one of
`activities.reminder_options`), `assigned_to`, `notes`, `person_id`. Fires
`ActivityCreated`.

### `completeActivity(Activity $activity, ?string $outcome, Authenticatable $by): Activity`

Marks the activity done with its outcome. Fires `ActivityCompleted`.

### `sendEmail(Deal $deal, array $data, Authenticatable $by): Email`

Keys: `to` (required), `cc`, `subject` (required), `body` (required, plain
text, template variables allowed), `reply_to_id`. Stores the email as queued
and dispatches its delivery. Fires `EmailQueued`. See [Email](/concepts/email).

### `uploadDocument(Deal|Company $target, UploadedFile $file, array $data, Authenticatable $by): Document`

Keys: `kind` (required: `quote`, `contract`, `signature_evidence`,
`payment_evidence`, `other`), `document_date`, `valid_until` (quotes). Fires
`DocumentUploaded`.

## Services

| Method | Returns | See |
|---|---|---|
| `SalesCrm::access()` | `Sellinnate\SalesCrm\Authorization\Access` | below |
| `SalesCrm::board()` | `Sellinnate\SalesCrm\Services\DealBoard` | below |
| `SalesCrm::reports()` | `Sellinnate\SalesCrm\Services\Reports` | [Reports](/concepts/reports) |
| `SalesCrm::timeline()` | `Sellinnate\SalesCrm\Services\DealTimeline` | [Timeline](/concepts/timeline) |
| `SalesCrm::inbound()` | `Sellinnate\SalesCrm\Inbound\Inbound` | [Email](/concepts/email#inbound-email) |

### Access

| Method | |
|---|---|
| `canAccess(?Authenticatable $user): bool` | May enter the CRM. |
| `canManage(?Authenticatable $user, Model\|Workspace\|null $workspace): bool` | Works in and may configure the workspace. |
| `works(?Authenticatable $user, Model\|Workspace\|null $workspace): bool` | Works in the workspace. |
| `worksOn(?Authenticatable $user, Model $record): bool` | Works in the workspace of a CRM record. |
| `canSeeDeal(?Authenticatable $user, Deal $deal): bool` | May see (and work) the deal. |
| `workspacesFor(?Authenticatable $user): array` | `list<Workspace>` (empty without workspaces). |
| `members(Model\|Workspace\|null $workspace): Builder` | Users who work in the workspace. |
| `isMember($user, $workspace): bool` | Whether a user (or user key) works in it. |
| `label(Model\|Workspace\|null $workspace): string` | Display name. |
| `constrain($query, ?Authenticatable $user)` / `constrainDeals(Builder $query, ?Authenticatable $user)` | Apply the user's scope to a query. |
| `resolver()` / `authorizer()` | The bound contracts. |

### DealBoard

| Method | |
|---|---|
| `query(Authenticatable $user, array $filters, ?Pipeline $pipeline = null, Workspace\|false\|null $workspace = false): Builder<Deal>` | Deals the user sees matching filters: `search`, `quick` (list of `mine`, `p1`, `no_activity`, `stale`, `offer_expired`, `approval`), `owner` (id or `none`), `priority`, `source`, `tag`, `stage`, `closed` (`recent` default, or `all`). `$workspace = false` means all the user's workspaces. |
| `columns(Pipeline $pipeline, Builder $filtered, array $limits = []): array` | One entry per stage: `stage`, `deals` (first cards), `count`, `value`, `weighted` (cents), `more`. |
| `version(Pipeline $pipeline): string` | A hash that changes when the pipeline's board changes. |

### Inbound

| Method | |
|---|---|
| `receive(InboundEmail $email, Model\|Workspace\|false\|null $workspace = false): ?Email` | Match and store an email; `null` when it doesn't belong to the CRM. |
| `poll(?string $driver = null): array` | Read the driver's new emails: `['read' => n, 'stored' => m]`. |
| `drivers(): InboundManager` | The driver manager (`extend()` custom drivers). |

## Models: scopes and helpers

| Model | Scopes | Notable methods / relations |
|---|---|---|
| `Deal` | `open()`, `visibleTo($user)`, `inWorkspace($ws)` | `isOpen()`, `isWon()`, `isLost()`, `weightedCents()`, `mrrCents()`, `arrCents()`, `commissionCents()`, `offerState()`, `staleDays()`, `daysInStage()`, `nextActivityState()`, `primaryPerson()`; `pipeline`, `stage`, `company`, `owner`, `approver`, `origin`, `people`, `activities`, `notes`, `documents`, `emails`, `events`, `stageVisits` |
| `Pipeline` | `active()`, `ordered()`, `visibleTo()`, `inWorkspace()` | `stages`, `deals`, `firstOpenStage()` |
| `Stage` | | `pipeline`, `deals`, `requiredFields()`, `isOpen()` |
| `Company` | `visibleTo()`, `inWorkspace()` | `people`, `deals`, `activities`, `crmNotes`, `documents` |
| `Person` | `visibleTo()`, `inWorkspace()` | `name` (accessor), `callableNumber()`, `company`, `deals`, `activities`, `crmNotes`, `emails` |
| `Activity` | `open()`, `late()`, `visibleTo()`, `inWorkspace()` | `state()`, `isDone()`, `deadline()`, `remindAt()`; `deal`, `company`, `person`, `assignee`, `doneBy` |
| `Note` | `visibleTo()`, `inWorkspace()` | `deal`, `company`, `person`, `author` |
| `Document` | `visibleTo()`, `inWorkspace()` | `deal`, `company`, `uploader` |
| `Email` | `visibleTo()`, `inWorkspace()` | `thread()`, `deal`, `person`, `sender` |

Every model with workspace columns (all except `Stage`, `DealEvent` and
`StageVisit`, which follow their pipeline or deal) has `workspace()` (your
model), `workspaceRef()` (the `Workspace` value object), `setWorkspace()` and
`inWorkspace()`.

## Exceptions

| Class | When |
|---|---|
| `Illuminate\Validation\ValidationException` | Any invalid input, business rule or missing stage requirement. |
| `Illuminate\Auth\Access\AuthorizationException` | A policy refused the action for `$by`. |
| `Sellinnate\SalesCrm\Exceptions\ImportException` | An import file can't be read (unreadable, unsupported format); the message is safe to show. |
| `InvalidArgumentException` | Misconfiguration: no user model, a resolver/authorizer not implementing its contract, no From address. |

`Sellinnate\SalesCrm\Exceptions\MissingRequirements` is not thrown itself: it
builds the `ValidationException` of a refused stage. Use
`MissingRequirements::fieldsOf($exception)` to get the missing fields as
`RequiredField` enums.

## Enums

All in `Sellinnate\SalesCrm\Enums`, string-backed, with `label()` (translated),
`options()` (value ⇒ label) and `values()`:

| Enum | Values |
|---|---|
| `StageKind` | `open`, `won`, `lost` |
| `StageColor` | `neutral`, `brand`, `info`, `accent`, `warning`, `success`, `danger` |
| `RequiredField` | `company`, `person`, `owner`, `amount`, `expected_close_date`, `offer_valid_until`, `payment_terms`, `next_activity`, `offer_document` |
| `ActivityType` | `call`, `email`, `meeting`, `deadline`, `task` |
| `DealSource` | `inbound`, `outbound`, `referral`, `email`, `event`, `partner`, `website`, `integration`, `import`, `other` |
| `LostReason` | `price`, `timing`, `competitor`, `no_response`, `no_budget`, `not_in_target`, `postponed`, `other` |
| `Priority` | `p1`, `p2`, `p3`, `p4`, `excluded` |
| `RecurringPeriod` | `monthly`, `yearly` |
| `PersonRole` | `decision_maker`, `influencer`, `operational`, `other` |
| `SignatureStatus` | `not_verified`, `to_sign`, `signed` |
| `PaymentStatus` | `not_verified`, `expected`, `partial`, `paid` |
| `ApprovalStatus` | `pending`, `approved`, `rejected` |
| `CommissionType` | `amount`, `percent` |
| `DocumentKind` | `quote`, `contract`, `signature_evidence`, `payment_evidence`, `other` |
| `EmailDirection` | `out`, `in` |
| `EmailStatus` | `queued`, `sending`, `sent`, `failed`, `received` |
| `ImportStatus` | `uploaded`, `mapped`, `queued`, `running`, `done`, `failed` |
| `DealEventType` | `created`, `stage_changed`, `updated`, `won`, `lost`, `reopened` |
