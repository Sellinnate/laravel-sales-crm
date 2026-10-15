<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Sellinnate\SalesCrm\Actions\ManageActivities;
use Sellinnate\SalesCrm\Actions\MoveDeal;
use Sellinnate\SalesCrm\Actions\UpdateDeal;
use Sellinnate\SalesCrm\Authorization\Access;
use Sellinnate\SalesCrm\Data\InboundEmail;
use Sellinnate\SalesCrm\Enums\ImportStatus;
use Sellinnate\SalesCrm\Facades\SalesCrm;
use Sellinnate\SalesCrm\Livewire\Agenda;
use Sellinnate\SalesCrm\Livewire\Companies;
use Sellinnate\SalesCrm\Livewire\CompanyPage;
use Sellinnate\SalesCrm\Livewire\DealPanel;
use Sellinnate\SalesCrm\Livewire\Import as ImportPage;
use Sellinnate\SalesCrm\Livewire\People;
use Sellinnate\SalesCrm\Models\Activity;
use Sellinnate\SalesCrm\Models\Company;
use Sellinnate\SalesCrm\Models\Deal;
use Sellinnate\SalesCrm\Models\Email;
use Sellinnate\SalesCrm\Models\Import;
use Sellinnate\SalesCrm\Models\Note;
use Sellinnate\SalesCrm\Models\Person;
use Sellinnate\SalesCrm\Models\Pipeline;
use Sellinnate\SalesCrm\Services\DealFields;
use Sellinnate\SalesCrm\Workspaces\Workspace;

beforeEach(function () {
    useTeams();
    $this->alice = crmUser(['name' => 'Alice']);
    $this->bob = crmUser(['name' => 'Bob']);
    $this->both = crmUser(['name' => 'Both']);
    $this->alpha = crmTeam('Alpha', $this->alice, $this->both);
    $this->beta = crmTeam('Beta', $this->bob, $this->both);
    $this->alphaDeal = SalesCrm::for($this->alpha)->createDeal(['title' => 'Alpha deal', 'company_name' => 'Acme', 'person' => ['first_name' => 'Ann', 'email' => 'ann@acme.example']], $this->alice);
    $this->betaDeal = SalesCrm::for($this->beta)->createDeal(['title' => 'Beta deal', 'company_name' => 'Acme', 'person' => ['first_name' => 'Ann', 'email' => 'ann@acme.example']], $this->bob);
});

it('keeps every record in its workspace, the same company once per workspace', function () {
    expect(Company::query()->count())->toBe(2)
        ->and(Person::query()->count())->toBe(2)
        ->and(Pipeline::query()->count())->toBe(2)
        ->and($this->alphaDeal->company->inWorkspace($this->alpha))->toBeTrue()
        ->and($this->betaDeal->pipeline->inWorkspace($this->beta))->toBeTrue();
});

it('shows each user only the records of their workspaces', function () {
    expect(Deal::query()->visibleTo($this->alice)->pluck('title')->all())->toBe(['Alpha deal'])
        ->and(Deal::query()->visibleTo($this->bob)->pluck('title')->all())->toBe(['Beta deal'])
        ->and(Deal::query()->visibleTo($this->both)->count())->toBe(2)
        ->and(Company::query()->visibleTo($this->alice)->count())->toBe(1)
        ->and(Pipeline::query()->visibleTo(crmUser())->count())->toBe(0)
        ->and(Deal::query()->visibleTo(null)->count())->toBe(0);
});

it('refuses to read or change the records of another workspace', function () {
    expect(Gate::forUser($this->alice)->allows('view', $this->betaDeal))->toBeFalse()
        ->and(Gate::forUser($this->both)->allows('view', $this->betaDeal))->toBeTrue()
        ->and(fn () => app(UpdateDeal::class)->handle($this->betaDeal, ['title' => 'Mine'], $this->alice))->toThrow(AuthorizationException::class)
        ->and(fn () => SalesCrm::for($this->beta)->createDeal(['title' => 'Intruder'], $this->alice))->toThrow(AuthorizationException::class)
        ->and(fn () => app(MoveDeal::class)->handle($this->betaDeal, $this->betaDeal->pipeline->stages->last(), $this->alice))->toThrow(AuthorizationException::class);
});

