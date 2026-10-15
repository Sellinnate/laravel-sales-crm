<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Sellinnate\SalesCrm\Actions\ManageNotes;
use Sellinnate\SalesCrm\Events\UserMentioned;
use Sellinnate\SalesCrm\Facades\SalesCrm;
use Sellinnate\SalesCrm\Models\Note;
use Sellinnate\SalesCrm\Notifications\MentionedNotification;

beforeEach(function () {
    $this->author = crmUser(['name' => 'Ann Author']);
    $this->jane = crmUser(['name' => 'Jane Doe']);
    $this->janeLong = crmUser(['name' => 'Jane Doering']);
    $this->admin = crmUser(['name' => 'Alex Admin', 'is_admin' => true]);
    $this->deal = SalesCrm::shared()->createDeal(['title' => 'Website'], $this->author);
    $this->notes = app(ManageNotes::class);
});

it('adds notes and notifies the mentioned colleagues, the longest name first', function () {
    Notification::fake();

    $note = $this->notes->add($this->deal, 'Ask @Jane Doering and @Ann Author about it', $this->author);

    expect($note->mentions)->toBe([$this->janeLong->id])
        ->and($this->deal->fresh()->last_activity_at)->not->toBeNull();
    Notification::assertSentTo($this->janeLong, MentionedNotification::class);
    Notification::assertNotSentTo($this->jane, MentionedNotification::class);
    Notification::assertNotSentTo($this->author, MentionedNotification::class);
});

it('notifies on edit only the newly mentioned users', function () {
    Event::fake([UserMentioned::class]);
    $note = $this->notes->add($this->deal, 'Hi @Jane Doe', $this->author);
    $this->notes->update($note, 'Hi @Jane Doe and @Alex Admin', $this->author);

    Event::assertDispatchedTimes(UserMentioned::class, 2);
    Event::assertDispatched(UserMentioned::class, fn (UserMentioned $event): bool => $event->user->is($this->admin));
});

it('lets the author change a note, the author or a manager delete it', function () {
    $note = $this->notes->add($this->deal, 'Private thought', $this->author);

    expect(fn () => $this->notes->update($note, 'Hijack', $this->jane))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->notes->delete($note, $this->jane))->toThrow(AuthorizationException::class);

    $this->notes->pin($note, true, $this->jane);
    expect($note->fresh()->pinned_at)->not->toBeNull();

    $this->notes->delete($note, $this->admin);
    expect(Note::query()->find($note->id))->toBeNull();
});

it('refuses empty and too long notes', function () {
    expect(fn () => $this->notes->add($this->deal, '   ', $this->author))->toThrow(ValidationException::class)
        ->and(fn () => $this->notes->add($this->deal, str_repeat('x', 5001), $this->author))->toThrow(ValidationException::class);
});

it('writes notes on companies and people', function () {
    $company = SalesCrm::shared()->company(['name' => 'Acme']);
    $person = SalesCrm::shared()->person(['first_name' => 'Ada'], $company);

    expect($this->notes->add($company, 'About the company', $this->author)->company_id)->toBe($company->id)
        ->and($this->notes->add($person, 'About Ada', $this->author)->person_id)->toBe($person->id);
});
