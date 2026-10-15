<?php

use Sellinnate\SalesCrm\Facades\SalesCrm;
use Sellinnate\SalesCrm\Models\Deal;
use Workbench\App\Models\Team;

it('works the CRM from PHP without any UI', function () {
    useTeams();
    $owner = crmUser();
    $team = crmTeam('Alpha', $owner);
    $crm = SalesCrm::for($team);

    $pipeline = $crm->defaultPipeline();
    $company = $crm->company(['name' => 'Acme', 'website' => 'acme.example']);
    $person = $crm->person(['first_name' => 'Jane', 'email' => 'jane@acme.example'], $company);
    $deal = $crm->createDeal(['title' => 'Website', 'company_id' => $company->id, 'person' => ['person_id' => $person->id]], $owner);
    $system = $crm->createDeal(['title' => 'From a webhook']);

    SalesCrm::moveDeal($deal, crmStage($pipeline, 'Qualified')->id, $owner);
    SalesCrm::addNote($deal, 'First call went well', $owner);

    expect($crm->deals()->count())->toBe(2)
        ->and($crm->deals()->open()->pluck('title')->sort()->values()->all())->toBe(['From a webhook', 'Website'])
        ->and($crm->companies()->count())->toBe(1)
        ->and($crm->people()->first()->is($person))->toBeTrue()
        ->and($crm->pipelines()->count())->toBe(1)
        ->and($system->owner_id)->toBeNull()
        ->and($deal->fresh()->stage->name)->toBe('Qualified')
        ->and($crm->settings()->exists)->toBeFalse()
        ->and(SalesCrm::access()->works($owner, $team))->toBeTrue()
        ->and(Deal::query()->visibleTo($owner)->count())->toBe(2);
});

it('runs the quick start of the README', function () {
    $user = crmUser(['name' => 'Sam Seller']);
    $order = Team::query()->create(['name' => 'Order 42']);
    crmUser(['name' => 'Laura Bianchi']);

    $crm = SalesCrm::shared();

    $deal = $crm->createDeal([
        'title' => 'Website redesign',
        'company_name' => 'Northwind Traders',
        'amount_once' => 12000,
        'person' => ['first_name' => 'Laura', 'email' => 'laura@northwind.example'],
    ], $user);

    $fromOrder = $crm->createDealFrom($order, ['title' => 'Order #'.$order->id], $user);
    $again = $crm->createDealFrom($order, ['title' => 'Order #'.$order->id], $user);

    $proposal = $deal->pipeline->stages()->where('name', 'Proposal sent')->first();
    SalesCrm::moveDeal($deal, $proposal, $user, fill: ['offer_valid_until' => '2026-12-31']);

    SalesCrm::scheduleActivity($deal, ['type' => 'call', 'title' => 'Follow up', 'due_date' => now()->addDays(2)->toDateString()], $user);
    $note = SalesCrm::addNote($deal, 'Budget confirmed by @Laura Bianchi', $user);

    expect($crm->deals()->open()->with('stage')->get())->toHaveCount(2)
        ->and($again->is($fromOrder))->toBeTrue()
        ->and($deal->fresh()->stage->name)->toBe('Proposal sent')
        ->and($deal->company->name)->toBe('Northwind Traders')
        ->and($deal->people()->first()?->email)->toBe('laura@northwind.example')
        ->and($deal->activities()->where('title', 'Follow up')->exists())->toBeTrue()
        ->and($note->body)->toContain('@Laura Bianchi');
});