it('refuses a pipeline, a company or a person of another workspace', function () {
    $betaPipeline = $this->betaDeal->pipeline;

    expect(fn () => SalesCrm::for($this->alpha)->createDeal(['title' => 'X', 'pipeline_id' => $betaPipeline->id], $this->both))->toThrow(ValidationException::class)
        ->and(fn () => app(UpdateDeal::class)->handle($this->alphaDeal, ['company_id' => $this->betaDeal->company_id], $this->both))->toThrow(ValidationException::class)
        ->and(fn () => SalesCrm::addPerson($this->alphaDeal, ['person_id' => $this->betaDeal->people->first()->id], $this->both))->toThrow(ValidationException::class);
});

it('lists the members of a workspace for owners, assignees and mentions', function () {
    $access = app(Access::class);

    expect($access->members($this->alpha)->pluck('name')->sort()->values()->all())->toBe(['Alice', 'Both'])
        ->and($access->isMember($this->bob, $this->alpha))->toBeFalse()
        ->and($access->workspacesFor($this->both))->toHaveCount(2)
        ->and($access->label($this->beta))->toBe('Beta');
});

it('shows only owned and unassigned deals with owner visibility, all of them to managers', function () {
    config(['sales-crm.authorization.deal_visibility' => 'owner']);
    app()->forgetScopedInstances();
    $unassigned = SalesCrm::for($this->alpha)->createDeal(['title' => 'Nobody', 'owner_id' => null], $this->alice);
    $mine = SalesCrm::for($this->alpha)->createDeal(['title' => 'Both own', 'owner_id' => $this->both->id], $this->both);

    expect(Deal::query()->visibleTo($this->alice)->pluck('title')->sort()->values()->all())->toBe(['Alpha deal', 'Nobody'])
        ->and(Gate::forUser($this->alice)->allows('view', $mine))->toBeFalse()
        ->and(Gate::forUser($this->alice)->allows('view', $unassigned))->toBeTrue();

    $this->both->forceFill(['is_admin' => true])->save();
    expect(Deal::query()->visibleTo($this->both->fresh())->count())->toBe(4);
});

it('closes the CRM to users the access gate refuses', function () {
    Gate::define('sales-crm.access', fn ($user): bool => $user->name !== 'Bob');

    expect(Deal::query()->visibleTo($this->bob)->count())->toBe(0)
        ->and(app(Access::class)->canAccess($this->bob))->toBeFalse();

    $this->actingAs($this->bob)->get(route('sales-crm.deals'))->assertForbidden();
    $this->actingAs($this->alice)->get(route('sales-crm.deals'))->assertOk()->assertSee('Alpha deal')->assertDontSee('Beta deal');
});

it('scopes the activities of the agenda and the reminders', function () {
    SalesCrm::scheduleActivity($this->betaDeal, ['type' => 'call', 'title' => 'Beta call', 'due_date' => now()->toDateString()], $this->bob);

    expect(Activity::query()->visibleTo($this->alice)->count())->toBe(0)
        ->and(Activity::query()->visibleTo($this->both)->count())->toBe(1);
});

it('hides the activities and notes of the deals a user does not see, in the agenda too', function () {
    config(['sales-crm.authorization.deal_visibility' => 'owner']);
    app()->forgetScopedInstances();
    $hidden = SalesCrm::for($this->alpha)->createDeal(['title' => 'Both own', 'owner_id' => $this->both->id], $this->both);
    SalesCrm::scheduleActivity($hidden, ['type' => 'call', 'title' => 'Secret call', 'due_date' => now()->toDateString(), 'assigned_to' => $this->both->id], $this->both);
    SalesCrm::addNote($hidden, 'Secret note', $this->both);
    SalesCrm::scheduleActivity($this->alphaDeal, ['type' => 'call', 'title' => 'Open call', 'due_date' => now()->toDateString()], $this->alice);

    expect(Activity::query()->visibleTo($this->alice)->pluck('title')->all())->toBe(['Open call'])
        ->and(Note::query()->visibleTo($this->alice)->where('body', 'Secret note')->exists())->toBeFalse()
        ->and(Activity::query()->visibleTo($this->both)->where('title', 'Secret call')->exists())->toBeTrue()
        ->and(Note::query()->visibleTo($this->both)->where('body', 'Secret note')->exists())->toBeTrue();

    Livewire::actingAs($this->alice)->test(Agenda::class)
        ->set('scope', 'all')
        ->assertSee('Open call')
        ->assertDontSee('Secret call')
        ->assertDontSee('Both own');
});

