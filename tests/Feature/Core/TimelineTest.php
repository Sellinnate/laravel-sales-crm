<?php

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Mail;
use Sellinnate\SalesCrm\Contracts\TimelineProvider;
use Sellinnate\SalesCrm\Data\TimelineEntry;
use Sellinnate\SalesCrm\Facades\SalesCrm;
use Sellinnate\SalesCrm\Models\Company;
use Sellinnate\SalesCrm\Models\Deal;
use Sellinnate\SalesCrm\Models\Person;

class FakeCallsProvider implements TimelineProvider
{
    public function kind(): string
    {
        return 'calls';
    }

    public function label(): string
    {
        return 'Phone calls';
    }

    public function entries(Deal|Company|Person $subject, ?Authenticatable $viewer, int $limit): iterable
    {
        yield new TimelineEntry('call-1', 'calls', CarbonImmutable::now()->subYear(), 'phone', 'Inbound call (telephony)', 'Asked for prices');
    }

    public function count(Deal|Company|Person $subject, ?Authenticatable $viewer): int
    {
        return 42;
    }
}

it('mixes every kind of entry, pinned notes first, with counters per kind', function () {
    Mail::fake();
    config(['sales-crm.timeline.providers' => [FakeCallsProvider::class]]);
    $user = crmUser();
    $deal = SalesCrm::shared()->createDeal(['title' => 'Website', 'person' => ['first_name' => 'Jane', 'email' => 'jane@acme.example']], $user);
    $pinned = SalesCrm::addNote($deal, 'Pinned note', $user, pinned: true);
    SalesCrm::addNote($deal, 'Recent note', $user);
    $activity = SalesCrm::scheduleActivity($deal, ['type' => 'call', 'title' => 'Discovery call', 'due_date' => CarbonImmutable::today()->toDateString()], $user);
    SalesCrm::completeActivity($activity, 'Interested', $user);
    SalesCrm::scheduleActivity($deal, ['type' => 'task', 'title' => 'Still open', 'due_date' => CarbonImmutable::tomorrow()->toDateString()], $user);
    SalesCrm::sendEmail($deal, ['to' => 'jane@acme.example', 'subject' => 'Hello', 'body' => 'Hi'], $user);
    SalesCrm::updateDeal($deal, ['amount_once' => 500], $user);

    $timeline = SalesCrm::timeline();
    $entries = $timeline->for($deal, $user);
    $counts = $timeline->counts($deal, $user);

    expect($entries[0]->key)->toBe('note-'.$pinned->id)
        ->and(collect($entries)->pluck('kind')->unique()->sort()->values()->all())->toBe(['activity', 'calls', 'email', 'event', 'note'])
        ->and(collect($entries)->pluck('title')->all())->not->toContain('Still open')
        ->and($counts)->toMatchArray(['note' => 2, 'activity' => 1, 'email' => 1, 'document' => 0, 'calls' => 42])
        ->and($counts['event'])->toBeGreaterThanOrEqual(2)
        ->and($timeline->kinds($deal))->toHaveKey('calls', 'Phone calls')
        ->and(collect($timeline->for($deal, $user, 'email'))->pluck('kind')->unique()->all())->toBe(['email']);
});

it('builds the timeline of companies and people', function () {
    $user = crmUser();
    $deal = SalesCrm::shared()->createDeal(['title' => 'X', 'company_name' => 'Acme', 'person' => ['first_name' => 'Ann']], $user);
    SalesCrm::addNote($deal->company, 'About the company', $user);
    SalesCrm::addNote($deal->people->first(), 'About Ann', $user);

    expect(collect(SalesCrm::timeline()->for($deal->company, $user))->pluck('body')->all())->toBe(['About the company'])
        ->and(SalesCrm::timeline()->kinds($deal->people->first()))->not->toHaveKey('document')
        ->and(SalesCrm::timeline()->counts($deal->people->first(), $user)['note'])->toBe(1);
});
