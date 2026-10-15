<?php

use Sellinnate\SalesCrm\Facades\SalesCrm;
use Sellinnate\SalesCrm\Workspaces\Workspace;

it('exports the deals the user sees with their filters, neutralising formulas', function () {
    useTeams();
    $alice = crmUser();
    $bob = crmUser();
    $alpha = crmTeam('Alpha', $alice);
    $beta = crmTeam('Beta', $bob);
    SalesCrm::for($alpha)->createDeal(['title' => '=HYPERLINK("evil")', 'amount_once' => 1200, 'company_name' => 'Acme'], $alice);
    SalesCrm::for($alpha)->createDeal(['title' => 'Quiet deal', 'priority' => 'p4'], $alice);
    SalesCrm::for($beta)->createDeal(['title' => 'Beta secret'], $bob);

    $content = $this->actingAs($alice)->get(route('sales-crm.deals.export', ['format' => 'csv']))->assertOk()->streamedContent();

    expect($content)->toContain("'=HYPERLINK")->toContain('Quiet deal')->toContain('Acme')->not->toContain('Beta secret')
        ->and(substr_count($content, "\n"))->toBe(3);

    $filtered = $this->actingAs($alice)->get(route('sales-crm.deals.export', ['priority' => 'p4']))->streamedContent();
    expect($filtered)->toContain('Quiet deal')->not->toContain('HYPERLINK');

    $this->actingAs($alice)->get(route('sales-crm.deals.export', ['workspace' => Workspace::of($beta)?->key()]))->assertForbidden();
});

it('exports XLSX when openspout is installed', function () {
    $user = crmUser();
    SalesCrm::shared()->createDeal(['title' => 'Spreadsheet'], $user);

    $response = $this->actingAs($user)->get(route('sales-crm.deals.export', ['format' => 'xlsx']));

    $response->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    expect(substr($response->streamedContent(), 0, 2))->toBe('PK');
});
