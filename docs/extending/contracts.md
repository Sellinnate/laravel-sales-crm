---
title: "Custom contracts"
description: "Replace the workspace resolver, the authorizer, the email sender, the inbound driver and add timeline providers, with complete examples and how to register each one."
---

# Custom contracts

The CRM's behaviour around your app (who works where, who may do what, how
email leaves and arrives, what else shows on the timeline) sits behind five
PHP interfaces in `Sellinnate\SalesCrm\Contracts`. Each has a default; you
replace one by implementing the interface and registering your class.

::: callout info "In plain words"
A *contract* is a PHP `interface`: a list of methods your class promises to
have. The CRM only talks to the interface, so it doesn't care whether the
answer comes from its default class or from yours.
:::

| Contract | Default | Register yours with |
|---|---|---|
| `WorkspaceResolver` | `SingleWorkspaceResolver` | `sales-crm.workspaces.resolver` |
| `Authorizer` | `DefaultAuthorizer` | `sales-crm.authorization.authorizer` |
| `SenderResolver` | `ConfigSenderResolver` | a container binding |
| `InboundDriver` | `null`, `imap`, `webhook` | `SalesCrm::inbound()->drivers()->extend()` |
| `TimelineProvider` | none | `sales-crm.timeline.providers` |

## WorkspaceResolver {#workspaceresolver}

Decides whether the CRM is split by workspace, which workspaces a user works in
and who works in a workspace. The built-in `RelationWorkspaceResolver` covers
most apps with configuration only ([Workspaces](/concepts/workspaces)); write
your own when the rules are different.

```php
interface WorkspaceResolver
{
    public function enabled(): bool;
    public function workspacesFor(Authenticatable $user): array;            // list<Workspace>
    public function allows(Authenticatable $user, ?Workspace $workspace): bool;
    public function constrain(Builder|QueryBuilder $query, Authenticatable $user, string $typeColumn, string $idColumn): void;
    public function members(?Workspace $workspace): Builder;                // users of the workspace
    public function label(?Workspace $workspace): string;
}
```

### Example: teams with owners and members

Users belong to teams as **members** (a pivot) or as **owner** (a column on
the team), and only users with `is_active` can be assigned:

```php
namespace App\Crm;

use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Sellinnate\SalesCrm\Contracts\WorkspaceResolver;
use Sellinnate\SalesCrm\Workspaces\Workspace;

class TeamWorkspaceResolver implements WorkspaceResolver
{
    /** @var array<string, list<Workspace>> */
    private array $memo = [];

    public function enabled(): bool
    {
        return true;
    }

    public function workspacesFor(Authenticatable $user): array
    {
        $key = (string) $user->getAuthIdentifier();

        return $this->memo[$key] ??= Team::query()
            ->where('owner_id', $key)
            ->orWhereHas('members', fn ($q) => $q->whereKey($key))
            ->get()
            ->map(fn (Team $team) => Workspace::of($team))
            ->values()
            ->all();
    }

    public function allows(Authenticatable $user, ?Workspace $workspace): bool
    {
        foreach ($this->workspacesFor($user) as $mine) {
            if (Workspace::same($mine, $workspace)) {
                return true;
            }
        }

        return false;   // never the shared (null) workspace
    }

    public function constrain(Builder|QueryBuilder $query, Authenticatable $user, string $typeColumn, string $idColumn): void
    {
        $ids = array_map(fn (Workspace $w) => $w->id, $this->workspacesFor($user));

        $query->where($typeColumn, (new Team)->getMorphClass())->whereIn($idColumn, $ids);
    }

    public function members(?Workspace $workspace): Builder
    {
        $team = $workspace?->model();

        if (! $team instanceof Team) {
            return User::query()->whereRaw('1 = 0');   // no workspace, no members
        }

        return User::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q
                ->whereKey($team->owner_id)
                ->orWhereIn('id', $team->members()->select('users.id')))
            ->orderBy('name');
    }

    public function label(?Workspace $workspace): string
    {
        return (string) ($workspace?->model()?->name ?? '');
    }
}
```

Register it:

```php
// config/sales-crm.php
'workspaces' => [
    'resolver' => App\Crm\TeamWorkspaceResolver::class,
],
```

Rules to respect:

- `constrain()` must **filter out everything** when the user has no workspace
  (`whereIn(..., [])` does that).
