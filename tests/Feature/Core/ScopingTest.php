<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Sellinnate\SalesCrm\Actions\MoveDeal;
use Sellinnate\SalesCrm\Actions\UpdateDeal;
use Sellinnate\SalesCrm\Authorization\Access;
use Sellinnate\SalesCrm\Facades\SalesCrm;
use Sellinnate\SalesCrm\Models\Activity;
use Sellinnate\SalesCrm\Models\Company;
use Sellinnate\SalesCrm\Models\Deal;
use Sellinnate\SalesCrm\Models\Person;
use Sellinnate\SalesCrm\Models\Pipeline;

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
