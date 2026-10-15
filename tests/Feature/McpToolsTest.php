<?php

use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Server\Testing\PendingTestResponse;
use Sellinnate\SalesCrm\Facades\SalesCrm;
use Sellinnate\SalesCrm\Mcp\SalesCrmServer;
use Sellinnate\SalesCrm\Mcp\Tools\CrmActivitiesDueTool;
use Sellinnate\SalesCrm\Mcp\Tools\CrmActivityAddTool;
use Sellinnate\SalesCrm\Mcp\Tools\CrmDealCreateTool;
use Sellinnate\SalesCrm\Mcp\Tools\CrmDealGetTool;
use Sellinnate\SalesCrm\Mcp\Tools\CrmDealMoveTool;
use Sellinnate\SalesCrm\Mcp\Tools\CrmDealsListTool;
use Sellinnate\SalesCrm\Mcp\Tools\CrmDealUpdateTool;
use Sellinnate\SalesCrm\Mcp\Tools\CrmNoteAddTool;
use Sellinnate\SalesCrm\Mcp\Tools\CrmPipelinesTool;
use Sellinnate\SalesCrm\Models\Deal;
use Sellinnate\SalesCrm\Workspaces\Workspace;

beforeEach(function () {
    $this->seller = crmUser(['name' => 'Sam Seller']);
    $this->pipeline = crmPipeline();
    $this->deal = SalesCrm::shared()->createDeal(['title' => 'Website redesign', 'company_name' => 'Acme', 'amount_once' => 1500], $this->seller);
});

it('lists the tools of the server', function () {
    $tools = [
        CrmPipelinesTool::class, CrmDealsListTool::class, CrmDealGetTool::class, CrmDealCreateTool::class, CrmDealUpdateTool::class,
        CrmDealMoveTool::class, CrmNoteAddTool::class, CrmActivityAddTool::class, CrmActivitiesDueTool::class,
    ];

    // The tools() assertion only exists since laravel/mcp 1.0.
    if (method_exists(PendingTestResponse::class, 'tools')) {
        SalesCrmServer::actingAs($this->seller)->tools()->assertRegistered($tools);
    }

    expect((new ReflectionClass(SalesCrmServer::class))->getProperty('tools')->getDefaultValue())->toBe($tools)
        ->and(array_map(fn (string $tool): string => app($tool)->name(), $tools))->each->toStartWith('crm_');
});

it('reads pipelines, deals and the details of a deal', function () {
    SalesCrmServer::actingAs($this->seller)->tool(CrmPipelinesTool::class)->assertOk()->assertSee(['Sales pipeline', 'Proposal sent', '"deals":1']);
    SalesCrmServer::actingAs($this->seller)->tool(CrmDealsListTool::class, ['search' => 'acme'])->assertOk()->assertSee(['Website redesign', '"count":1']);
    $this->deal->forceFill(['source' => 'referral', 'tags' => ['vip']])->save();
    SalesCrmServer::actingAs($this->seller)->tool(CrmDealsListTool::class, ['source' => 'referral', 'tag' => 'vip'])->assertOk()->assertSee('"count":1');
    SalesCrmServer::actingAs($this->seller)->tool(CrmDealsListTool::class, ['source' => 'event'])->assertOk()->assertSee('"count":0');
    SalesCrmServer::actingAs($this->seller)->tool(CrmDealsListTool::class, ['tag' => 'other'])->assertOk()->assertSee('"count":0');
    SalesCrmServer::actingAs($this->seller)->tool(CrmDealGetTool::class, ['deal_id' => $this->deal->id])->assertOk()->assertSee(['Website redesign', 'timeline', 'Deal created']);
});

it('creates, updates and moves deals with the rules of the CRM', function () {
    SalesCrmServer::actingAs($this->seller)->tool(CrmDealCreateTool::class, ['title' => 'Mobile app', 'amount_once' => 9000, 'person' => ['first_name' => 'Ada', 'email' => 'ada@acme.example']])
        ->assertOk()->assertSee(['Deal created', 'Mobile app']);
    $deal = Deal::query()->where('title', 'Mobile app')->firstOrFail();

    SalesCrmServer::actingAs($this->seller)->tool(CrmDealUpdateTool::class, ['deal_id' => $deal->id, 'fields' => ['next_step' => 'Send the quote']])->assertOk();
    expect($deal->fresh()->next_step)->toBe('Send the quote');

    $proposal = crmStage($this->pipeline, 'Proposal sent');
    SalesCrmServer::actingAs($this->seller)->tool(CrmDealMoveTool::class, ['deal_id' => $deal->id, 'stage_id' => $proposal->id])
        ->assertHasErrors(['requirements.offer_valid_until']);

    SalesCrmServer::actingAs($this->seller)->tool(CrmDealMoveTool::class, ['deal_id' => $deal->id, 'stage_id' => $proposal->id, 'fill' => ['offer_valid_until' => '2030-01-01']])
        ->assertOk()->assertSee('Proposal sent');
    expect($deal->fresh()->stage_id)->toBe($proposal->id);

    SalesCrmServer::actingAs($this->seller)->tool(CrmDealMoveTool::class, ['deal_id' => $deal->id, 'stage_id' => 999999])->assertHasErrors();
});

