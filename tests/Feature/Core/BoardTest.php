<?php

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Sellinnate\SalesCrm\Actions\BulkUpdateDeals;
use Sellinnate\SalesCrm\Actions\SaveView;
use Sellinnate\SalesCrm\Facades\SalesCrm;
use Sellinnate\SalesCrm\Models\Deal;
use Sellinnate\SalesCrm\Models\SavedView;
use Sellinnate\SalesCrm\Services\DealBoard;

beforeEach(function () {
    $this->seller = crmUser(['name' => 'Sam Seller']);
    $this->other = crmUser(['name' => 'Olga Other']);
    $this->pipeline = crmPipeline();
    $this->board = app(DealBoard::class);
});

it('builds the columns with counts, totals and weighted totals in a constant number of queries', function () {
    $qualified = crmStage($this->pipeline, 'Qualified');

    foreach (range(1, 6) as $index) {
        SalesCrm::shared()->createDeal(['title' => "Deal {$index}", 'stage_id' => $qualified->id, 'amount_once' => 1000, 'company_name' => "Company {$index}", 'person' => ['first_name' => "P{$index}"]], $this->seller);
    }

    config(['sales-crm.deals.cards_per_column' => 5]);
    DB::enableQueryLog();
    $columns = $this->board->columns($this->pipeline, $this->board->query($this->seller, [], $this->pipeline));
    $queries = count(DB::getQueryLog());
    $column = collect($columns)->first(fn (array $column): bool => $column['stage']->id === $qualified->id);

    expect($column['count'])->toBe(6)
        ->and($column['deals'])->toHaveCount(5)
        ->and($column['more'])->toBe(1)
        ->and($column['value'])->toBe(600000)
        ->and($column['weighted'])->toBe(120000)
        ->and($queries)->toBeLessThanOrEqual(8);

    $more = $this->board->columns($this->pipeline, $this->board->query($this->seller, [], $this->pipeline), [$qualified->id => 10]);
    expect(collect($more)->first(fn (array $column): bool => $column['stage']->id === $qualified->id)['deals'])->toHaveCount(6);
});

it('filters by search, quick filters, owner, priority, source and tag', function () {
    $website = SalesCrm::shared()->createDeal(['title' => 'Website', 'company_name' => 'Acme', 'priority' => 'p1', 'source' => 'referral', 'tags' => ['vip'], 'external_ref' => 'REF-9', 'person' => ['first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@acme.example', 'mobile' => '333 765 4321']], $this->seller);
    $other = SalesCrm::shared()->createDeal(['title' => 'Hosting', 'owner_id' => $this->other->id], $this->other);
    $stale = SalesCrm::shared()->createDeal(['title' => 'Forgotten', 'offer_valid_until' => CarbonImmutable::yesterday()->toDateString()], $this->seller);
    $stale->forceFill(['last_activity_at' => CarbonImmutable::now()->subDays(40)])->save();
    SalesCrm::scheduleActivity($other, ['type' => 'call', 'title' => 'Call', 'due_date' => CarbonImmutable::tomorrow()->toDateString()], $this->other);

    $titles = fn (array $filters): array => $this->board->query($this->seller, $filters)->orderBy('title')->pluck('title')->all();

    expect($titles(['search' => 'acme']))->toBe(['Website'])
        ->and($titles(['search' => 'Jane Doe']))->toBe(['Website'])
        ->and($titles(['search' => '7654321']))->toBe(['Website'])
        ->and($titles(['search' => 'ref-9']))->toBe(['Website'])
        ->and($titles(['search' => '100%']))->toBe([])
        ->and($titles(['quick' => ['mine']]))->toBe(['Forgotten', 'Website'])
        ->and($titles(['quick' => ['p1']]))->toBe(['Website'])
        ->and($titles(['quick' => ['no_activity']]))->toBe(['Forgotten', 'Website'])
        ->and($titles(['quick' => ['stale']]))->toBe(['Forgotten'])
        ->and($titles(['quick' => ['offer_expired']]))->toBe(['Forgotten'])
        ->and($titles(['owner' => (string) $this->other->id]))->toBe(['Hosting'])
        ->and($titles(['owner' => 'none']))->toBe([])
        ->and($titles(['source' => 'referral']))->toBe(['Website'])
        ->and($titles(['tag' => 'vip']))->toBe(['Website']);
});

it('hides the deals closed long ago unless asked', function () {
    $old = SalesCrm::shared()->createDeal(['title' => 'Old win', 'stage_id' => crmStage($this->pipeline, 'Won')->id], $this->seller);
    $old->forceFill(['won_at' => CarbonImmutable::now()->subDays(200)])->save();

    expect($this->board->query($this->seller, [])->count())->toBe(0)
        ->and($this->board->query($this->seller, ['closed' => 'all'])->count())->toBe(1);
});

it('changes the version of the board when something changes', function () {
    $before = $this->board->version($this->pipeline);
    $deal = SalesCrm::shared()->createDeal(['title' => 'New'], $this->seller);
    $afterCreate = $this->board->version($this->pipeline);
    $this->travel(2)->minutes();
    SalesCrm::addNote($deal, 'A note moves the last activity', $this->seller);

    expect($afterCreate)->not->toBe($before)
        ->and($this->board->version($this->pipeline))->not->toBe($afterCreate);
});

it('saves personal and shared views and keeps only known state', function () {
    $view = app(SaveView::class)->handle($this->seller, null, 'My P1', ['view' => 'list', 'quick' => ['p1', 'bogus'], 'sort' => 'value', 'columns' => ['stage', 'nope'], 'card' => ['amount']], shared: true);

    expect($view->state['quick'])->toBe(['p1'])
        ->and($view->state['columns'])->toBe(['stage'])
        ->and($view->state['view'])->toBe('list')
        ->and(SavedView::query()->usableBy($this->other, null)->pluck('name')->all())->toBe(['My P1']);

    app(SaveView::class)->handle($this->seller, null, 'My P1', ['view' => 'kanban']);
    expect(SavedView::query()->count())->toBe(1)->and($view->fresh()->state['view'])->toBe('kanban')->and($view->fresh()->shared)->toBeFalse();

    expect(fn () => app(SaveView::class)->delete($view, $this->other))->toThrow(AuthorizationException::class);
    app(SaveView::class)->delete($view, $this->seller);
    expect(SavedView::query()->count())->toBe(0);
});

it('applies bulk actions deal by deal, reporting those left as they were', function () {
    $proposal = crmStage($this->pipeline, 'Proposal sent');
    $ready = SalesCrm::shared()->createDeal(['title' => 'Ready', 'amount_once' => 100, 'offer_valid_until' => '2030-01-01'], $this->seller);
    $missing = SalesCrm::shared()->createDeal(['title' => 'Missing'], $this->seller);

    $result = app(BulkUpdateDeals::class)->handle([$ready->id, $missing->id], 'stage', $proposal->id, $this->seller);

    expect($result['done'])->toBe(1)
        ->and($result['skipped'][0]['title'])->toBe('Missing')
        ->and($ready->fresh()->stage_id)->toBe($proposal->id)
        ->and($missing->fresh()->stage_id)->not->toBe($proposal->id);

    $result = app(BulkUpdateDeals::class)->handle([$ready->id], 'delete', null, $this->seller);
    expect($result['done'])->toBe(0)->and($result['skipped'])->toHaveCount(1)->and(Deal::query()->count())->toBe(2);
});
