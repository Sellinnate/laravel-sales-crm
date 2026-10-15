<?php

use Sellinnate\SalesCrm\Facades\SalesCrm;
use Sellinnate\SalesCrm\Models\Deal;

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
