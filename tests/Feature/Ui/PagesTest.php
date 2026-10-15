<?php

use Sellinnate\SalesCrm\Facades\SalesCrm;

it('renders every page of the UI', function (string $route) {
    $user = crmUser(['is_admin' => true]);
    $deal = SalesCrm::shared()->createDeal(['title' => 'Website redesign', 'company_name' => 'Acme', 'person' => ['first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@acme.test']], $user);

    $parameters = match ($route) {
        'sales-crm.deals.show' => ['deal' => $deal->id],
        'sales-crm.companies.show' => ['company' => $deal->company_id],
        'sales-crm.people.show' => ['person' => $deal->people()->value('crm_people.id')],
        default => [],
    };

    $this->actingAs($user)->get(route($route, $parameters))->assertOk()->assertSee('sales-crm', false);
})->with(['sales-crm.deals', 'sales-crm.deals.show', 'sales-crm.agenda', 'sales-crm.companies', 'sales-crm.companies.show', 'sales-crm.people', 'sales-crm.people.show', 'sales-crm.reports', 'sales-crm.import', 'sales-crm.settings']);
