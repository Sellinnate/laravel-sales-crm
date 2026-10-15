---
title: "Timeline"
description: "The single history of a deal, company or person: notes, activities done, emails, changes and documents, plus read-only entries from your own systems via timeline providers."
---

# Timeline

Every deal, company and person has a **timeline**: one list, newest first, of
everything that happened. Colleagues open a deal and understand its story in
seconds, without asking.

## What's on it

| Kind | Key | Entries | On |
|---|---|---|---|
| Notes | `note` | Every note, **pinned notes on top** | deals, companies, people |
| Activities | `activity` | Activities **done**, with their outcome | deals, companies, people |
| Emails | `email` | Sent, failed and received emails | deals, companies (emails of their deals and people), people |
| Changes | `event` | Creation, stage changes, won/lost/reopened, field changes with old → new value | deals only |
| Documents | `document` | Uploaded files, with kind, version and validity | deals, companies |
| Yours | your key | Read-only entries from a [timeline provider](#timeline-providers) | wherever your provider returns them |

The UI shows a tab per kind with a counter, so users can filter ("only
emails"). The timeline is capped at `sales-crm.timeline.limit` entries (80 by
default); the counters are not capped.

## Reading it from PHP

```php
use Sellinnate\SalesCrm\Facades\SalesCrm;

$timeline = SalesCrm::timeline();

$entries = $timeline->for($deal, $user);                 // all kinds, newest first
$emails  = $timeline->for($deal, $user, 'email', 20);    // one kind, at most 20
$counts  = $timeline->counts($deal, $user);              // ['note' => 3, 'activity' => 5, 'email' => 2, …]
$kinds   = $timeline->kinds($company);                   // ['note' => 'Notes', …] (labels translated)

foreach ($entries as $entry) {
    $entry->kind;     // "note", "activity", "email", "event", "document" or a provider key
    $entry->at;       // CarbonImmutable
    $entry->title;
    $entry->body;     // may be null
    $entry->author;   // display name, may be null
    $entry->pinned;
    $entry->toArray();
}
```

Each entry is a `Sellinnate\SalesCrm\Data\TimelineEntry` with the properties
`key`, `kind`, `at`, `icon`, `title`, `body`, `meta`, `author`, `pinned`, `id`
and `url`.

## Timeline providers {#timeline-providers}

The CRM doesn't do telephony, support tickets or invoicing, but your app might.
A **timeline provider** mixes read-only entries from your own tables into the
timelines, so a salesperson sees "Call with Jane, 4 min" or "Invoice #1042
paid" right next to the notes.

### 1. Implement the contract

```php
namespace App\Crm;

use App\Models\PhoneCall;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Sellinnate\SalesCrm\Contracts\TimelineProvider;
use Sellinnate\SalesCrm\Data\TimelineEntry;
use Sellinnate\SalesCrm\Models\Company;
use Sellinnate\SalesCrm\Models\Deal;
use Sellinnate\SalesCrm\Models\Person;

class PhoneCallsTimeline implements TimelineProvider
{
    public function kind(): string
    {
        return 'calls';                       // letters, digits, dashes: the key of the filter tab
    }

    public function label(): string
    {
        return 'Phone calls';
    }

    public function entries(Deal|Company|Person $subject, ?Authenticatable $viewer, int $limit): iterable
    {
        foreach ($this->query($subject)->latest('started_at')->limit($limit)->get() as $call) {
            yield new TimelineEntry(
                key: 'calls-'.$call->id,      // unique across the whole timeline
                kind: $this->kind(),
                at: CarbonImmutable::parse($call->started_at),
                icon: 'phone',
                title: ($call->direction === 'in' ? 'Incoming call' : 'Outgoing call').' · '.$call->number,
                body: $call->summary,
                meta: gmdate('i:s', $call->duration_seconds).' min',
                author: $call->agent_name,
            );
        }
    }

    public function count(Deal|Company|Person $subject, ?Authenticatable $viewer): int
    {
        return $this->query($subject)->count();
    }

    private function query(Deal|Company|Person $subject)
    {
        $numbers = match (true) {
            $subject instanceof Person  => array_filter([$subject->mobile, $subject->phone]),
            $subject instanceof Company => $subject->people->flatMap(fn ($p) => [$p->mobile, $p->phone])->filter()->all(),
            $subject instanceof Deal    => $subject->people->flatMap(fn ($p) => [$p->mobile, $p->phone])->filter()->all(),
        };

        return PhoneCall::query()->whereIn('number', $numbers);
    }
}
```

The numbers of people are stored in E.164 (`+15551234567`), which makes
matching with telephony logs straightforward.

### 2. Register it

```php
// config/sales-crm.php
'timeline' => [
    'providers' => [
        App\Crm\PhoneCallsTimeline::class,
    ],
    'limit' => 80,
],
```

Providers are resolved from the container, so you can type-hint dependencies in
the constructor.

### Rules for providers

- **Respect the viewer.** `$viewer` is the user looking at the timeline (or
  `null`); return only what they may see.
- **Use the `$limit`.** Never return more than asked; sort newest first.
- **Keep `count()` cheap**: it runs every time a timeline is shown.
- **Icons**: use one of the UI's icon names, for example `phone`, `mail`,
  `calendar`, `clock`, `file-text`, `receipt`, `coins`, `users`, `user`,
  `building`, `inbox`, `send`, `check`, `alert`, `tag`, `link`,
  `external-link`. An unknown name falls back to a dot.
- The `url` property is included in `toArray()` (for example in the MCP
  `crm_deal_get` result); the built-in UI doesn't render it as a link.

## Best practices

- **Prefer providers over copying data** into notes: the source of truth stays
  in your system.
- **Prefix keys with your kind** (`calls-42`) so they never collide with the
  CRM's own keys.

## Common pitfalls

- A provider class not implementing `TimelineProvider` is silently skipped:
  check the `implements` clause.
- Slow providers slow down every deal panel. Index the columns you filter on.
