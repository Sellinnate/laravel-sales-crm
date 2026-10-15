<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Sellinnate\SalesCrm\Actions\CreateDeal;
use Sellinnate\SalesCrm\Actions\DeleteDeal;
use Sellinnate\SalesCrm\Actions\MoveDeal;
use Sellinnate\SalesCrm\Actions\UpdateDeal;
use Sellinnate\SalesCrm\Enums\ApprovalStatus;
use Sellinnate\SalesCrm\Enums\DealEventType;
use Sellinnate\SalesCrm\Enums\DocumentKind;
use Sellinnate\SalesCrm\Events\ApprovalRequested;
use Sellinnate\SalesCrm\Events\DealCreated;
use Sellinnate\SalesCrm\Events\DealDeleted;
use Sellinnate\SalesCrm\Events\DealLost;
use Sellinnate\SalesCrm\Events\DealReopened;
use Sellinnate\SalesCrm\Events\DealStageChanged;
use Sellinnate\SalesCrm\Events\DealUpdated;
use Sellinnate\SalesCrm\Events\DealWon;
use Sellinnate\SalesCrm\Facades\SalesCrm;
use Sellinnate\SalesCrm\Models\Activity;
use Sellinnate\SalesCrm\Models\Company;
use Sellinnate\SalesCrm\Models\Deal;
use Sellinnate\SalesCrm\Models\DealEvent;
use Sellinnate\SalesCrm\Models\Document;
use Sellinnate\SalesCrm\Models\Person;
use Sellinnate\SalesCrm\Models\StageVisit;

beforeEach(function () {
    $this->admin = crmUser(['is_admin' => true]);
    $this->seller = crmUser();
    $this->pipeline = crmPipeline();
});

it('creates a deal at the top of the first open stage with company, person and owner', function () {
    Event::fake([DealCreated::class]);

    $older = SalesCrm::shared()->createDeal(['title' => 'Older'], $this->seller);
    $deal = SalesCrm::shared()->createDeal([
        'title' => 'Website redesign',
        'company_name' => 'Acme S.r.l.',
        'person' => ['first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'Jane@Acme.example'],
        'amount_once' => '1500.50',
        'recurring' => 90,
        'recurring_period' => 'monthly',
        'priority' => 'p1',
        'tags' => ['vip', ' vip ', ''],
    ], $this->seller);

    expect($deal->stage->name)->toBe('New lead')
        ->and($deal->owner_id)->toBe($this->seller->id)
        ->and($deal->company->name)->toBe('Acme S.r.l.')
        ->and($deal->people->first()->email)->toBe('jane@acme.example')
        ->and($deal->people->first()->pivot->is_primary)->toBeTruthy()
        ->and($deal->amount_once_cents)->toBe(150050)
        ->and($deal->first_year_value_cents)->toBe(150050 + 9000 * 12)
        ->and($deal->mrrCents())->toBe(9000)
        ->and($deal->arrCents())->toBe(108000)
        ->and($deal->tags)->toBe(['vip'])
        ->and($deal->position)->toBe(0)
        ->and($older->fresh()->position)->toBe(1)
        ->and(DealEvent::query()->where('deal_id', $deal->id)->where('type', 'created')->exists())->toBeTrue()
        ->and(StageVisit::query()->where('deal_id', $deal->id)->whereNull('left_at')->count())->toBe(1);

    Event::assertDispatched(DealCreated::class, fn (DealCreated $event): bool => $event->deal->is($deal) && $event->by?->is($this->seller));
});

it('reuses an existing company and person instead of duplicating them', function () {
    SalesCrm::shared()->createDeal(['title' => 'First', 'company_name' => 'Acme Srl', 'person' => ['first_name' => 'Jane', 'email' => 'jane@acme.example']], $this->seller);
    SalesCrm::shared()->createDeal(['title' => 'Second', 'company_name' => 'ACME s.r.l.', 'person' => ['first_name' => 'J.', 'email' => 'JANE@acme.example']], $this->seller);

    expect(Company::query()->count())->toBe(1)->and(Person::query()->count())->toBe(1);
});

it('asks the fields a stage requires and the reason of a loss', function () {
    $proposal = crmStage($this->pipeline, 'Proposal sent');

    try {
        SalesCrm::shared()->createDeal(['title' => 'Quote', 'stage_id' => $proposal->id], $this->seller);
        $this->fail('The requirements were not checked.');
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['requirements.amount', 'requirements.offer_valid_until']);
    }

    expect(fn () => SalesCrm::shared()->createDeal(['title' => 'Gone', 'stage_id' => crmStage($this->pipeline, 'Lost')->id], $this->seller))
        ->toThrow(ValidationException::class);

    $deal = SalesCrm::shared()->createDeal(['title' => 'Quote', 'stage_id' => $proposal->id, 'amount_once' => 1000, 'offer_valid_until' => '2030-01-01'], $this->seller);
    expect($deal->stage_id)->toBe($proposal->id)
        ->and(Activity::query()->where('deal_id', $deal->id)->value('title'))->toBe('Follow up on the proposal');
});