- `allows()` must return `false` for a `null` workspace when `enabled()` is
  `true`, and `true` only for `null` when it's `false`.
- `members()` returns an Eloquent builder on **your user model**: it is used to
  validate owners, assignees, approvers and mentions, and to fill the pickers.
- The resolver is bound **per request** (scoped), so memoising inside it is
  safe, also under Octane.

## Authorizer {#authorizer}

Answers "may this user enter, configure, see this deal". The default asks two
gates and applies `deal_visibility` ([Authorization](/concepts/authorization)).
Replace it to plug in another permission system.

```php
interface Authorizer
{
    public function canAccess(Authenticatable $user): bool;
    public function canManage(Authenticatable $user, ?Workspace $workspace): bool;
    public function canSeeDeal(Authenticatable $user, Deal $deal): bool;
    public function constrainDeals(Builder $query, Authenticatable $user): void;
}
```

### Example: permissions and regional visibility

Sales reps see only the deals of their region (a custom field), managers see
everything:

```php
namespace App\Crm;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Sellinnate\SalesCrm\Contracts\Authorizer;
use Sellinnate\SalesCrm\Models\Deal;
use Sellinnate\SalesCrm\Workspaces\Workspace;

class RegionAuthorizer implements Authorizer
{
    public function canAccess(Authenticatable $user): bool
    {
        return Gate::forUser($user)->allows('use-crm');
    }

    public function canManage(Authenticatable $user, ?Workspace $workspace): bool
    {
        return Gate::forUser($user)->allows('manage-crm');
    }

    public function canSeeDeal(Authenticatable $user, Deal $deal): bool
    {
        return $this->canManage($user, $deal->workspaceRef())
            || ($deal->custom_values['region'] ?? null) === $user->region
            || (string) $deal->owner_id === (string) $user->getAuthIdentifier();
    }

    public function constrainDeals(Builder $query, Authenticatable $user): void
    {
        if ($this->canManage($user, null)) {
            return;
        }

        $table = $query->getModel()->getTable();

        $query->where(fn (Builder $q) => $q
            ->where($table.'.custom_values->region', $user->region)
            ->orWhere($table.'.owner_id', $user->getAuthIdentifier()));
    }
}
```

```php
// config/sales-crm.php
'authorization' => [
    'authorizer' => App\Crm\RegionAuthorizer::class,
    // ...
],
```

Keep `canSeeDeal()` and `constrainDeals()` **consistent**: the first checks a
single deal (policies), the second filters lists (board, search, reports,
API). The workspace filter is applied before your authorizer is asked, and
`canManage()` is only asked for users who work in the workspace.

