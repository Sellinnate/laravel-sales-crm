<?php

namespace Sellinnate\SalesCrm\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Sellinnate\SalesCrm\Actions\CreateDeal;
use Sellinnate\SalesCrm\Enums\DealSource;
use Sellinnate\SalesCrm\Enums\LostReason;
use Sellinnate\SalesCrm\Models\Activity;
use Sellinnate\SalesCrm\Models\Deal;
use Sellinnate\SalesCrm\Models\Stage;
use Sellinnate\SalesCrm\Models\StageVisit;
use Sellinnate\SalesCrm\Support\Crm;
use Sellinnate\SalesCrm\Workspaces\Workspace;

/**
 * The dashboard of the CRM on the deals the viewer sees — a workspace or all of theirs, an owner, a
 * period: the open pipeline by stage, the forecast by month of expected close, won and lost with the
 * win rate and the reasons, the time spent in each stage, what needs attention, the activities and
 * the owners, and the value by source.
 *
 * Values are first-year values (one-off + a year of recurring) in cents; weighted values take the
 * probability of the stage. Won and lost count in the period by the day they were closed.
 *
 * @phpstan-type StageRow array{stage: string, count: int, value: int, weighted: int}
 * @phpstan-type OwnerRow array{owner: string, open: int, open_value: int, won: int, won_value: int, lost: int, win_rate: float|null}
 * @phpstan-type SourceRow array{source: string, open: int, open_value: int, won: int, won_value: int, share: float|null}
 * @phpstan-type Report array{
 *     open: list<StageRow>,
 *     open_total: array{count: int, value: int, weighted: int},
 *     forecast: list<array{month: CarbonImmutable, value: int, weighted: int, count: int}>,
 *     won: array{count: int, value: int},
 *     lost: array{count: int, value: int},
 *     win_rate: float|null,
 *     lost_reasons: list<array{reason: string, count: int}>,
 *     stage_days: list<array{stage: string, days: float, visits: int}>,
 *     attention: array{stale: int, no_activity: int, offer_expired: int, offer_expiring: int},
 *     activities: list<array{user: string, done: int, overdue: int}>,
 *     owners: list<OwnerRow>,
 *     sources: list<SourceRow>,
 *     integrations: array{count: int, open_value: int, won: int, won_value: int, share: float|null}
 * }
 */
class Reports
{
    public const FORECAST_MONTHS = 6;

    public function __construct(private DealBoard $board) {}

    /**
     * @param  Workspace|false|null  $workspace  false: every workspace of the viewer
     * @return Report
     */
    public function build(Authenticatable $viewer, Workspace|false|null $workspace, int|string|null $ownerId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $ownerId = Crm::isUserKey($ownerId) ? $ownerId : null;
        $from = $from->startOfDay();
        $to = $to->endOfDay();
        $table = Crm::table('deals');
        $deals = fn (): Builder => Deal::query()
            ->visibleTo($viewer)
            ->when($workspace !== false, fn (Builder $query) => $query->inWorkspace($workspace === false ? null : $workspace))
            ->when($ownerId !== null && $ownerId !== '', fn (Builder $query) => $query->where($table.'.owner_id', $ownerId));

        $open = $deals()->open()->with('stage:id,name,probability,position,pipeline_id')->get(['id', 'stage_id', 'owner_id', 'first_year_value_cents', 'expected_close_date', 'origin_type', 'source']);
        $won = $deals()->whereBetween('won_at', [$from, $to])->get(['id', 'owner_id', 'first_year_value_cents', 'origin_type', 'source']);
        $lost = $deals()->whereBetween('lost_at', [$from, $to])->get(['id', 'owner_id', 'first_year_value_cents', 'lost_reason', 'source']);
        $weighted = fn (Deal $deal): int => (int) round($deal->first_year_value_cents * $deal->stage->probability / 100);

        $byStage = array_values($open->groupBy('stage_id')->map(fn (Collection $group): array => [
            'stage' => (string) $group->first()?->stage->name,
            'position' => (int) $group->first()?->stage->position,
            'pipeline' => (int) $group->first()?->stage->pipeline_id,
            'count' => $group->count(),
            'value' => (int) $group->sum('first_year_value_cents'),
            'weighted' => (int) $group->sum($weighted),
        ])->sortBy([['pipeline', 'asc'], ['position', 'asc']])->map(fn (array $row): array => ['stage' => $row['stage'], 'count' => $row['count'], 'value' => $row['value'], 'weighted' => $row['weighted']])->all());

        $closed = $won->count() + $lost->count();
        $wonValue = (int) $won->sum('first_year_value_cents');

        return [
            'open' => $byStage,
            'open_total' => ['count' => $open->count(), 'value' => (int) $open->sum('first_year_value_cents'), 'weighted' => (int) $open->sum($weighted)],
            'forecast' => $this->forecast($open, $weighted),
            'won' => ['count' => $won->count(), 'value' => $wonValue],
            'lost' => ['count' => $lost->count(), 'value' => (int) $lost->sum('first_year_value_cents')],
            'win_rate' => $closed === 0 ? null : round($won->count() / $closed * 100, 1),
            'lost_reasons' => array_values($lost->groupBy(fn (Deal $deal): string => ($deal->lost_reason ?? LostReason::Other)->label())
                ->map(fn (Collection $group, string $reason): array => ['reason' => $reason, 'count' => $group->count()])
                ->sortByDesc('count')->all()),
            'stage_days' => $this->stageDays($deals, $from, $to),
            'attention' => $this->attention($viewer, $workspace, $ownerId),
            'activities' => $this->activities($viewer, $workspace, $ownerId, $deals, $from, $to),
            'owners' => $this->owners($open, $won, $lost),
            'sources' => $this->sources($open, $won, $wonValue),
            'integrations' => $this->integrations($open, $won, $wonValue),
        ];
    }