it('leaves the records of hidden deals out of the timeline of their company, counters included', function () {
    config(['sales-crm.authorization.deal_visibility' => 'owner']);
    app()->forgetScopedInstances();
    $company = $this->alphaDeal->company;
    $hidden = SalesCrm::for($this->alpha)->createDeal(['title' => 'Both own', 'owner_id' => $this->both->id, 'company_id' => $company->id], $this->both);
    $note = SalesCrm::addNote($hidden, 'Secret note', $this->both);
    $note->forceFill(['company_id' => $company->id])->save();
    $visible = SalesCrm::addNote($this->alphaDeal, 'Public note', $this->alice);
    $visible->forceFill(['company_id' => $company->id])->save();

    $timeline = SalesCrm::timeline();
    $titles = fn ($viewer): array => array_map(fn ($entry): ?string => $entry->body, $timeline->for($company, $viewer, 'note'));

    expect($titles($this->alice))->toBe(['Public note'])
        ->and($timeline->counts($company, $this->alice)['note'])->toBe(1)
        ->and($titles($this->both))->toContain('Secret note')
        ->and($timeline->counts($company, $this->both)['note'])->toBe(1 + (int) Gate::forUser($this->both)->allows('view', $this->alphaDeal));
});

it('shows on a company and a person only the deals the user sees, counters included', function () {
    config(['sales-crm.authorization.deal_visibility' => 'owner']);
    app()->forgetScopedInstances();
    $company = $this->alphaDeal->company;
    $person = $this->alphaDeal->people->first();
    $hidden = SalesCrm::for($this->alpha)->createDeal(['title' => 'Hidden renewal', 'owner_id' => $this->both->id, 'company_id' => $company->id, 'person' => ['person_id' => $person->id]], $this->both);

    $this->actingAs($this->alice)->get(route('sales-crm.companies.show', $company))->assertOk()->assertSee('Alpha deal')->assertDontSee('Hidden renewal');
    $this->actingAs($this->alice)->get(route('sales-crm.people.show', $person))->assertOk()->assertSee('Alpha deal')->assertDontSee('Hidden renewal');
    $this->actingAs($this->both)->get(route('sales-crm.companies.show', $company))->assertOk()->assertSee('Hidden renewal');

    $count = fn ($component, int $id): ?int => collect($component->instance()->{$component->instance() instanceof Companies ? 'companies' : 'people'}->items())->firstWhere('id', $id)?->deals_count;

    expect($count(Livewire::actingAs($this->alice)->test(Companies::class), $company->id))->toBe(1)
        ->and($count(Livewire::actingAs($this->alice)->test(People::class), $person->id))->toBe(1)
        ->and($count(Livewire::actingAs($this->both)->test(Companies::class), $company->id))->toBe(1)
        ->and($count(Livewire::actingAs($this->both)->test(People::class), $person->id))->toBe(1);

    $this->both->forceFill(['is_admin' => true])->save();
    expect($count(Livewire::actingAs($this->both->fresh())->test(Companies::class), $company->id))->toBe(2);

    expect($hidden->company_id)->toBe($company->id);
});

it('validates the records of the CRM on its own database connection', function () {
    config(['sales-crm.database.connection' => 'crm']);

    $rules = collect(app(DealFields::class)->rules(null))->flatten()->map(fn ($rule): string => is_object($rule) && method_exists($rule, '__toString') ? (string) $rule : (is_string($rule) ? $rule : ''));

    expect($rules->filter(fn (string $rule): bool => str_starts_with($rule, 'exists:') || str_starts_with($rule, 'unique:'))->values()->all())
        ->not->toBeEmpty()
        ->each->toContain(':crm.');
});

it('refuses to change an activity of a hidden deal through the person it names', function () {
    config(['sales-crm.authorization.deal_visibility' => 'owner']);
    app()->forgetScopedInstances();
    $person = $this->alphaDeal->people->first();
    $hidden = SalesCrm::for($this->alpha)->createDeal(['title' => 'Hidden renewal', 'owner_id' => $this->both->id], $this->both);
    $activity = SalesCrm::scheduleActivity($hidden, ['type' => 'call', 'title' => 'Secret call', 'due_date' => now()->toDateString(), 'person_id' => $person->id], $this->both);

    expect(fn () => app(ManageActivities::class)->save($person, ['type' => 'call', 'title' => 'Hijacked', 'due_date' => now()->toDateString()], $this->alice, $activity))
        ->toThrow(AuthorizationException::class)
        ->and($activity->fresh()->title)->toBe('Secret call');
});

