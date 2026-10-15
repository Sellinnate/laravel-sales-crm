---
title: "Reports"
description: "The reports dashboard and the Reports service: open pipeline, forecast, win rate, lost reasons, time per stage, attention points, activities, owners and sources."
---

# Reports

The **Reports** page answers the questions a sales manager asks every week:
how much is in the pipeline, what's likely to close and when, how many deals we
won and lost (and why), where deals get stuck, and who is doing what. The same
numbers are available from PHP.

::: callout info "Two words to know"
- **First-year value**: the one-off amount plus one year of the recurring
  amount. A deal of 2,000 + 150/month is worth 3,800 in its first year. All
  report values use it.
- **Weighted value**: the first-year value multiplied by the probability of the
  deal's stage. 10,000 in a 60% stage weighs 6,000. It's a realistic estimate of
  what the pipeline will bring.
:::

## The page

Open **Reports** in the CRM navigation. Filters at the top:

- **Workspace** (when you work in several) or all of them;
- **Owner**, or everybody;
- **Period** (from / to): by default the last 90 days. It applies to won and lost
  deals (counted on the day they were closed), to the time in stage and to the
  activities. The open pipeline and the forecast are always "as of now".

## The figures

| Section | What it shows |
|---|---|
| **Open pipeline** | Open deals by stage: count, value and weighted value, plus totals. |
| **Forecast** | Open deals by month of **expected close date**, for the next 6 months: value, weighted value and count. Deals without an expected close date are not in the forecast. |
| **Won / lost** | Count and value of deals won and lost in the period. |
| **Win rate** | Won ÷ (won + lost) in the period, as a percentage. |
| **Lost reasons** | How many deals were lost for each reason. |
| **Time per stage** | Average days spent in each stage, from the stage visits that ended in the period. Deleted stages keep their row, by their old name. |
| **Needs attention** | Counts of stale deals, open deals without a next activity, expired offers and offers expiring soon. |
| **Activities** | Per user: activities done and overdue. |
| **Owners** | Per owner: open deals and value, won deals and value, lost deals, win rate. |
| **Sources** | Per source: open and won deals and value, share of the won value. |
| **Integrations** | Deals created from your records (`createDealFrom()`): count, open value, won, share of the won value. |

Every figure respects the viewer's permissions: with
`deal_visibility = owner`, a user who doesn't manage the workspace only sees
reports on the deals they can see.

## From PHP

```php
use Carbon\CarbonImmutable;
use Sellinnate\SalesCrm\Facades\SalesCrm;

$report = SalesCrm::reports()->build(
    viewer: $user,
    workspace: false,                         // false = all the viewer's workspaces, null = shared CRM, or a Workspace
    ownerId: null,                            // a user key to filter by owner
    from: CarbonImmutable::now()->subDays(89),
    to: CarbonImmutable::now(),
);

$report['open_total'];     // ['count' => 34, 'value' => 18250000, 'weighted' => 7340000]  (cents)
$report['win_rate'];       // 37.5 (or null without closed deals)
$report['forecast'][0];    // ['month' => CarbonImmutable, 'value' => …, 'weighted' => …, 'count' => …]
$report['lost_reasons'];   // [['reason' => 'Price', 'count' => 4], …]
```

The returned array has the keys `open`, `open_total`, `forecast`, `won`,
`lost`, `win_rate`, `lost_reasons`, `stage_days`, `attention`, `activities`,
`owners`, `sources` and `integrations`. **Values are in cents**; labels (stage
names, lost reasons, owners) are ready to display.

To pass a specific workspace, build the value object from your model:

```php
use Sellinnate\SalesCrm\Workspaces\Workspace;

SalesCrm::reports()->build($user, Workspace::of($team), null, $from, $to);
```

## Building your own numbers

For anything else, query the models directly and keep the viewer's scope:

```php
use Sellinnate\SalesCrm\Models\Deal;

// Won value this quarter, per month (in cents)
Deal::query()->visibleTo($user)
    ->whereBetween('won_at', [now()->startOfQuarter(), now()])
    ->get()
    ->groupBy(fn ($deal) => $deal->won_at->format('Y-m'))
    ->map->sum('first_year_value_cents');
```

`crm_stage_visits` (one row per stay of a deal in a stage, with `entered_at` and
`left_at`) and `crm_deal_events` (every tracked change) are there for deeper
analyses. See the [Database schema](/reference/database).

## Best practices

- **Fill the expected close date** on open deals: the forecast depends on it.
- **Always pick a lost reason that is true**: the lost reasons chart is only as
  good as the data.
- **Review "Needs attention" weekly** with the team.

## Common pitfalls

- Comparing report values with amounts typed in deals: reports use the
  **first-year** value, which includes 12 months of a monthly amount.
- An empty forecast: no open deal has an expected close date in the next six
  months.