    /**
     * @param  Collection<int, Deal>  $open
     * @param  callable(Deal): int  $weighted
     * @return list<array{month: CarbonImmutable, value: int, weighted: int, count: int}>
     */
    private function forecast(Collection $open, callable $weighted): array
    {
        $months = [];
        $start = CarbonImmutable::now()->startOfMonth();

        for ($index = 0; $index < self::FORECAST_MONTHS; $index++) {
            $month = $start->addMonths($index);
            $deals = $open->filter(fn (Deal $deal): bool => $deal->expected_close_date !== null && $deal->expected_close_date->format('Y-m') === $month->format('Y-m'));
            $months[] = ['month' => $month, 'value' => (int) $deals->sum('first_year_value_cents'), 'weighted' => (int) $deals->sum($weighted), 'count' => $deals->count()];
        }

        return $months;
    }

    /**
     * The average days spent in each stage, by the visits that left it in the period.
     *
     * @param  callable(): Builder<Deal>  $deals
     * @return list<array{stage: string, days: float, visits: int}>
     */
    private function stageDays(callable $deals, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $visits = StageVisit::query()
            ->whereIn('deal_id', $deals()->select(Crm::table('deals').'.id'))
            ->whereBetween('left_at', [$from, $to])
            ->get(['stage_id', 'stage_name', 'entered_at', 'left_at']);
        $stages = Stage::query()->whereIn('id', $visits->pluck('stage_id')->filter()->unique())->get(['id', 'name', 'position', 'pipeline_id'])->keyBy('id');

        // A stage deleted since keeps its row, by the name it had, after the others.
        return array_values($visits->groupBy(fn (StageVisit $visit): string => $visit->stage_id !== null ? 'id:'.$visit->stage_id : 'name:'.$visit->stage_name)
            ->map(function (Collection $group) use ($stages): array {
                /** @var StageVisit $first */
                $first = $group->first();
                $stage = $first->stage_id !== null ? $stages->get($first->stage_id) : null;

                return [
                    'stage' => $stage !== null ? $stage->name : (string) __('sales-crm::ui.reports.deleted_stage', ['name' => (string) $first->stage_name]),
                    'order' => $stage !== null ? [(int) $stage->pipeline_id, (int) $stage->position] : [PHP_INT_MAX, 0],
                    'days' => round((float) $group->avg(fn (StageVisit $visit): float => $visit->left_at === null ? 0.0 : $visit->entered_at->diffInSeconds($visit->left_at) / 86400), 1),
                    'visits' => $group->count(),
                ];
            })
            ->sortBy('order')
            ->map(fn (array $row): array => ['stage' => $row['stage'], 'days' => $row['days'], 'visits' => $row['visits']])
            ->all());
    }

    /**
     * @return array{stale: int, no_activity: int, offer_expired: int, offer_expiring: int}
     */
    private function attention(Authenticatable $viewer, Workspace|false|null $workspace, int|string|null $ownerId): array
    {
        $count = fn (string $quick): int => $this->board->query($viewer, ['quick' => [$quick], 'owner' => $ownerId, 'closed' => 'all'], null, $workspace)->count();
        $today = CarbonImmutable::today();
        $days = max(0, (int) config('sales-crm.deals.offer_expiring_days', 7));

        return [
            'stale' => $count('stale'),
            'no_activity' => $count('no_activity'),
            'offer_expired' => $count('offer_expired'),
            'offer_expiring' => $this->board->query($viewer, ['owner' => $ownerId, 'closed' => 'all'], null, $workspace)->open()
                // Half-open: SQLite keeps the date with a time, so the last day must be compared as < the next one.
                ->where(Crm::table('deals').'.offer_valid_until', '>=', $today->toDateString())
                ->where(Crm::table('deals').'.offer_valid_until', '<', $today->addDays($days + 1)->toDateString())
                ->count(),
        ];
    }

