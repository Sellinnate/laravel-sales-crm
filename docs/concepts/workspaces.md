---
title: "Workspaces (multi-tenancy)"
description: "Split the CRM by team, client or account: one shared CRM by default, workspaces from your Eloquent relations, or your own resolver."
---

# Workspaces (multi-tenancy)

By default the whole application shares **one CRM**. If your app serves several
teams, clients or accounts that must not see each other's deals, turn on
**workspaces**: every CRM record then belongs to one of your models (a `Team`,
an `Account`, a `Client`), and users only see the workspaces they belong to.

::: callout info "In plain words"
*Multi-tenancy* means one installation serving many separate customers
(*tenants*). Here a tenant is called a **workspace**, and it's simply a model
you already have. The CRM never creates workspaces: it attaches its records to
yours.
:::

## How it works

Every CRM table has two nullable columns, `workspace_type` and `workspace_id`:
a polymorphic reference to your model (the same mechanism as Laravel's
`morphTo`). In the shared CRM both are `null`.

A **workspace resolver** (contract `WorkspaceResolver`) tells the CRM:

- whether workspaces are on at all;
- which workspaces a user works in;
- which users work in a workspace (they can be owners, assignees, approvers and
  be mentioned);
- how to restrict a query to the user's workspaces;
- the name to show for a workspace.

Every query of the UI, the API and the MCP tools goes through it, as do the
policies.

## Option 1: one shared CRM (default)

```php
'workspaces' => [
    'resolver' => Sellinnate\SalesCrm\Workspaces\SingleWorkspaceResolver::class,
],
```

No workspace columns are used. Every user who passes the `sales-crm.access`
gate works in the CRM, and every user of your user model (optionally narrowed by
`sales-crm.users.scope`) can be picked as owner or assignee. The CRM's name is
`sales-crm.ui.brand`.

From code, use `SalesCrm::shared()` (or `SalesCrm::for(null)`).

## Option 2: workspaces from your relations

If your users belong to teams through a relation, `RelationWorkspaceResolver`
needs only configuration. With models like these:

```php
class User extends Authenticatable
{
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class);
    }
}

class Team extends Model
{
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }
}
```

configure:

```php
// config/sales-crm.php
'workspaces' => [
    'resolver'         => Sellinnate\SalesCrm\Workspaces\RelationWorkspaceResolver::class,
    'model'            => App\Models\Team::class,
    'user_relation'    => 'teams',   // $user->teams(): the workspaces of a user
    'members_relation' => 'users',   // $team->users(): the users of a workspace
    'label_attribute'  => 'name',    // shown in the workspace selector
],

'database' => [
    // ...
    'workspace_key_type' => 'int',   // match teams.id: int, uuid or ulid (set BEFORE migrating)
],
```

- **`user_relation`** can be any relation (`belongsToMany`, `hasMany`…), a
  method returning a collection, or a **single model**: a user who belongs to
  exactly one company works too (`'user_relation' => 'company'` for a
  `belongsTo`).
- **`members_relation`** must be a relation method on the workspace model.
- The user's workspaces are read once per request.

From code, pass the model:

```php
use Sellinnate\SalesCrm\Facades\SalesCrm;

$crm = SalesCrm::for($team);

$crm->createDeal(['title' => 'Website redesign'], by: $user);
$crm->deals()->open()->count();
$crm->defaultPipeline();
```

::: callout warning "With workspaces on, there is no shared CRM"
`RelationWorkspaceResolver` never allows the `null` workspace: every record must
belong to a workspace. `SalesCrm::shared()` is for apps without workspaces.
:::

## Option 3: your own resolver

For anything else (a tenant from the subdomain, a `current_team_id` column,
an external permission service), implement `WorkspaceResolver` and set it in
`workspaces.resolver`. A full example is in
[Custom contracts](/extending/contracts#workspaceresolver).

## What changes in the UI

- Users who work in **several workspaces** get a **workspace selector** on the
  deals, agenda, companies, people and reports pages (with an "all workspaces"
  option) and on the settings page.
- Each workspace has its own pipelines, stages, companies, people, custom
  fields, email templates, entry stage and saved views.
- The default pipeline is created **the first time a workspace is opened**, not
  when your team is created: workspaces that never use the CRM have no rows.

## Workspaces in the API and MCP

The JSON API and the MCP tools identify a workspace with its **key**:
`"<morph type>|<id>"`, for example `"App\Models\Team|7"` (or `"team|7"` if you
use a morph map). Creating a deal without a `pipeline_id` requires the
`workspace` key when workspaces are on.

```php
use Sellinnate\SalesCrm\Workspaces\Workspace;

$workspace = Workspace::of($team);
$workspace->key();                       // "App\Models\Team|7"
Workspace::fromKey('App\Models\Team|7'); // back to the value object
$workspace->model();                     // the Team
```

::: callout tip "Use a morph map"
The `workspace_type` column stores your model's morph class. Registering a
morph map keeps the stored value stable if you rename or move the class:

```php
use Illuminate\Database\Eloquent\Relations\Relation;

Relation::enforceMorphMap(['team' => App\Models\Team::class]);
```

Set it up **before** creating CRM data.
:::

## Checking access in your code

```php
use Sellinnate\SalesCrm\Facades\SalesCrm;

$access = SalesCrm::access();

$access->workspacesFor($user);         // list<Workspace> the user works in
$access->works($user, $team);          // does the user work in this workspace?
$access->canManage($user, $team);      // may they configure it?
$access->members($team)->get();        // users who work in it
$access->label($team);                 // its display name
```

## Demo data with workspaces {#demo-data-with-workspaces}

`php artisan sales-crm:install --demo` fills the **shared** CRM, which is not
visible when workspaces are on. Seed your workspaces from code instead:

```php
use App\Models\Team;
use Sellinnate\SalesCrm\Database\Seeders\DemoSeeder;

$seeder = app(DemoSeeder::class);
$seeder->workspaces = Team::all()->all();
$seeder->run();
```

## Best practices

- **Pick the workspace model that matches your billing or access unit**: what a
  customer pays for is usually what must be isolated.
- **Set `workspace_key_type`** to match that model's key before migrating.
- **Use `Deal::query()->visibleTo($user)`** (or the board service) whenever you
  show CRM data to a user: it applies the workspace filter for you.
- **Use a morph map** from day one.

## Common pitfalls

- `SalesCrm::for($team)->deals()` is **not** permission-filtered: it returns all
  deals of that workspace. Check `SalesCrm::access()->works($user, $team)`
  first, or use `visibleTo($user)`.
- A user removed from a team can no longer be picked as owner, but deals they
  already own keep them as owner until reassigned.
- `members_relation` pointing to a method that isn't an Eloquent relation: the
  workspace has no members, so nobody can be owner or assignee.
