<?php

use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Sellinnate\SalesCrm\Facades\SalesCrm;
use Sellinnate\SalesCrm\Mcp\SalesCrmServer;
use Sellinnate\SalesCrm\Mcp\Tools\CrmActivityAddTool;
use Sellinnate\SalesCrm\Mcp\Tools\CrmDealGetTool;
use Sellinnate\SalesCrm\Mcp\Tools\CrmDealMoveTool;
use Sellinnate\SalesCrm\Mcp\Tools\CrmDealsListTool;
use Sellinnate\SalesCrm\Mcp\Tools\CrmDealUpdateTool;
use Sellinnate\SalesCrm\Mcp\Tools\CrmNoteAddTool;
use Sellinnate\SalesCrm\Workspaces\Workspace;

/*
| deal_visibility "owner": a member of the workspace who doesn't manage it sees only their deals and
| the unassigned ones, through every interface.
*/

beforeEach(function () {
    useTeams();
    config(['sales-crm.authorization.deal_visibility' => 'owner', 'sales-crm.api.enabled' => true, 'sales-crm.api.middleware' => ['api', 'auth']]);
    Route::group([], __DIR__.'/../../routes/api.php');
    app('router')->getRoutes()->refreshNameLookups();
    app()->forgetScopedInstances();

    $this->seller = crmUser(['name' => 'Sam Seller']);
    $this->colleague = crmUser(['name' => 'Chris Colleague']);
    $this->team = crmTeam('Alpha', $this->seller, $this->colleague);
    $this->mine = SalesCrm::for($this->team)->createDeal(['title' => 'Mine to work', 'amount_once' => 1000, 'owner_id' => $this->seller->id], $this->seller);
    $this->hidden = SalesCrm::for($this->team)->createDeal(['title' => 'Colleague only', 'amount_once' => 7000, 'next_step' => 'Untouched', 'owner_id' => $this->colleague->id], $this->colleague);
});

it('hides the deals of colleagues from the JSON API', function () {
    $this->actingAs($this->seller)->getJson('/api/crm/deals')->assertOk()->assertJsonPath('meta.total', 1)->assertDontSee('Colleague only');

    foreach ([
        $this->getJson('/api/crm/deals/'.$this->hidden->id),
        $this->patchJson('/api/crm/deals/'.$this->hidden->id, ['next_step' => 'Hijacked']),
        $this->postJson('/api/crm/deals/'.$this->hidden->id.'/move', ['stage_id' => crmStage($this->hidden->pipeline, 'Qualified')->id]),
        $this->postJson('/api/crm/deals/'.$this->hidden->id.'/notes', ['body' => 'Hijacked']),
        $this->postJson('/api/crm/deals/'.$this->hidden->id.'/activities', ['type' => 'call', 'title' => 'Hijacked', 'due_date' => now()->toDateString()]),
    ] as $response) {
        expect($response->status())->toBeIn([403, 404]);
    }

    expect($this->hidden->fresh()->next_step)->toBe('Untouched')
        ->and($this->hidden->notes()->count())->toBe(0)
        ->and($this->hidden->activities()->count())->toBe(0);
});

it('hides the deals of colleagues from the MCP tools', function () {
    SalesCrmServer::actingAs($this->seller)->tool(CrmDealsListTool::class, ['search' => 'colleague'])->assertOk()->assertSee('"count":0');

    foreach ([
        [CrmDealGetTool::class, ['deal_id' => $this->hidden->id]],
        [CrmDealUpdateTool::class, ['deal_id' => $this->hidden->id, 'fields' => ['next_step' => 'Hijacked']]],
        [CrmDealMoveTool::class, ['deal_id' => $this->hidden->id, 'stage_id' => crmStage($this->hidden->pipeline, 'Qualified')->id]],
        [CrmNoteAddTool::class, ['deal_id' => $this->hidden->id, 'body' => 'Hijacked']],
        [CrmActivityAddTool::class, ['deal_id' => $this->hidden->id, 'type' => 'call', 'title' => 'Hijacked', 'due_date' => now()->toDateString()]],
    ] as [$tool, $arguments]) {
        SalesCrmServer::actingAs($this->seller)->tool($tool, $arguments)->assertHasErrors();
    }

    expect($this->hidden->fresh()->next_step)->toBe('Untouched')
        ->and($this->hidden->notes()->count())->toBe(0);
});

it('leaves the deals of colleagues out of the export and the reports', function () {
    $csv = $this->actingAs($this->seller)->get(route('sales-crm.deals.export', ['workspace' => Workspace::of($this->team)?->key()]))->assertOk()->streamedContent();
    $report = SalesCrm::reports()->build($this->seller, Workspace::of($this->team), null, CarbonImmutable::now()->subMonth(), CarbonImmutable::now());

    expect($csv)->toContain('Mine to work')->not->toContain('Colleague only')
        ->and($report['open_total']['count'])->toBe(1)
        ->and($report['open_total']['value'])->toBe(100000);
});

it('refuses the documents of the deals of colleagues', function () {
    Storage::fake('local');
    $document = SalesCrm::uploadDocument($this->hidden, UploadedFile::fake()->create('quote.pdf', 10, 'application/pdf'), ['kind' => 'quote'], $this->colleague);

    $this->actingAs($this->seller)->get(route('sales-crm.documents.download', $document))->assertForbidden();
    $this->actingAs($this->colleague)->get(route('sales-crm.documents.download', $document))->assertOk();
});
