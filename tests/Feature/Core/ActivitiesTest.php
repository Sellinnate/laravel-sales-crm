<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Sellinnate\SalesCrm\Actions\ManageActivities;
use Sellinnate\SalesCrm\Events\ActivityCompleted;
use Sellinnate\SalesCrm\Events\ActivityDue;
use Sellinnate\SalesCrm\Facades\SalesCrm;
use Sellinnate\SalesCrm\Models\Activity;
use Sellinnate\SalesCrm\Notifications\ActivityDueNotification;

beforeEach(function () {
    $this->seller = crmUser();
    $this->deal = SalesCrm::shared()->createDeal(['title' => 'Website', 'person' => ['first_name' => 'Jane', 'email' => 'jane@acme.example']], $this->seller);
    $this->actions = app(ManageActivities::class);
});

it('plans activities, assigned to the author, and keeps the next one on the deal', function () {
    $later = $this->actions->save($this->deal, ['type' => 'meeting', 'title' => 'Demo', 'due_date' => CarbonImmutable::today()->addDays(5)->toDateString(), 'due_time' => '15:00'], $this->seller);
    $sooner = $this->actions->save($this->deal, ['type' => 'call', 'title' => 'Call', 'due_date' => CarbonImmutable::today()->addDay()->toDateString()], $this->seller);

    expect($later->assigned_to)->toBe($this->seller->id)
        ->and($sooner->has_time)->toBeFalse()
        ->and($sooner->due_at->format('H:i'))->toBe('09:00')
        ->and($this->deal->fresh()->next_activity_at->toDateTimeString())->toBe($sooner->deadline()->toDateTimeString());

    $this->actions->complete($sooner, 'Talked, interested', $this->seller);
    $deal = $this->deal->fresh();

    expect($deal->next_activity_at->toDateTimeString())->toBe($later->due_at->toDateTimeString())
        ->and($deal->last_contacted_at)->not->toBeNull()
        ->and($sooner->fresh()->outcome)->toBe('Talked, interested');

    $this->actions->reopen($sooner->fresh(), $this->seller);
    expect($sooner->fresh()->done_at)->toBeNull();

    $this->actions->delete($later, $this->seller);
    expect(Activity::query()->find($later->id))->toBeNull();
});

it('validates the activities and their assignee', function () {
    expect(fn () => $this->actions->save($this->deal, ['type' => 'party', 'title' => '', 'due_date' => 'tomorrow'], $this->seller))->toThrow(ValidationException::class);

    useTeams();
    $team = crmTeam('Alpha', $this->seller);
    $deal = SalesCrm::for($team)->createDeal(['title' => 'Team deal'], $this->seller);

    expect(fn () => app(ManageActivities::class)->save($deal, ['type' => 'call', 'title' => 'X', 'due_date' => '2030-01-01', 'assigned_to' => crmUser()->id], $this->seller))->toThrow(ValidationException::class);
});

it('logs a call already made as a done activity with its outcome', function () {
    Event::fake([ActivityCompleted::class]);
    $person = $this->deal->people()->first();

    $activity = $this->actions->logCall($this->deal, ['person_id' => $person->id, 'outcome' => 'Left a voicemail'], $this->seller);

    expect($activity->isDone())->toBeTrue()
        ->and($activity->title)->toBe('Call with Jane')
        ->and($activity->person_id)->toBe($person->id)
        ->and($this->deal->fresh()->last_contacted_at)->not->toBeNull();
    Event::assertDispatched(ActivityCompleted::class);

    expect(fn () => $this->actions->logCall($this->deal, ['outcome' => ''], $this->seller))->toThrow(ValidationException::class);
});

it('reads the state of an activity, whole-day ones late only after their day', function () {
    $now = CarbonImmutable::parse('2026-10-15 12:00');
    $timed = new Activity(['due_at' => '2026-10-15 10:00', 'has_time' => true]);
    $allDay = new Activity(['due_at' => '2026-10-15 09:00', 'has_time' => false]);
    $yesterday = new Activity(['due_at' => '2026-10-14 09:00', 'has_time' => false]);

    expect($timed->state($now))->toBe('overdue')
        ->and($allDay->state($now))->toBe('today')
        ->and($yesterday->state($now))->toBe('overdue')
        ->and((new Activity(['due_at' => '2026-10-20 09:00']))->state($now))->toBe('planned');
});

it('sends each reminder once, to an assignee who still works the CRM', function () {
    Notification::fake();
    $soon = $this->actions->save($this->deal, ['type' => 'call', 'title' => 'Call', 'due_date' => CarbonImmutable::now()->toDateString(), 'due_time' => CarbonImmutable::now()->addMinutes(10)->format('H:i'), 'remind_minutes' => 15], $this->seller);
    $later = $this->actions->save($this->deal, ['type' => 'call', 'title' => 'Later', 'due_date' => CarbonImmutable::now()->addDays(3)->toDateString(), 'remind_minutes' => 15], $this->seller);

    $this->artisan('sales-crm:remind-activities')->assertSuccessful();
    $this->artisan('sales-crm:remind-activities')->assertSuccessful();

    Notification::assertSentToTimes($this->seller, ActivityDueNotification::class, 1);
    expect($soon->fresh()->reminded_at)->not->toBeNull()->and($later->fresh()->reminded_at)->toBeNull();
});