it('adds notes and activities and lists the activities due', function () {
    SalesCrmServer::actingAs($this->seller)->tool(CrmNoteAddTool::class, ['deal_id' => $this->deal->id, 'body' => 'Spoke with the CEO'])->assertOk()->assertSee('Note added');
    SalesCrmServer::actingAs($this->seller)->tool(CrmActivityAddTool::class, ['deal_id' => $this->deal->id, 'type' => 'call', 'title' => 'Call back', 'due_date' => now()->toDateString()])->assertOk()->assertSee('Activity planned');
    SalesCrmServer::actingAs($this->seller)->tool(CrmActivityAddTool::class, ['deal_id' => $this->deal->id, 'type' => 'call', 'title' => 'Done already', 'due_date' => now()->toDateString(), 'done' => true, 'outcome' => 'OK'])->assertOk()->assertSee('Activity logged');
    SalesCrmServer::actingAs($this->seller)->tool(CrmActivitiesDueTool::class)->assertOk()->assertSee('Call back')->assertDontSee('Done already');
});

it('keeps the tools within the scope of the user', function () {
    useTeams();
    $alpha = crmTeam('Alpha', $this->seller);
    $stranger = crmUser();
    $alphaDeal = SalesCrm::for($alpha)->createDeal(['title' => 'Team secret'], $this->seller);

    SalesCrmServer::actingAs($stranger)->tool(CrmDealGetTool::class, ['deal_id' => $alphaDeal->id])->assertHasErrors(['not found']);
    SalesCrmServer::actingAs($stranger)->tool(CrmDealsListTool::class, ['search' => 'secret'])->assertSee('"count":0');
});

it('refuses users without access to the CRM', function () {
    Gate::define('sales-crm.access', fn () => false);

    SalesCrmServer::actingAs($this->seller)->tool(CrmDealsListTool::class)->assertHasErrors(['not allowed']);
});

it('refuses every write tool on a deal of another workspace, leaving it as it was', function () {
    useTeams();
    $alice = crmUser();
    $bob = crmUser();
    crmTeam('Alpha', $alice);
    $beta = crmTeam('Beta', $bob);
    $foreign = SalesCrm::for($beta)->createDeal(['title' => 'Beta secret', 'next_step' => 'Untouched'], $bob);
    $stage = crmStage($foreign->pipeline, 'Qualified');

    foreach ([
        [CrmDealGetTool::class, ['deal_id' => $foreign->id]],
        [CrmDealUpdateTool::class, ['deal_id' => $foreign->id, 'fields' => ['next_step' => 'Hijacked']]],
        [CrmDealMoveTool::class, ['deal_id' => $foreign->id, 'stage_id' => $stage->id]],
        [CrmNoteAddTool::class, ['deal_id' => $foreign->id, 'body' => 'Hijacked']],
        [CrmActivityAddTool::class, ['deal_id' => $foreign->id, 'type' => 'call', 'title' => 'Hijacked', 'due_date' => now()->toDateString()]],
        [CrmDealCreateTool::class, ['title' => 'Intruder', 'pipeline_id' => $foreign->pipeline_id]],
    ] as [$tool, $arguments]) {
        SalesCrmServer::actingAs($alice)->tool($tool, $arguments)->assertHasErrors();
    }

    $foreign = $foreign->fresh();
    expect($foreign->next_step)->toBe('Untouched')
        ->and($foreign->stage_id)->not->toBe($stage->id)
        ->and($foreign->notes()->count())->toBe(0)
        ->and($foreign->activities()->count())->toBe(0)
        ->and(Deal::query()->where('title', 'Intruder')->exists())->toBeFalse();
});

it('asks for the workspace of a new deal when the user works in several', function () {
    useTeams();
    $alpha = crmTeam('Alpha', $this->seller);
    crmTeam('Beta', $this->seller);
    app()->forgetScopedInstances();
    $seller = $this->seller->fresh();

    SalesCrmServer::actingAs($seller)->tool(CrmDealCreateTool::class, ['title' => 'Where?'])->assertHasErrors(['workspace']);
    SalesCrmServer::actingAs($seller)->tool(CrmDealCreateTool::class, ['title' => 'Where?', 'workspace' => 'Alpha'])->assertHasErrors(['workspace']);
    SalesCrmServer::actingAs($seller)->tool(CrmDealCreateTool::class, ['title' => 'Here', 'workspace' => Workspace::of($alpha)?->key()])->assertOk();

    expect(Deal::query()->where('title', 'Where?')->exists())->toBeFalse()
        ->and(Deal::query()->where('title', 'Here')->first()?->inWorkspace($alpha))->toBeTrue();
});

it('takes the reason of a loss inside fill too', function () {
    SalesCrmServer::actingAs($this->seller)->tool(CrmDealMoveTool::class, ['deal_id' => $this->deal->id, 'stage_id' => crmStage($this->pipeline, 'Lost')->id, 'fill' => ['lost_reason' => 'price']])
        ->assertOk();

    expect($this->deal->fresh()->lost_reason?->value)->toBe('price');
});
