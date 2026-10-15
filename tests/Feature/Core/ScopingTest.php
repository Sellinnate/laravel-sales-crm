<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Sellinnate\SalesCrm\Actions\MoveDeal;
use Sellinnate\SalesCrm\Actions\UpdateDeal;
use Sellinnate\SalesCrm\Authorization\Access;
use Sellinnate\SalesCrm\Facades\SalesCrm;
use Sellinnate\SalesCrm\Livewire\Agenda;
use Sellinnate\SalesCrm\Livewire\Companies;
use Sellinnate\SalesCrm\Livewire\People;
use Sellinnate\SalesCrm\Models\Activity;
use Sellinnate\SalesCrm\Models\Company;
use Sellinnate\SalesCrm\Models\Deal;
use Sellinnate\SalesCrm\Models\Note;
use Sellinnate\SalesCrm\Models\Person;
use Sellinnate\SalesCrm\Models\Pipeline;
use Sellinnate\SalesCrm\Services\DealFields;

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
        ->set('scope', 'everybody')
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
