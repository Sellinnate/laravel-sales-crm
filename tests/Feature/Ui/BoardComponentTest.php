<?php

use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Sellinnate\SalesCrm\Facades\SalesCrm;
use Sellinnate\SalesCrm\Livewire\DealPanel;
use Sellinnate\SalesCrm\Livewire\DealsBoard;
use Sellinnate\SalesCrm\Models\Deal;
use Sellinnate\SalesCrm\Models\SavedView;

beforeEach(function () {
    $this->seller = crmUser(['name' => 'Sam Seller']);
    $this->admin = crmUser(['name' => 'Alex Admin', 'is_admin' => true]);
    $this->pipeline = crmPipeline();
    $this->actingAs($this->seller);
});

it('shows the board with its columns and the getting started guide when empty', function () {
    Livewire::test(DealsBoard::class)
        ->assertSee(['New lead', 'Proposal sent', 'Won'])
        ->assertSee(__('sales-crm::ui.guide.title'));

    SalesCrm::shared()->createDeal(['title' => 'Visible deal', 'company_name' => 'Acme'], $this->seller);

    Livewire::test(DealsBoard::class)
        ->assertSee(['Visible deal', 'Acme'])
        ->assertDontSee(__('sales-crm::ui.guide.title'));
});

it('creates a deal from the quick add inside the component', function () {
    Livewire::test(DealsBoard::class)
        ->call('startCreate')
        ->assertSet('showCreate', true)
        ->set('draft.title', 'Quick deal')
        ->set('draft.company_name', 'Acme')
        ->set('draft.person.first_name', 'Jane')
        ->set('draft.amount_once', '2500')
        ->call('createDeal')
        ->assertHasNoErrors()
        ->assertSet('showCreate', false)
        ->assertSee('Quick deal')
        ->assertDispatched('crm-notify');

    $deal = Deal::query()->where('title', 'Quick deal')->firstOrFail();
    expect($deal->owner_id)->toBe($this->seller->id)->and($deal->amount_once_cents)->toBe(250000);
});

it('shows the errors of the quick add next to its fields', function () {
    Livewire::test(DealsBoard::class)
        ->call('startCreate')
        ->set('draft.title', '')
        ->call('createDeal')
        ->assertHasErrors(['draft.title'])
        ->set('draft.title', 'Needs amount')
        ->set('draft.stage_id', (string) crmStage($this->pipeline, 'Proposal sent')->id)
        ->call('createDeal')
        ->assertHasErrors(['draft.requirements.amount']);
});

it('moves a dropped card, and asks the missing fields with the mini form', function () {
    $deal = SalesCrm::shared()->createDeal(['title' => 'Drag me'], $this->seller);
    $qualified = crmStage($this->pipeline, 'Qualified');
    $proposal = crmStage($this->pipeline, 'Proposal sent');

    Livewire::test(DealsBoard::class)->call('moveDeal', $deal->id, $qualified->id, null)->assertSet('showRequirements', false);
    expect($deal->fresh()->stage_id)->toBe($qualified->id);

    $component = Livewire::test(DealsBoard::class)
        ->call('moveDeal', $deal->id, $proposal->id, null)
        ->assertSet('showRequirements', true)
        ->assertSet('pendingDeal', $deal->id)
        ->assertSee(__('sales-crm::ui.requirements_title', ['stage' => 'Proposal sent']));
    expect($deal->fresh()->stage_id)->toBe($qualified->id);

    $component->set('fill.amount_once', '1000')->call('completeMove')->assertHasErrors(['fill.requirements.offer_valid_until']);
    $component->set('fill.offer_valid_until', '2030-01-01')->call('completeMove')->assertHasNoErrors()->assertSet('showRequirements', false);

    expect($deal->fresh()->stage_id)->toBe($proposal->id)->and($deal->fresh()->amount_once_cents)->toBe(100000);
});

it('asks the reason of a loss and moves with the keyboard and the menu', function () {
    $deal = SalesCrm::shared()->createDeal(['title' => 'Keyboard'], $this->seller);

    Livewire::test(DealsBoard::class)->call('shiftDeal', $deal->id, 1);
    expect($deal->fresh()->stage->name)->toBe('Qualified');

    Livewire::test(DealsBoard::class)
        ->call('moveTo', $deal->id, crmStage($this->pipeline, 'Lost')->id)
        ->assertSet('showRequirements', true)
        ->set('fill.lost_reason', 'timing')
        ->call('completeMove')
        ->assertSet('showRequirements', false);

    expect($deal->fresh()->isLost())->toBeTrue();
});

it('filters with the quick filters and the search', function () {
    SalesCrm::shared()->createDeal(['title' => 'Mine P1', 'priority' => 'p1'], $this->seller);
    SalesCrm::shared()->createDeal(['title' => 'Somebody else', 'owner_id' => $this->admin->id], $this->admin);

    Livewire::test(DealsBoard::class)
        ->call('toggleQuick', 'mine')
        ->assertSee('Mine P1')->assertDontSee('Somebody else')
        ->call('toggleQuick', 'mine')
        ->set('search', 'somebody')
        ->assertSee('Somebody else')->assertDontSee('Mine P1')
        ->call('resetFilters')
        ->assertSee(['Mine P1', 'Somebody else']);
});