::: callout tip "Only one rule to change?"
To change a single permission (say, "only the owner edits a deal"), extending a
policy is simpler than a new authorizer. See
[Authorization](/concepts/authorization#changing-one-rule).
:::

## SenderResolver {#senderresolver}

Decides the From, the Reply-To and the mailer of every email sent from a deal.

```php
interface SenderResolver
{
    public function resolve(?Authenticatable $user, ?Workspace $workspace): Sender;
}
```

`Sender` is a small value object: `new Sender(address, name, replyTo, mailer)`.
`mailer` is the name of a mailer in `config/mail.php` (`null` = default).

### Example: one sending domain per workspace

```php
namespace App\Crm;

use Illuminate\Contracts\Auth\Authenticatable;
use Sellinnate\SalesCrm\Contracts\SenderResolver;
use Sellinnate\SalesCrm\Data\Sender;
use Sellinnate\SalesCrm\Workspaces\Workspace;

class TeamSenderResolver implements SenderResolver
{
    public function resolve(?Authenticatable $user, ?Workspace $workspace): Sender
    {
        $team = $workspace?->model();

        return new Sender(
            address: $team?->sales_email ?? 'sales@example.test',
            name: $user?->name ?? $team?->name,
            replyTo: $team ? "crm+{$team->id}@example.test" : 'crm@example.test',
            mailer: $team?->mailer,               // e.g. a per-team SMTP mailer
        );
    }
}
```

Bind it in a service provider's `register()`:

```php
// app/Providers/AppServiceProvider.php
use App\Crm\TeamSenderResolver;
use Sellinnate\SalesCrm\Contracts\SenderResolver;

public function register(): void
{
    $this->app->bind(SenderResolver::class, TeamSenderResolver::class);
}
```

The address must be accepted by your mail provider for that mailer (SPF/DKIM).

## InboundDriver {#inbounddriver}

A source of inbound emails **polled** by `sales-crm:poll-inbox`. (Push-based
sources call `SalesCrm::inbound()->receive()` instead; see
[Email](/concepts/email#inbound-email).)

```php
interface InboundDriver
{
    /** @return iterable<InboundEmail> the emails not read yet; the driver keeps its own cursor */
    public function fetch(): iterable;
}
```

### Example: a mail provider's API

```php
namespace App\Crm;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Sellinnate\SalesCrm\Contracts\InboundDriver;
use Sellinnate\SalesCrm\Data\InboundEmail;

class AcmeMailDriver implements InboundDriver
{
    public function __construct(private string $token) {}

    public function fetch(): iterable
    {
        $cursor = Cache::get('acme-mail:cursor');

        $response = Http::withToken($this->token)
            ->get('https://api.acme-mail.example/v1/messages', ['after' => $cursor, 'limit' => 50])
            ->throw()
            ->json();

        foreach ($response['messages'] as $message) {
            yield InboundEmail::fromArray([
                'message_id'  => $message['message_id'],
                'from'        => ['email' => $message['from']['address'], 'name' => $message['from']['name']],
                'to'          => $message['to'],
                'cc'          => $message['cc'] ?? [],
                'subject'     => $message['subject'],
                'text'        => $message['text_body'],
                'html'        => $message['html_body'],
                'in_reply_to' => $message['headers']['in-reply-to'] ?? null,
                'references'  => $message['headers']['references'] ?? null,
                'date'        => $message['date'],
                'headers'     => $message['headers'],
            ]);

            Cache::forever('acme-mail:cursor', $message['id']);
        }
    }
}
```

Register it under a name, then select it:

```php
// app/Providers/AppServiceProvider.php
use App\Crm\AcmeMailDriver;
use Sellinnate\SalesCrm\Facades\SalesCrm;

public function boot(): void
{
    SalesCrm::inbound()->drivers()->extend('acme-mail', fn ($app) => new AcmeMailDriver(config('services.acme_mail.token')));
}
```

```dotenv
SALES_CRM_INBOUND_DRIVER=acme-mail
```

```php
// routes/console.php
Schedule::command('sales-crm:poll-inbox')->everyFiveMinutes()->withoutOverlapping();
```

You can also poll one driver explicitly: `php artisan sales-crm:poll-inbox --driver=acme-mail`.

Pass the auto-reply headers (`Auto-Submitted`, `Precedence`, …) in `headers`,
lowercased keys: the CRM uses them to drop out-of-office replies. Returning an
email twice is harmless (it's de-duplicated by Message-ID), but a proper cursor
saves work.

## TimelineProvider {#timelineprovider}

Adds read-only entries from your systems (calls, tickets, orders) to the
timelines of deals, companies and people.

```php
interface TimelineProvider
{
    public function kind(): string;    // key of the filter tab: letters, digits, dashes
    public function label(): string;   // label of the tab
    public function entries(Deal|Company|Person $subject, ?Authenticatable $viewer, int $limit): iterable; // TimelineEntry
    public function count(Deal|Company|Person $subject, ?Authenticatable $viewer): int;
}
```

```php
// config/sales-crm.php
'timeline' => [
    'providers' => [App\Crm\PhoneCallsTimeline::class],
],
```

A complete example is on the [Timeline](/concepts/timeline#timeline-providers)
page.

## Best practices

- **Start from the defaults**: read `SingleWorkspaceResolver`,
  `RelationWorkspaceResolver`, `DefaultAuthorizer` and `ConfigSenderResolver`
  in `vendor/sellinnate/sales-crm/src` before writing your own.
- **Test the scope**: write a test where a user of workspace A tries to read a
  deal of workspace B through `Deal::query()->visibleTo($user)`.
- **Keep implementations fast**: resolvers and authorizers run on every page
  and query.

## Common pitfalls

- A class in the config that doesn't implement the contract: the CRM throws
  `InvalidArgumentException` ("must implement …") at the first use.
- Binding a `SenderResolver` in `boot()` after something already resolved it:
  bind in `register()`.
- A custom `members()` that ignores `sales-crm.users.scope`: if you rely on that
  option, apply the scope yourself.