it('keeps the external reference unique within the workspace', function () {
    SalesCrm::shared()->createDeal(['title' => 'One', 'external_ref' => 'X-1'], $this->seller);

    expect(fn () => SalesCrm::shared()->createDeal(['title' => 'Two', 'external_ref' => 'X-1'], $this->seller))
        ->toThrow(ValidationException::class);
});

it('accepts as owner only a user of the workspace', function () {
    useTeams();
    $team = crmTeam('Alpha', $this->seller);
    $stranger = crmUser();

    expect(fn () => SalesCrm::for($team)->createDeal(['title' => 'X', 'owner_id' => $stranger->id], $this->seller))
        ->toThrow(ValidationException::class);

    $deal = SalesCrm::for($team)->createDeal(['title' => 'X'], $this->seller);
    expect($deal->owner_id)->toBe($this->seller->id)->and($deal->workspace_id)->toBe($team->id);
});

it('records every tracked change in the history, with readable values', function () {
    Event::fake([DealUpdated::class]);
    $deal = SalesCrm::shared()->createDeal(['title' => 'Website', 'amount_once' => 1000], $this->seller);

    app(UpdateDeal::class)->handle($deal, ['title' => 'Website v2', 'amount_once' => 2500, 'owner_id' => $this->admin->id, 'next_step' => 'Call back'], $this->seller);

    $events = DealEvent::query()->where('deal_id', $deal->id)->where('type', DealEventType::Updated->value)->get()->keyBy('field');

    expect($events->keys()->sort()->values()->all())->toBe(['amount_once_cents', 'owner_id', 'title'])
        ->and($events['amount_once_cents']->from)->toBe('€1,000')
        ->and($events['amount_once_cents']->to)->toBe('€2,500')
        ->and($events['owner_id']->to)->toBe($this->admin->name);

    Event::assertDispatched(DealUpdated::class, fn (DealUpdated $event): bool => in_array('next_step', $event->changes, true));
});

it('marks the signature and the payment as verified only with an evidence', function () {
    $deal = SalesCrm::shared()->createDeal(['title' => 'Contract'], $this->seller);

    expect(fn () => app(UpdateDeal::class)->handle($deal, ['signature_status' => 'signed'], $this->seller))->toThrow(ValidationException::class)
        ->and(fn () => app(UpdateDeal::class)->handle($deal, ['payment_status' => 'paid', 'payment_evidence' => 'Wire 123'], $this->seller))->toThrow(ValidationException::class);

    $deal = app(UpdateDeal::class)->handle($deal, ['signature_status' => 'signed', 'signature_evidence' => 'https://sign.example/doc/1'], $this->seller);
    expect($deal->signature_verified_by)->toBe($this->seller->id)->and($deal->signature_verified_at)->not->toBeNull();

    $deal = app(UpdateDeal::class)->handle($deal, ['payment_status' => 'partial', 'amount_collected' => 500, 'payment_evidence' => 'Wire 123'], $this->seller);
    expect($deal->payment_verified_at)->not->toBeNull()->and($deal->amount_collected_cents)->toBe(50000);

    $other = SalesCrm::shared()->createDeal(['title' => 'With contract'], $this->seller);
    Document::query()->create(['deal_id' => $other->id, 'kind' => DocumentKind::Contract, 'name' => 'contract.pdf', 'disk' => 'local', 'path' => 'x/contract.pdf']);
    expect(app(UpdateDeal::class)->handle($other, ['signature_status' => 'signed'], $this->seller)->signature_status->value)->toBe('signed');
});