it('skips stale reminders and can rely on the events only', function () {
    Event::fake([ActivityDue::class]);
    $old = $this->actions->save($this->deal, ['type' => 'call', 'title' => 'Old', 'due_date' => CarbonImmutable::now()->subDay()->toDateString(), 'due_time' => '09:00', 'remind_minutes' => 0], $this->seller);

    $this->artisan('sales-crm:remind-activities')->assertSuccessful();

    Event::assertNotDispatched(ActivityDue::class);
    expect($old->fresh()->reminded_at)->not->toBeNull();
});

it('does not notify when the channels are off', function () {
    Notification::fake();
    config(['sales-crm.notifications.channels' => []]);
    $this->actions->save($this->deal, ['type' => 'call', 'title' => 'Call', 'due_date' => CarbonImmutable::now()->toDateString(), 'due_time' => CarbonImmutable::now()->addMinutes(5)->format('H:i'), 'remind_minutes' => 15], $this->seller);

    $this->artisan('sales-crm:remind-activities')->assertSuccessful();

    Notification::assertNothingSent();
});

it('plans the activities of companies and people too', function () {
    $company = $this->deal->company ?? SalesCrm::shared()->company(['name' => 'Acme']);
    $person = $this->deal->people()->first();

    $onCompany = $this->actions->save($company, ['type' => 'task', 'title' => 'Check the VAT', 'due_date' => '2030-01-01'], $this->seller);
    $onPerson = $this->actions->save($person, ['type' => 'email', 'title' => 'Send the brochure', 'due_date' => '2030-01-01'], $this->seller);

    expect($onCompany->company_id)->toBe($company->id)->and($onPerson->person_id)->toBe($person->id);
});

it('sends the reminders longer than two days when they are due', function () {
    Notification::fake();
    config(['sales-crm.activities.reminder_options' => [0, 15, 60, 1440, 10080]]);
    $due = CarbonImmutable::now()->addDays(7)->subMinutes(5);
    $week = $this->actions->save($this->deal, ['type' => 'meeting', 'title' => 'Kick-off', 'due_date' => $due->toDateString(), 'due_time' => $due->format('H:i'), 'remind_minutes' => 10080], $this->seller);

    $this->artisan('sales-crm:remind-activities')->assertSuccessful();

    Notification::assertSentToTimes($this->seller, ActivityDueNotification::class, 1);
    expect($week->fresh()->reminded_at)->not->toBeNull();
});

it('never reminds an assignee of an activity whose deal they no longer see', function () {
    Notification::fake();
    config(['sales-crm.authorization.deal_visibility' => 'owner']);
    app()->forgetScopedInstances();
    $colleague = crmUser();
    $this->actions->save($this->deal, ['type' => 'call', 'title' => 'Call', 'due_date' => CarbonImmutable::now()->toDateString(), 'due_time' => CarbonImmutable::now()->addMinutes(10)->format('H:i'), 'remind_minutes' => 15], $this->seller);
    $this->deal->forceFill(['owner_id' => $colleague->id])->save();

    $this->artisan('sales-crm:remind-activities')->assertSuccessful();

    Notification::assertNotSentTo($this->seller, ActivityDueNotification::class);
});

it('keeps the assignee of an activity changed by someone else, unless asked to clear it', function () {
    $colleague = crmUser();
    $activity = $this->actions->save($this->deal, ['type' => 'call', 'title' => 'Call', 'due_date' => CarbonImmutable::now()->addDay()->toDateString(), 'assigned_to' => $colleague->id], $this->seller);

    $this->actions->save($this->deal, ['type' => 'call', 'title' => 'Call back', 'due_date' => CarbonImmutable::now()->addDay()->toDateString()], $this->seller, $activity);
    expect($activity->fresh()->assigned_to)->toBe($colleague->id)->and($activity->fresh()->title)->toBe('Call back');

    $this->actions->save($this->deal, ['type' => 'call', 'title' => 'Call back', 'due_date' => CarbonImmutable::now()->addDay()->toDateString(), 'assigned_to' => ''], $this->seller, $activity);
    expect($activity->fresh()->assigned_to)->toBeNull();

    $unassigned = $this->actions->save($this->deal, ['type' => 'task', 'title' => 'Anyone', 'due_date' => CarbonImmutable::now()->addDay()->toDateString(), 'assigned_to' => ''], $this->seller);
    $mine = $this->actions->save($this->deal, ['type' => 'task', 'title' => 'Mine', 'due_date' => CarbonImmutable::now()->addDay()->toDateString()], $this->seller);
    expect($unassigned->assigned_to)->toBeNull()->and($mine->assigned_to)->toBe($this->seller->id);
});

it('completes an activity once, keeping the first outcome', function () {
    Event::fake([ActivityCompleted::class]);
    $activity = $this->actions->save($this->deal, ['type' => 'call', 'title' => 'Call', 'due_date' => CarbonImmutable::now()->toDateString()], $this->seller);
    $stale = $activity->fresh();

    $this->actions->complete($activity, 'Interested, call back in March', $this->seller);
    $again = $this->actions->complete($stale, null, $this->seller);

    expect($again->outcome)->toBe('Interested, call back in March')
        ->and($activity->fresh()->outcome)->toBe('Interested, call back in March');
    Event::assertDispatchedTimes(ActivityCompleted::class, 1);
});
