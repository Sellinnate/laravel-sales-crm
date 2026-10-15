<?php

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Sellinnate\SalesCrm\Facades\SalesCrm;
use Sellinnate\SalesCrm\Models\Deal;
use Sellinnate\SalesCrm\Support\Crm;

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

it('keeps every route of the API within the workspaces of the user', function () {
    enableApi();
    useTeams();
    $alice = crmUser(['is_admin' => true]);
    $bob = crmUser();
    $alpha = crmTeam('Alpha', $alice);
    $beta = crmTeam('Beta', $bob);
    $foreign = SalesCrm::for($beta)->createDeal(['title' => 'Beta secret', 'next_step' => 'Untouched'], $bob);
    $betaStage = crmStage($foreign->pipeline, 'Qualified');
    SalesCrm::for($alpha)->createDeal(['title' => 'Alpha deal'], $alice);

    $this->actingAs($alice)->getJson('/api/crm/deals')->assertOk()->assertJsonPath('meta.total', 1)->assertDontSee('Beta secret');
    $this->actingAs($alice)->getJson('/api/crm/pipelines')->assertOk()->assertJsonCount(1, 'data');

    $refused = fn ($response) => expect($response->status())->toBeIn([403, 404, 422]);
    $refused($this->actingAs($alice)->getJson('/api/crm/deals/'.$foreign->id));
    $refused($this->actingAs($alice)->patchJson('/api/crm/deals/'.$foreign->id, ['next_step' => 'Hijacked']));
    $refused($this->actingAs($alice)->postJson('/api/crm/deals/'.$foreign->id.'/move', ['stage_id' => $betaStage->id]));
    $refused($this->actingAs($alice)->postJson('/api/crm/deals/'.$foreign->id.'/notes', ['body' => 'Hijacked']));
    $refused($this->actingAs($alice)->postJson('/api/crm/deals/'.$foreign->id.'/activities', ['type' => 'call', 'title' => 'Hijacked', 'due_date' => now()->toDateString()]));
    $refused($this->actingAs($alice)->deleteJson('/api/crm/deals/'.$foreign->id));
    $refused($this->actingAs($alice)->postJson('/api/crm/deals', ['title' => 'Intruder', 'pipeline_id' => $foreign->pipeline_id]));

    $foreign = $foreign->fresh();
    expect($foreign)->not->toBeNull()
        ->and($foreign->next_step)->toBe('Untouched')
        ->and($foreign->stage_id)->not->toBe($betaStage->id)
        ->and($foreign->notes()->count())->toBe(0)
        ->and($foreign->activities()->count())->toBe(0)
        ->and(Deal::query()->where('title', 'Intruder')->exists())->toBeFalse();

    Gate::define('sales-crm.access', fn ($user): bool => ! $user->is($bob));
    $this->actingAs($bob)->getJson('/api/crm/deals')->assertForbidden();
});

it('answers an owner that cannot be a user key with nothing found or a validation error, on every database', function () {
    enableApi();
    SalesCrm::shared()->createDeal(['title' => 'Owned'], $this->seller);

    $this->actingAs($this->seller)->getJson('/api/crm/deals?owner_id=abc')->assertOk()->assertJsonPath('meta.total', 0);
    $this->actingAs($this->seller)->postJson('/api/crm/deals', ['title' => 'Bad owner', 'owner_id' => 'abc'])->assertUnprocessable();

    expect(Crm::isUserKey('42'))->toBeTrue()
        ->and(Crm::isUserKey('abc'))->toBeFalse()
        ->and(Crm::isUserKey(''))->toBeFalse()
        ->and(Crm::isUserKey(7))->toBeTrue();
});
