<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Sellinnate\SalesCrm\Actions\SaveEmailTemplate;
use Sellinnate\SalesCrm\Facades\SalesCrm;
use Sellinnate\SalesCrm\Livewire\DealPanel;
use Sellinnate\SalesCrm\Models\Activity;
use Sellinnate\SalesCrm\Models\Document;
use Sellinnate\SalesCrm\Models\Email;
use Sellinnate\SalesCrm\Models\EmailTemplate;
use Sellinnate\SalesCrm\Models\Note;

beforeEach(function () {
    $this->seller = crmUser(['name' => 'Sam Seller']);
    $this->pipeline = crmPipeline();
    $this->deal = SalesCrm::shared()->createDeal(['title' => 'Panel deal', 'company_name' => 'Acme', 'person' => ['first_name' => 'Jane', 'email' => 'jane@acme.example']], $this->seller);
    $this->actingAs($this->seller);
});

it('shows the deal and saves only the changed fields', function () {
    Livewire::test(DealPanel::class, ['dealId' => $this->deal->id])
        ->assertSee(['Panel deal', 'Acme', 'Jane'])
        ->set('form.amount_once', '3200')
        ->set('form.next_step', 'Send the contract')
        ->set('form.tags', 'vip, renewal')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('crm-deal-updated');

    $deal = $this->deal->fresh();
    expect($deal->amount_once_cents)->toBe(320000)->and($deal->next_step)->toBe('Send the contract')->and($deal->tags)->toBe(['vip', 'renewal']);
});

it('shows the errors of the rules next to the fields', function () {
    Livewire::test(DealPanel::class, ['dealId' => $this->deal->id])
        ->set('form.signature_status', 'signed')
        ->call('save')
        ->assertHasErrors(['form.signature_evidence']);
});

it('moves the deal from its stage path, asking the required fields', function () {
    Livewire::test(DealPanel::class, ['dealId' => $this->deal->id])
        ->call('moveTo', crmStage($this->pipeline, 'Proposal sent')->id)
        ->assertSet('showRequirements', true)
        ->set('fill.amount_once', '500')
        ->set('fill.offer_valid_until', '2030-06-30')
        ->call('completeMove')
        ->assertSet('showRequirements', false);

    expect($this->deal->fresh()->stage->name)->toBe('Proposal sent');
});

it('adds notes, activities, calls and people from the quick actions', function () {
    $component = Livewire::test(DealPanel::class, ['dealId' => $this->deal->id]);

    $component->call('open', 'note')->set('noteBody', 'First note')->call('addNote')->assertHasNoErrors();
    $component->call('open', 'activity')->set('activity.title', 'Demo')->set('activity.due_date', '2030-01-01')->call('saveActivity')->assertHasNoErrors();
    $activity = Activity::query()->where('title', 'Demo')->firstOrFail();
    $component->set("outcomes.{$activity->id}", 'Went well')->call('completeActivity', $activity->id);
    $component->call('open', 'call')->set('call.outcome', 'No answer')->call('logCall')->assertHasNoErrors();
    $component->call('open', 'person')->set('person.first_name', 'Bob')->set('person.email', 'bob@acme.example')->call('addPerson')->assertHasNoErrors();

    expect(Note::query()->where('deal_id', $this->deal->id)->count())->toBe(1)
        ->and($activity->fresh()->outcome)->toBe('Went well')
        ->and(Activity::query()->where('deal_id', $this->deal->id)->whereNotNull('done_at')->count())->toBe(2)
        ->and($this->deal->people()->count())->toBe(2);

    $component->assertSee(['First note', 'Went well', 'No answer']);
});

it('sends an email with a template and uploads a document', function () {
    Mail::fake();
    Storage::fake('local');
    $admin = crmUser(['is_admin' => true]);
    app(SaveEmailTemplate::class)->handle(null, ['name' => 'Hello', 'subject' => 'About {{deal}}', 'body' => 'Hi {{first_name}}'], $admin);
    $template = EmailTemplate::query()->firstOrFail();

    Livewire::test(DealPanel::class, ['dealId' => $this->deal->id])
        ->call('open', 'email')
        ->assertSet('email.to', 'jane@acme.example')
        ->set('email.template', (string) $template->id)
        ->call('applyTemplate')
        ->assertSet('email.subject', 'About {{deal}}')
        ->call('sendEmail')
        ->assertHasNoErrors();

    expect(Email::query()->value('subject'))->toBe('About Panel deal');

    Livewire::test(DealPanel::class, ['dealId' => $this->deal->id])
        ->call('open', 'document')
        ->set('document.kind', 'quote')
        ->set('document.valid_until', '2030-03-31')
        ->set('upload', UploadedFile::fake()->create('quote.pdf', 20, 'application/pdf'))
        ->call('uploadDocument')
        ->assertHasNoErrors();

    expect(Document::query()->count())->toBe(1)->and($this->deal->fresh()->offer_valid_until->toDateString())->toBe('2030-03-31');
});

it('shows the timeline with a counter per kind', function () {
    SalesCrm::addNote($this->deal, 'A note', $this->seller);
    SalesCrm::addNote($this->deal, 'Another note', $this->seller);

    Livewire::test(DealPanel::class, ['dealId' => $this->deal->id])
        ->assertSeeHtml('data-test="timeline-count-note">2<')
        ->set('kind', 'note')
        ->assertSee('Another note');
});

it('refuses the panel of a deal the user does not see', function () {
    useTeams();
    $alpha = crmTeam('Alpha', crmUser());
    $hidden = SalesCrm::for($alpha)->createDeal(['title' => 'Hidden']);

    Livewire::test(DealPanel::class, ['dealId' => $hidden->id])->assertForbidden();
});

it('deletes a deal only for managers', function () {
    Livewire::test(DealPanel::class, ['dealId' => $this->deal->id])->call('deleteDeal')->assertForbidden();
    expect($this->deal->fresh())->not->toBeNull();

    $this->actingAs(crmUser(['is_admin' => true]));
    Livewire::test(DealPanel::class, ['dealId' => $this->deal->id])->call('deleteDeal')->assertDispatched('crm-close-panel');
    expect($this->deal->fresh())->toBeNull();
});
