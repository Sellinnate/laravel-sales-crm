<?php

use Illuminate\Support\Facades\Route;
use Sellinnate\SalesCrm\Facades\SalesCrm;
use Sellinnate\SalesCrm\Models\Deal;

function enableApi(): void
{
    config(['sales-crm.api.enabled' => true, 'sales-crm.api.middleware' => ['api', 'auth']]);
    Route::group([], __DIR__.'/../../routes/api.php');
    app('router')->getRoutes()->refreshNameLookups();
}

beforeEach(function () {
    $this->seller = crmUser();
    $this->pipeline = crmPipeline();
});

it('is off by default', function () {
    expect(Route::has('sales-crm.api.deals.index'))->toBeFalse();
});

it('serves pipelines and deals as JSON', function () {
    enableApi();
    $this->getJson('/api/crm/deals')->assertUnauthorized();
    $deal = SalesCrm::shared()->createDeal(['title' => 'API deal', 'amount_once' => 1200, 'company_name' => 'Acme'], $this->seller);

    $this->actingAs($this->seller)->getJson('/api/crm/pipelines')->assertOk()->assertJsonPath('data.0.stages.0.name', 'New lead');
    $this->actingAs($this->seller)->getJson('/api/crm/deals?search=acme')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.amount_once', 1200);
    $this->actingAs($this->seller)->getJson('/api/crm/deals/'.$deal->id)->assertOk()->assertJsonPath('data.company.name', 'Acme');
});

it('creates, updates, moves and deletes deals through the actions', function () {
    enableApi();
    $created = $this->actingAs($this->seller)->postJson('/api/crm/deals', ['title' => 'Created via API', 'amount_once' => 300])->assertCreated()->json('data');
    $id = $created['id'];

    $this->actingAs($this->seller)->patchJson('/api/crm/deals/'.$id, ['next_step' => 'Call'])->assertOk()->assertJsonPath('data.next_step', 'Call');

    $proposal = crmStage($this->pipeline, 'Proposal sent');
    $this->actingAs($this->seller)->postJson('/api/crm/deals/'.$id.'/move', ['stage_id' => $proposal->id])->assertUnprocessable()->assertJsonValidationErrors(['requirements.offer_valid_until']);
    $this->actingAs($this->seller)->postJson('/api/crm/deals/'.$id.'/move', ['stage_id' => $proposal->id, 'fill' => ['offer_valid_until' => '2030-01-01']])->assertOk()->assertJsonPath('data.stage.id', $proposal->id);

    $this->actingAs($this->seller)->postJson('/api/crm/deals/'.$id.'/notes', ['body' => 'From the API'])->assertCreated();
    $this->actingAs($this->seller)->postJson('/api/crm/deals/'.$id.'/activities', ['type' => 'call', 'title' => 'Call', 'due_date' => '2030-01-01'])->assertCreated();

    $this->actingAs($this->seller)->deleteJson('/api/crm/deals/'.$id)->assertForbidden();
    $this->actingAs(crmUser(['is_admin' => true]))->deleteJson('/api/crm/deals/'.$id)->assertNoContent();
    expect(Deal::query()->find($id))->toBeNull();
});
