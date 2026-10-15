<?php

namespace Sellinnate\SalesCrm\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Sellinnate\SalesCrm\Enums\ApprovalStatus;
use Sellinnate\SalesCrm\Enums\Priority;
use Sellinnate\SalesCrm\Models\Deal;
use Sellinnate\SalesCrm\Models\Pipeline;
use Sellinnate\SalesCrm\Models\Stage;
use Sellinnate\SalesCrm\Support\Crm;
use Sellinnate\SalesCrm\Support\Search;
use Sellinnate\SalesCrm\Workspaces\Workspace;

/**
 * The reads of the board and of the list of deals: the filters shared by both views, at most
 * `sales-crm.deals.cards_per_column` cards per column in one query (window function), the count,
 * total and weighted total of each column in another, and the version of the pipeline that open
 * boards poll to redraw only when something changed.
 *
 * @phpstan-type Column array{stage: Stage, deals: Collection<int, Deal>, count: int, value: int, weighted: int, more: int}
 * @phpstan-type Filters array{search?: string|null, quick?: list<string>, owner?: int|string|null, priority?: string|null, source?: string|null, tag?: string|null, closed?: string|null, stage?: int|string|null}
 */
class DealBoard
{
    /**
     * The quick filters of the toolbar (labels: `sales-crm::ui.quick.<filter>`).
     */
    public const QUICK_FILTERS = ['mine', 'p1', 'no_activity', 'stale', 'offer_expired', 'approval'];

    /**
     * The sortable columns of the list.
     */
    public const SORTS = [
        'title' => 'title',
        'value' => 'first_year_value_cents',
        'close' => 'expected_close_date',
        'next' => 'next_activity_at',
        'updated' => 'updated_at',
        'created' => 'created_at',
        'offer' => 'offer_valid_until',
    ];

    public static function perColumn(): int
    {
        return max(5, (int) config('sales-crm.deals.cards_per_column', 50));
    }