    /**
     * The activities done in the period and those open and late, by the user they are assigned to.
     *
     * @param  callable(): Builder<Deal>  $deals
     * @return list<array{user: string, done: int, overdue: int}>
     */
    private function activities(Authenticatable $viewer, Workspace|false|null $workspace, int|string|null $ownerId, callable $deals, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $table = Crm::table('activities');
        $activities = Activity::query()
            ->visibleTo($viewer)
            ->when($workspace !== false, fn (Builder $query) => $query->inWorkspace($workspace === false ? null : $workspace))
            ->when($ownerId !== null && $ownerId !== '', fn (Builder $query) => $query->where(fn (Builder $scope) => $scope
                ->whereIn($table.'.deal_id', $deals()->select(Crm::table('deals').'.id'))
                ->orWhere(fn (Builder $records) => $records->whereNull($table.'.deal_id')->where($table.'.assigned_to', $ownerId))))
            ->where(fn (Builder $query) => $query->whereBetween($table.'.done_at', [$from, $to])->orWhere(fn (Builder $late) => $late->late()))
            ->with('assignee')
            ->get(['id', 'assigned_to', 'done_at', 'due_at', 'has_time']);

        // By the assignee: two colleagues with the same name keep their own row.
        return array_values($activities->groupBy(fn (Activity $activity): string => (string) ($activity->assigned_to ?? ''))
            ->map(fn (Collection $group): array => [
                'user' => Crm::userName($group->first()?->assignee) ?: (string) __('sales-crm::ui.reports.unassigned'),
                'done' => $group->whereNotNull('done_at')->count(),
                'overdue' => $group->filter(fn (Activity $activity): bool => $activity->done_at === null && $activity->state() === 'overdue')->count(),
            ])
            ->sortBy('user')->all());
    }

    /**
     * @param  Collection<int, Deal>  $open
     * @param  Collection<int, Deal>  $won
     * @param  Collection<int, Deal>  $lost
     * @return list<OwnerRow>
     */
    private function owners(Collection $open, Collection $won, Collection $lost): array
    {
        $key = fn (mixed $id): string => $id === null ? '' : (string) $id;
        $ids = $open->pluck('owner_id')->merge($won->pluck('owner_id'))->merge($lost->pluck('owner_id'))->map($key)->unique();
        $model = Crm::userModel();
        $users = $model::query()->whereIn((new $model)->getKeyName(), $ids->filter()->values()->all())->get()->keyBy(fn ($user): string => (string) $user->getKey());

        return array_values($ids->map(function (string $id) use ($open, $won, $lost, $users, $key): array {
            $mine = fn (Deal $deal): bool => $key($deal->owner_id) === $id;
            $wonCount = $won->filter($mine)->count();
            $lostCount = $lost->filter($mine)->count();

            return [
                'owner' => $id === '' ? (string) __('sales-crm::ui.reports.no_owner') : (Crm::userName($users->get($id)) ?: '—'),
                'open' => $open->filter($mine)->count(),
                'open_value' => (int) $open->filter($mine)->sum('first_year_value_cents'),
                'won' => $wonCount,
                'won_value' => (int) $won->filter($mine)->sum('first_year_value_cents'),
                'lost' => $lostCount,
                'win_rate' => $wonCount + $lostCount === 0 ? null : round($wonCount / ($wonCount + $lostCount) * 100, 1),
            ];
        })->sortByDesc('open_value')->all());
    }

    /**
     * The value by source of the deals: where the pipeline and the won deals come from.
     *
     * @param  Collection<int, Deal>  $open
     * @param  Collection<int, Deal>  $won
     * @return list<SourceRow>
     */
    private function sources(Collection $open, Collection $won, int $wonValue): array
    {
        $rows = [];

        foreach (DealSource::cases() as $source) {
            $mine = fn (Deal $deal): bool => $deal->source === $source;
            $openCount = $open->filter($mine)->count();
            $wonCount = $won->filter($mine)->count();

            if ($openCount === 0 && $wonCount === 0) {
                continue;
            }

            $wonSum = (int) $won->filter($mine)->sum('first_year_value_cents');
            $rows[] = [
                'source' => $source->label(),
                'open' => $openCount,
                'open_value' => (int) $open->filter($mine)->sum('first_year_value_cents'),
                'won' => $wonCount,
                'won_value' => $wonSum,
                'share' => $wonValue === 0 ? null : round($wonSum / $wonValue * 100, 1),
            ];
        }

        usort($rows, fn (array $a, array $b): int => [$b['won_value'], $b['open_value']] <=> [$a['won_value'], $a['open_value']]);

        return $rows;
    }

    /**
     * The deals born from records of the host ({@see CreateDeal::fromOrigin()}):
     * the proof of value of the integration that feeds the CRM.
     *
     * @param  Collection<int, Deal>  $open
     * @param  Collection<int, Deal>  $won
     * @return array{count: int, open_value: int, won: int, won_value: int, share: float|null}
     */
    private function integrations(Collection $open, Collection $won, int $wonValue): array
    {
        $fromOrigin = fn (Deal $deal): bool => $deal->origin_type !== null;
        $wonSum = (int) $won->filter($fromOrigin)->sum('first_year_value_cents');

        return [
            'count' => $open->filter($fromOrigin)->count() + $won->filter($fromOrigin)->count(),
            'open_value' => (int) $open->filter($fromOrigin)->sum('first_year_value_cents'),
            'won' => $won->filter($fromOrigin)->count(),
            'won_value' => $wonSum,
            'share' => $wonValue === 0 ? null : round($wonSum / $wonValue * 100, 1),
        ];
    }
}