it('works the list: sorting, inline edit and bulk actions', function () {
    $a = SalesCrm::shared()->createDeal(['title' => 'Alpha', 'amount_once' => 100], $this->seller);
    $b = SalesCrm::shared()->createDeal(['title' => 'Bravo', 'amount_once' => 900], $this->seller);

    $component = Livewire::test(DealsBoard::class)->call('setView', 'list')->assertSee(['Alpha', 'Bravo']);
    $component->call('sortBy', 'value')->assertSet('sort', 'value');

    $component->call('updateField', $a->id, 'next_step', 'Call them')->call('updateField', $a->id, 'priority', 'p2');
    expect($a->fresh()->next_step)->toBe('Call them')->and($a->fresh()->priority?->value)->toBe('p2');

    $component->set('selected', [(string) $a->id, (string) $b->id])->set('bulkAction', 'priority')->set('bulkValue', 'p1')->call('applyBulk');
    expect($a->fresh()->priority?->value)->toBe('p1')->and($b->fresh()->priority?->value)->toBe('p1');

    expect($component->instance()->exportUrl('csv'))->toContain('/crm/export/deals')->toContain('format=csv');
});

it('saves and applies views, chooses columns and card fields', function () {
    $component = Livewire::test(DealsBoard::class)
        ->call('toggleQuick', 'p1')
        ->set('viewName', 'Hot deals')
        ->call('saveView')
        ->assertSet('showSaveView', false);

    $view = SavedView::query()->firstOrFail();
    expect($view->state['quick'])->toBe(['p1']);

    $component->call('resetFilters')->call('applyView', $view->id)->assertSet('quick', ['p1']);

    $component->call('chooseCardFields')->set('cardChoice', ['amount', 'owner'])->call('applyCardFields')->assertSet('cardFields', 'amount,owner');
    $component->call('setView', 'list')->call('chooseColumns')->set('columnChoice', ['stage', 'owner'])->call('applyColumns')->assertSet('listColumns', 'stage,owner');
});

it('opens the panel of a deal and redraws when the pipeline changes elsewhere', function () {
    $deal = SalesCrm::shared()->createDeal(['title' => 'Panel deal'], $this->seller);

    $component = Livewire::test(DealsBoard::class)->call('openDeal', $deal->id)->assertSet('showPanel', true)->assertSet('openDealId', $deal->id);
    $component->call('refreshIfChanged');

    SalesCrm::shared()->createDeal(['title' => 'Added elsewhere'], $this->admin);
    $component->call('refreshIfChanged')->assertSee('Added elsewhere');
});

it('keeps the deals of other workspaces out of the board', function () {
    useTeams();
    $alpha = crmTeam('Alpha', $this->seller);
    $beta = crmTeam('Beta', $this->admin);
    SalesCrm::for($alpha)->createDeal(['title' => 'Alpha deal'], $this->seller);
    $secret = SalesCrm::for($beta)->createDeal(['title' => 'Beta secret'], $this->admin);

    Livewire::test(DealsBoard::class)
        ->assertSee('Alpha deal')
        ->assertDontSee('Beta secret')
        ->call('openDeal', $secret->id)
        ->assertSet('showPanel', false)
        ->call('setView', 'list')
        ->assertDontSee('Beta secret');
});

it('never lets the browser choose the pending move of the requirements form', function () {
    foreach (['pendingDeal', 'pendingStage', 'pendingAfter'] as $property) {
        expect(fn () => Livewire::test(DealsBoard::class)->set($property, 1))->toThrow(CannotUpdateLockedPropertyException::class);
    }

    expect(fn () => Livewire::test(DealPanel::class, ['dealId' => SalesCrm::shared()->createDeal(['title' => 'Locked'], $this->seller)->id])->set('pendingStage', 1))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('forgets the selection of the list when other rows are shown', function () {
    $deal = SalesCrm::shared()->createDeal(['title' => 'Selected'], $this->seller);

    Livewire::test(DealsBoard::class)->set('view', 'list')
        ->set('selected', [$deal->id])->call('toggleQuick', 'p1')->assertSet('selected', [])
        ->set('selected', [$deal->id])->call('resetFilters')->assertSet('selected', []);
});

it('creates a deal in a stage asking for the next activity with the type the form shows', function () {
    $qualified = crmStage($this->pipeline, 'Qualified');
    $qualified->forceFill(['required_fields' => ['next_activity']])->save();

    Livewire::test(DealsBoard::class)
        ->call('startCreate', $qualified->id)
        ->set('draft.title', 'Needs a call')
        ->set('draft.next_activity.title', 'Discovery call')
        ->call('createDeal')
        ->assertHasNoErrors();

    $deal = Deal::query()->where('title', 'Needs a call')->firstOrFail();
    expect($deal->stage_id)->toBe($qualified->id)
        ->and($deal->activities()->first()?->type->value)->toBe('call');
});
