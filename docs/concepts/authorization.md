---
title: "Authorization"
description: "Who can enter the CRM, who configures it and who sees which deals: the two gates, deal visibility, the policies and how to customise them."
---

# Authorization

The CRM answers three questions for every user and every action:

1. **May this user enter the CRM at all?** (the *access* gate)
2. **May they configure the CRM of this workspace?** (the *manage* gate)
3. **May they see and work this record?** (workspace + visibility + policies)

::: callout warning "Open by default"
Until you define the gates, **every authenticated user can use and configure
the CRM**. That's convenient for a first try and wrong for production: define
the gates before going live.
:::

## The two gates

```php
// app/Providers/AppServiceProvider.php
use App\Models\User;
use Illuminate\Support\Facades\Gate;

public function boot(): void
{
    // Enter the CRM: board, deals, contacts, agenda, reports, API, MCP.
    Gate::define('sales-crm.access', fn (User $user) => $user->hasRole('sales') || $user->hasRole('sales-manager'));

    // Configure the CRM of a workspace. $workspace is your workspace model, or null in the shared CRM.
    Gate::define('sales-crm.manage', fn (User $user, $workspace = null) => $user->hasRole('sales-manager'));
}
```

The package defines both gates **only if your app hasn't**, with the default
rule "any authenticated user". Your definitions win. You can rename the
abilities with `sales-crm.authorization.access_ability` and `manage_ability`.

::: callout tip "Per-workspace managers"
With [workspaces](/concepts/workspaces), the manage gate receives the workspace
model, so "admin of this team" is easy:

```php
Gate::define('sales-crm.manage', fn (User $user, $team = null) => $team !== null && $team->owner_id === $user->id);
```

Always give `$workspace` a default of `null`: in the shared CRM it is `null`.
:::

### What managers can do

Users who pass the manage gate for a workspace (and work in it) can, on top of
everything else:

- create, edit and delete **pipelines and stages**, the **custom fields**, the
  **email templates** and the **entry stage** for integrations;
- run **imports**;
- **delete** deals, companies and people (and any note or document);
- **decide any approval** (except on deals they own);
- see **all deals** when `deal_visibility` is `owner`.

Everyone else can see the settings page but not change it, and doesn't see the
Import item in the navigation.

## Deal visibility

```dotenv
SALES_CRM_DEAL_VISIBILITY=workspace   # default
SALES_CRM_DEAL_VISIBILITY=owner
```

- **`workspace`**: everyone who works in a workspace sees and works all its
  deals. Good for small, collaborative teams.
- **`owner`**: users who **cannot manage** the workspace only see the deals
  they own and the **unassigned** ones. Managers still see everything. Good when
  salespeople must not see each other's pipeline.

Visibility applies everywhere: board, list, export, reports, search, API, MCP,
and to the activities, notes, documents and emails of hidden deals, which
`visibleTo($user)` leaves out of the agenda and of the timelines of companies
and people too. Records without a deal (a note on a company) follow the
workspace only.

## The policies

The CRM registers regular Laravel policies for its models
(`sales-crm.authorization.policies`):

| Model | Policy | Rules |
|---|---|---|
| `Deal` | `DealPolicy` | view/update: works in the workspace and can see the deal; create: works in the workspace; delete: can see it **and** manages the workspace. |
| `Pipeline` | `PipelinePolicy` | view: works in the workspace; create/update/delete: manages the workspace. |
| `Company` | `CompanyPolicy` | view/create/update: works in the workspace; delete: manages it. |
| `Person` | `PersonPolicy` | view/create/update: works in the workspace; delete: manages it. |
| `Activity` | `ActivityPolicy` | view/update/delete: works in the workspace and can see the activity's deal. |
| `Note` | `NotePolicy` | view and pin: works in the workspace and sees the deal; update: the author; delete: the author or a manager. |
| `Document` | `DocumentPolicy` | view (download): works in the workspace and sees the deal; delete: the uploader or a manager. |

Because they're normal policies you can use them in your own code:

```php
$user->can('update', $deal);
Gate::forUser($user)->authorize('delete', $deal);
```

All actions of the CRM call them when a user is given. With **no user** (system
writes such as `createDealFrom()` without `$by`), authorization is skipped.

### Changing one rule {#changing-one-rule}

Extend the policy and map your class in the config. Example: let only the
owner edit a deal.

```php
namespace App\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use Sellinnate\SalesCrm\Models\Deal;
use Sellinnate\SalesCrm\Policies\DealPolicy;

class CrmDealPolicy extends DealPolicy
{
    public function update(Authenticatable $user, Deal $deal): bool
    {
        return parent::update($user, $deal)
            && ((string) $deal->owner_id === (string) $user->getAuthIdentifier()
                || $this->access->canManage($user, $deal->workspaceRef()));
    }
}
```

```php
// config/sales-crm.php
'policies' => [
    Sellinnate\SalesCrm\Models\Deal::class => App\Policies\CrmDealPolicy::class,
    // keep the other entries
],
```

The parent policies receive `Sellinnate\SalesCrm\Authorization\Access` as
`$this->access`, which offers `works()`, `worksOn()`, `canManage()`,
`canSeeDeal()` and more.

## Replacing the rules entirely

The default authorizer (`DefaultAuthorizer`) implements the gates and the deal
visibility described above. To plug in another permission system, implement
the `Authorizer` contract (four methods: `canAccess`, `canManage`,
`canSeeDeal`, `constrainDeals`) and set it in
`sales-crm.authorization.authorizer`. See
[Custom contracts](/extending/contracts#authorizer).

## Limiting who can be picked as owner

Authorization decides who **can act**; `sales-crm.users.scope` decides who
**can be chosen** as owner, assignee or approver (and who can be mentioned).
For example, to list only the sales team:

```php
// config/sales-crm.php
'users' => ['name_attribute' => 'name', 'email_attribute' => 'email', 'scope' => 'salesTeam'],

// app/Models/User.php
public function scopeSalesTeam($query)
{
    return $query->whereIn('role', ['sales', 'sales-manager']);
}
```

## Interfaces

- **Web UI**: every page requires the access gate (403 otherwise), on top of
  the `sales-crm.ui.middleware` (by default `web` and `auth`).
- **JSON API**: 403 for users without access; policy failures are 403 too.
- **MCP**: tools answer "You are not allowed to use the CRM." for users without
  access, and act with the user's permissions.

## Best practices

- **Define both gates** in production.
- **Use `owner` visibility** when salespeople are paid on their own pipeline.
- **Keep managers few**: they can delete deals and change the pipeline for
  everybody in the workspace.

## Common pitfalls

- Declaring the manage gate as `fn (User $user, Team $team)` without a default:
  in the shared CRM the second argument is `null` and the closure fails. Use
  `$team = null`.
- Expecting `owner` visibility to hide deals from managers: managers always see
  all deals of the workspaces they manage.