    /**
     * The deals the user sees matching the filters.
     *
     * @param  Filters  $filters
     * @return Builder<Deal>
     */
    public function query(Authenticatable $user, array $filters, ?Pipeline $pipeline = null, Workspace|false|null $workspace = false): Builder
    {
        $now = CarbonImmutable::now();
        $table = Crm::table('deals');
        $quick = array_values(array_intersect($filters['quick'] ?? [], self::QUICK_FILTERS));
        $search = trim((string) ($filters['search'] ?? ''));
        $closedDays = max(1, (int) config('sales-crm.deals.closed_visible_days', 90));
        $owner = $filters['owner'] ?? null;

        return Deal::query()
            ->visibleTo($user)
            ->when($pipeline !== null, fn (Builder $query) => $query->where($table.'.pipeline_id', $pipeline?->id))
            // false: every workspace of the user; null: the shared CRM; a workspace: that one.
            ->when($workspace !== false, fn (Builder $query) => $query->inWorkspace($workspace === false ? null : $workspace))
            ->when(($filters['closed'] ?? 'recent') !== 'all', fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->where(fn (Builder $open) => $open->whereNull($table.'.won_at')->whereNull($table.'.lost_at'))
                ->orWhere($table.'.won_at', '>=', $now->subDays($closedDays))
                ->orWhere($table.'.lost_at', '>=', $now->subDays($closedDays))))
            ->when($search !== '', fn (Builder $query) => $this->search($query, $search))
            ->when($owner !== null && $owner !== '', fn (Builder $query) => match (true) {
                $owner === 'none' => $query->whereNull($table.'.owner_id'),
                Crm::isUserKey($owner) => $query->where($table.'.owner_id', $owner),
                default => $query->whereRaw('1 = 0'),
            })
            ->when(Priority::tryFrom((string) ($filters['priority'] ?? '')), fn (Builder $query, Priority $priority) => $query->where($table.'.priority', $priority->value))
            ->when(filled($filters['source'] ?? null), fn (Builder $query) => $query->where($table.'.source', (string) ($filters['source'] ?? '')))
            ->when(filled($filters['stage'] ?? null), fn (Builder $query) => $query->where($table.'.stage_id', (int) ($filters['stage'] ?? 0)))
            // The tag compared with the decoded values: the JSON text escapes accented letters.
            ->when(filled($filters['tag'] ?? null), fn (Builder $query) => $query->whereJsonContains($table.'.tags', trim((string) ($filters['tag'] ?? ''))))
            ->when(in_array('mine', $quick, true), fn (Builder $query) => $query->where($table.'.owner_id', Crm::userKey($user)))
            ->when(in_array('p1', $quick, true), fn (Builder $query) => $query->where($table.'.priority', Priority::P1->value))
            ->when(in_array('no_activity', $quick, true), fn (Builder $query) => $query->open()->whereNull($table.'.next_activity_at'))
            ->when(in_array('offer_expired', $quick, true), fn (Builder $query) => $query->open()->where($table.'.offer_valid_until', '<', $now->toDateString()))
            ->when(in_array('approval', $quick, true), fn (Builder $query) => $query->where($table.'.approval_required', true)->where($table.'.approval_status', ApprovalStatus::Pending->value))
            ->when(in_array('stale', $quick, true), fn (Builder $query) => $this->stale($query, $now));
    }

    /**
     * The columns of the board: every stage with its first cards, the count, the total and the
     * weighted total of the deals matching the filters, and how many more there are.
     *
     * @param  Builder<Deal>  $filtered
     * @param  array<int, int>  $limits  cards per stage beyond the default, by stage id ("Show more")
     * @return list<Column>
     */
    public function columns(Pipeline $pipeline, Builder $filtered, array $limits = []): array
    {
        $stages = $pipeline->stages;
        $table = Crm::table('deals');

        $totals = (clone $filtered)
            ->toBase()
            ->select(Crm::raw($this->wrap($table.'.stage_id').' as stage_id, count(*) as total, coalesce(sum('.$this->wrap($table.'.first_year_value_cents').'), 0) as value'))
            ->groupBy($table.'.stage_id')
            ->get()
            ->keyBy('stage_id');

        $ranked = (clone $filtered)
            ->toBase()
            ->select([$table.'.id', $table.'.stage_id'])
            ->addSelect(Crm::raw('ROW_NUMBER() OVER (PARTITION BY '.$this->wrap($table.'.stage_id').' ORDER BY '.$this->wrap($table.'.position').', '.$this->wrap($table.'.id').') as rn'));

        $ids = Crm::db()->query()->fromSub($ranked, 'ranked')->select('ranked.id')->where(function ($query) use ($stages, $limits): void {
            $query->whereRaw('1 = 0');

            foreach ($stages as $stage) {
                $query->orWhere(fn ($inner) => $inner->where('ranked.stage_id', $stage->id)->where('ranked.rn', '<=', $limits[$stage->id] ?? self::perColumn()));
            }
        });

        $deals = Deal::query()
            ->whereIn($table.'.id', $ids)
            ->with(self::cardRelations())
            ->orderBy($table.'.position')
            ->orderBy($table.'.id')
            ->get()
            ->each(fn (Deal $deal) => $deal->setRelation('stage', $stages->firstWhere('id', $deal->stage_id)))
            ->groupBy('stage_id');

        return array_values($stages->map(function (Stage $stage) use ($deals, $totals): array {
            /** @var Collection<int, Deal> $cards */
            $cards = $deals->get($stage->id) ?? new Collection;
            $row = $totals->get($stage->id);
            $count = (int) ($row->total ?? 0);
            $value = (int) ($row->value ?? 0);

            return [
                'stage' => $stage,
                'deals' => $cards->values(),
                'count' => $count,
                'value' => $value,
                'weighted' => (int) round($value * $stage->probability / 100),
                'more' => max(0, $count - $cards->count()),
            ];
        })->all());
    }

    /**
     * What a card needs, in a constant number of queries.
     *
     * @return array<int|string, mixed>
     */
    public static function cardRelations(): array
    {
        $people = Crm::table('people');

        return [
            'company:id,name',
            'owner',
            'people' => fn ($query) => $query->select([$people.'.id', $people.'.first_name', $people.'.last_name', $people.'.email', $people.'.mobile', $people.'.phone']),
        ];
    }

    /**
     * The version of the board of a pipeline: it changes when a deal is created, changed, moved or
     * deleted, so a board open elsewhere redraws only then.
     */
    public function version(Pipeline $pipeline): string
    {
        $deals = Deal::query()->where('pipeline_id', $pipeline->id)->toBase()->selectRaw('count(*) as total, max(updated_at) as updated')->first();

        return md5(json_encode([
            (int) ($deals->total ?? 0),
            (string) ($deals->updated ?? ''),
            (int) Pipeline::query()->whereKey($pipeline->id)->value('revision'),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  Builder<Deal>  $query
     */
    private function search(Builder $query, string $search): void
    {
        $pattern = Search::contains($search);
        $digits = (string) preg_replace('/\D+/', '', $search);
        $table = Crm::table('deals');
        $peopleTable = Crm::table('people');

        $people = Crm::db()->table($peopleTable)
            ->select($peopleTable.'.id')
            ->where(function ($inner) use ($pattern, $digits, $peopleTable): void {
                $inner->whereRaw(Search::like('LOWER('.Search::concat("COALESCE({$this->wrap($peopleTable.'.first_name')}, '')", "COALESCE({$this->wrap($peopleTable.'.last_name')}, '')").')'), [$pattern])
                    ->whereRaw(Search::like("LOWER(COALESCE({$this->wrap($peopleTable.'.email')}, ''))"), [$pattern], 'or');

                if (strlen($digits) >= 4) {
                    $inner->orWhere($peopleTable.'.mobile', 'like', '%'.$digits.'%')->orWhere($peopleTable.'.phone', 'like', '%'.$digits.'%');
                }
            });

        $query->where(fn (Builder $inner) => $inner
            ->whereRaw(Search::like("LOWER({$this->wrap($table.'.title')})"), [$pattern])
            ->whereRaw(Search::like("LOWER(COALESCE({$this->wrap($table.'.external_ref')}, ''))"), [$pattern], 'or')
            ->orWhereIn($table.'.company_id', Crm::db()->table(Crm::table('companies'))->select('id')->whereRaw(Search::like('LOWER(name)'), [$pattern]))
            ->orWhereIn($table.'.id', Crm::db()->table(Crm::table('deal_person'))->select('deal_id')->whereIn('person_id', $people)));
    }

    /**
     * Open deals idle beyond the rotting days of their stage: one threshold per stage.
     *
     * @param  Builder<Deal>  $query
     */
    private function stale(Builder $query, CarbonImmutable $now): void
    {
        $table = Crm::table('deals');
        $stages = Stage::query()->whereNotNull('rotting_days')->whereIn('pipeline_id', (clone $query)->toBase()->select($table.'.pipeline_id'))->get(['id', 'rotting_days']);

        $query->open()->where(function (Builder $inner) use ($stages, $now, $table): void {
            $inner->whereRaw('1 = 0');

            foreach ($stages as $stage) {
                $inner->orWhere(fn (Builder $one) => $one
                    ->where($table.'.stage_id', $stage->id)
                    ->where(Crm::raw("COALESCE({$this->wrap($table.'.last_activity_at')}, {$this->wrap($table.'.stage_entered_at')}, {$this->wrap($table.'.created_at')})"), '<', $now->startOfDay()->subDays((int) $stage->rotting_days)->toDateTimeString()));
            }
        });
    }

    private function wrap(string $column): string
    {
        return Crm::db()->getQueryGrammar()->wrap($column);
    }
}