it('applies the rules of the internal approval', function () {
    Event::fake([ApprovalRequested::class]);
    $approver = crmUser();
    $deal = SalesCrm::shared()->createDeal(['title' => 'Big discount'], $this->seller);

    $deal = app(UpdateDeal::class)->handle($deal, ['approval_required' => true, 'approver_id' => $approver->id, 'approval_note' => '20% discount'], $this->seller);
    expect($deal->approval_status)->toBe(ApprovalStatus::Pending);
    Event::assertDispatched(ApprovalRequested::class);

    // The owner never decides; nobody but the approver or a manager does.
    expect(fn () => app(UpdateDeal::class)->handle($deal, ['approval_status' => 'approved'], $this->seller))->toThrow(ValidationException::class)
        ->and(fn () => app(UpdateDeal::class)->handle($deal, ['approval_status' => 'approved'], crmUser()))->toThrow(ValidationException::class);

    $deal = app(UpdateDeal::class)->handle($deal, ['approval_status' => 'approved'], $approver);
    expect($deal->approval_status)->toBe(ApprovalStatus::Approved)->and($deal->approval_decided_at)->not->toBeNull();

    // A new approver (set by a manager) decides again.
    $deal = app(UpdateDeal::class)->handle($deal, ['approver_id' => $this->admin->id], $this->admin);
    expect($deal->approval_status)->toBe(ApprovalStatus::Pending);

    // Nobody names themselves approver, but a manager.
    $mine = SalesCrm::shared()->createDeal(['title' => 'Mine'], $this->seller);
    expect(fn () => app(UpdateDeal::class)->handle($mine, ['approval_required' => true, 'approver_id' => $this->seller->id], $this->seller))->toThrow(ValidationException::class);
});

it('limits a percent commission to 100% and computes it on the first-year value', function () {
    $deal = SalesCrm::shared()->createDeal(['title' => 'Commission', 'amount_once' => 10000], $this->seller);

    expect(fn () => app(UpdateDeal::class)->handle($deal, ['commission_type' => 'percent', 'commission_value' => 150], $this->seller))->toThrow(ValidationException::class);

    $deal = app(UpdateDeal::class)->handle($deal, ['commission_type' => 'percent', 'commission_value' => 10], $this->seller);
    expect($deal->commissionCents())->toBe(100000);

    $deal = app(UpdateDeal::class)->handle($deal, ['commission_type' => 'amount'], $this->seller);
    expect($deal->commission_value)->toBeNull();
});

it('moves a deal between stages with visits, history, dates and events', function () {
    Event::fake([DealStageChanged::class, DealWon::class, DealLost::class, DealReopened::class]);
    $deal = SalesCrm::shared()->createDeal(['title' => 'Journey', 'amount_once' => 1000, 'owner_id' => $this->seller->id], $this->seller);
    $won = crmStage($this->pipeline, 'Won');
    $lost = crmStage($this->pipeline, 'Lost');
    $qualified = crmStage($this->pipeline, 'Qualified');

    $deal = app(MoveDeal::class)->handle($deal, $won, $this->seller);
    expect($deal->isWon())->toBeTrue();
    Event::assertDispatched(DealWon::class);

    expect(fn () => app(MoveDeal::class)->handle($deal, $lost, $this->seller))->toThrow(ValidationException::class);
    $deal = app(MoveDeal::class)->handle($deal, $lost, $this->seller, ['lost_reason' => 'price', 'lost_note' => 'Too expensive']);
    expect($deal->isLost())->toBeTrue()->and($deal->won_at)->toBeNull()->and($deal->lost_reason?->value)->toBe('price');
    Event::assertDispatched(DealLost::class);

    $deal = app(MoveDeal::class)->handle($deal, $qualified, $this->seller);
    expect($deal->isOpen())->toBeTrue()->and($deal->lost_reason)->toBeNull();
    Event::assertDispatched(DealReopened::class);
    Event::assertDispatchedTimes(DealStageChanged::class, 3);

    expect(StageVisit::query()->where('deal_id', $deal->id)->count())->toBe(4)
        ->and(StageVisit::query()->where('deal_id', $deal->id)->whereNull('left_at')->value('stage_id'))->toBe($qualified->id)
        ->and(DealEvent::query()->where('deal_id', $deal->id)->pluck('type')->map->value->all())->toContain('won', 'lost', 'reopened', 'stage_changed');
});

it('completes the missing fields with the move, person and next activity included', function () {
    $deal = SalesCrm::shared()->createDeal(['title' => 'Signature soon'], $this->seller);
    $signature = crmStage($this->pipeline, 'Signature');

    app(\Sellinnate\SalesCrm\Actions\SaveStage::class)->handle($this->pipeline, ['name' => 'Signature', 'color' => 'info', 'probability' => 90, 'kind' => 'open', 'required_fields' => ['amount', 'owner', 'person', 'next_activity']], $this->admin, $signature);

    try {
        app(MoveDeal::class)->handle($deal, $signature->fresh(), $this->seller);
        $this->fail('Moved without its requirements.');
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['requirements.person', 'requirements.amount', 'requirements.next_activity']);
    }

    $deal = app(MoveDeal::class)->handle($deal, $signature->fresh(), $this->seller, [
        'amount_once' => 5000,
        'person' => ['first_name' => 'Ada', 'email' => 'ada@example.test'],
        'next_activity' => ['type' => 'meeting', 'title' => 'Sign', 'due_date' => CarbonImmutable::tomorrow()->toDateString()],
    ]);

    expect($deal->stage_id)->toBe($signature->id)
        ->and($deal->people()->count())->toBe(1)
        ->and($deal->fresh()->next_activity_at)->not->toBeNull();
});