it('checks the access to a record on every request of an open page, not only on the first', function () {
    $company = $this->alphaDeal->company;
    $panel = Livewire::actingAs($this->alice)->test(DealPanel::class, ['dealId' => $this->alphaDeal->id])->assertOk();
    $page = Livewire::actingAs($this->alice)->test(CompanyPage::class, ['company' => $company])->assertOk();

    $this->alpha->users()->detach($this->alice->id);
    $this->alice->unsetRelations();
    app()->forgetScopedInstances();

    // Livewire 4.1+ answers 404, earlier versions let the exception through.
    foreach ([$panel, $page] as $component) {
        try {
            $component->call('$refresh')->assertStatus(404);
        } catch (ModelNotFoundException) {
        }
    }
});

it('never shows the import of another workspace when the browser changes its id', function () {
    Storage::fake('local');
    $this->alice->forceFill(['is_admin' => true])->save();
    $this->bob->forceFill(['is_admin' => true])->save();
    Storage::disk('local')->put('sales-crm/imports/beta.csv', "Title,Company\nSecret deal,Beta Corp\n");
    $import = new Import(['pipeline_id' => $this->betaDeal->pipeline_id, 'disk' => 'local', 'stored_path' => 'sales-crm/imports/beta.csv', 'original_filename' => 'beta-secret.csv', 'status' => ImportStatus::cases()[0], 'user_id' => $this->bob->id]);
    $import->setWorkspace(Workspace::of($this->beta))->save();

    Livewire::actingAs($this->alice->fresh())->test(ImportPage::class)
        ->set('importId', $import->id)
        ->assertDontSee('beta-secret.csv')
        ->assertDontSee('Secret deal');
});

it('returns the deal already born from a record only to a user who may see it', function () {
    $origin = crmTeam('Order 7');
    $deal = SalesCrm::for($this->beta)->createDealFrom($origin, ['title' => 'Order 7']);

    expect(SalesCrm::for($this->beta)->createDealFrom($origin, ['title' => 'Again'])->is($deal))->toBeTrue()
        ->and(SalesCrm::for($this->beta)->createDealFrom($origin, ['title' => 'Again'], $this->bob)->is($deal))->toBeTrue()
        ->and(fn () => SalesCrm::for($this->beta)->createDealFrom($origin, ['title' => 'Again'], $this->alice))->toThrow(AuthorizationException::class);
});

it('never stores the email of a user in a workspace they do not work in', function () {
    $bobPerson = $this->betaDeal->people->first();
    $bobPerson->forceFill(['email' => 'only-beta@client.example'])->save();
    $alice = $this->alice->forceFill(['email' => 'alice@vendor.example']);
    $alice->save();

    $bcc = SalesCrm::inbound()->receive(InboundEmail::fromArray(['message_id' => 'bcc-x@vendor.example', 'from' => 'alice@vendor.example', 'to' => ['only-beta@client.example'], 'subject' => 'Private', 'text' => 'Private']));

    $sent = Email::query()->create(['deal_id' => $this->betaDeal->id, 'workspace_type' => $this->betaDeal->workspace_type, 'workspace_id' => $this->betaDeal->workspace_id, 'workspace_key' => Workspace::fromRecord($this->betaDeal)?->key() ?? '', 'direction' => 'out', 'status' => 'sent', 'message_id' => 'beta-thread@vendor.example', 'from_email' => 'bob@vendor.example', 'to' => ['only-beta@client.example'], 'subject' => 'Offer', 'body_text' => 'Offer']);
    $reply = SalesCrm::inbound()->receive(InboundEmail::fromArray(['message_id' => 'reply-x@vendor.example', 'from' => 'alice@vendor.example', 'to' => ['only-beta@client.example'], 'subject' => 'Re: Offer', 'text' => 'Me too', 'in_reply_to' => '<beta-thread@vendor.example>']));

    expect($bcc)->toBeNull()
        ->and($reply)->toBeNull()
        ->and(Email::query()->whereIn('message_id', ['bcc-x@vendor.example', 'reply-x@vendor.example'])->count())->toBe(0)
        ->and($sent->exists)->toBeTrue();
});

it('never creates pipelines from the import wizard where the user does not manage', function () {
    $this->both->forceFill(['is_admin' => false])->save();
    Gate::define('sales-crm.manage', fn ($user, $workspace = null): bool => $user->is($this->both) && $workspace?->is($this->alpha));
    $before = Pipeline::query()->count();

    $wizard = Livewire::actingAs($this->both)->test(ImportPage::class);
    $wizard->set('workspace', Workspace::of($this->beta)?->key());
    $wizard->set('workspace', 'nonsense|42');

    expect($wizard->instance()->pipelines)->toHaveCount(0)
        ->and(Pipeline::query()->count())->toBe($before)
        ->and(Pipeline::query()->whereNull('workspace_type')->exists())->toBeFalse();
});