it('places a dropped card after its neighbour and renumbers the column', function () {
    $stage = crmStage($this->pipeline, 'Qualified');
    $a = SalesCrm::shared()->createDeal(['title' => 'A', 'stage_id' => $stage->id], $this->seller);
    $b = SalesCrm::shared()->createDeal(['title' => 'B', 'stage_id' => $stage->id], $this->seller);
    $c = SalesCrm::shared()->createDeal(['title' => 'C', 'stage_id' => $stage->id], $this->seller);
    // Column: C, B, A — drop C after A, then a new card from another stage after B.
    app(MoveDeal::class)->handle($c, $stage, $this->seller, [], $a->id);
    $order = Deal::query()->where('stage_id', $stage->id)->orderBy('position')->pluck('title')->all();
    expect($order)->toBe(['B', 'A', 'C']);

    $d = SalesCrm::shared()->createDeal(['title' => 'D'], $this->seller);
    app(MoveDeal::class)->handle($d, $stage, $this->seller, [], $b->id);
    expect(Deal::query()->where('stage_id', $stage->id)->orderBy('position')->pluck('title')->all())->toBe(['B', 'D', 'A', 'C'])
        ->and(Deal::query()->where('stage_id', $stage->id)->orderBy('position')->pluck('position')->all())->toBe([0, 1, 2, 3]);
});

it('refuses a stage of another pipeline', function () {
    $deal = SalesCrm::shared()->createDeal(['title' => 'X'], $this->seller);
    $other = app(\Sellinnate\SalesCrm\Actions\SavePipeline::class)->handle(null, ['name' => 'Other'], $this->admin);

    expect(fn () => app(MoveDeal::class)->handle($deal, $other->stages->first(), $this->seller))->toThrow(ValidationException::class);
});

it('deletes a deal only for managers, with its documents', function () {
    Event::fake([DealDeleted::class]);
    \Illuminate\Support\Facades\Storage::fake('local');
    $deal = SalesCrm::shared()->createDeal(['title' => 'Delete me'], $this->seller);
    \Illuminate\Support\Facades\Storage::disk('local')->put('sales-crm/x.pdf', 'pdf');
    Document::query()->create(['deal_id' => $deal->id, 'kind' => 'quote', 'name' => 'x.pdf', 'disk' => 'local', 'path' => 'sales-crm/x.pdf']);

    expect(fn () => app(DeleteDeal::class)->handle($deal, $this->seller))->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);

    app(DeleteDeal::class)->handle($deal, $this->admin);

    expect(Deal::query()->find($deal->id))->toBeNull()
        ->and(Document::query()->count())->toBe(0);
    \Illuminate\Support\Facades\Storage::disk('local')->assertMissing('sales-crm/x.pdf');
    Event::assertDispatched(DealDeleted::class, fn (DealDeleted $event): bool => $event->dealId === $deal->id && $event->attributes['title'] === 'Delete me');
});

it('creates one deal per record of the host, in the entry stage of the workspace', function () {
    $origin = crmUser(['name' => 'An order']);
    $meeting = crmStage($this->pipeline, 'Meeting / demo');
    app(\Sellinnate\SalesCrm\Actions\SaveWorkspaceSettings::class)->handle(null, ['entry_stage_id' => $meeting->id], $this->admin);

    $deal = SalesCrm::shared()->createDealFrom($origin, ['title' => 'From the order']);
    $again = SalesCrm::shared()->createDealFrom($origin, ['title' => 'Again']);

    expect($again->id)->toBe($deal->id)
        ->and($deal->stage_id)->toBe($meeting->id)
        ->and($deal->source->value)->toBe('integration')
        ->and($deal->origin?->is($origin))->toBeTrue()
        ->and(Deal::query()->count())->toBe(1);
});

it('computes the derived state of a deal', function () {
    $deal = SalesCrm::shared()->createDeal(['title' => 'Stale', 'amount_once' => 1000, 'offer_valid_until' => CarbonImmutable::today()->addDays(3)->toDateString()], $this->seller);
    $deal->forceFill(['last_activity_at' => CarbonImmutable::now()->subDays(30), 'stage_entered_at' => CarbonImmutable::now()->subDays(30)])->save();
    $deal = $deal->fresh(['stage']);

    expect($deal->staleDays())->toBe(30)
        ->and($deal->daysInStage())->toBe(30)
        ->and($deal->offerState())->toBe('expiring')
        ->and($deal->nextActivityState())->toBe('none')
        ->and($deal->weightedCents())->toBe(10000);
});
