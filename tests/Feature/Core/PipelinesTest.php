<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Sellinnate\SalesCrm\Actions\SavePipeline;
use Sellinnate\SalesCrm\Actions\SaveStage;
use Sellinnate\SalesCrm\Enums\StageKind;
use Sellinnate\SalesCrm\Facades\SalesCrm;
use Sellinnate\SalesCrm\Models\Pipeline;
use Sellinnate\SalesCrm\Models\Stage;
use Sellinnate\SalesCrm\Services\DefaultPipeline;

beforeEach(function () {
    $this->admin = crmUser(['is_admin' => true]);
});

it('creates the default pipeline once, the first time the CRM is opened', function () {
    $first = app(DefaultPipeline::class)->ensureFor(null);
    $again = app(DefaultPipeline::class)->ensureFor(null);

    expect($again->id)->toBe($first->id)
        ->and(Pipeline::query()->count())->toBe(1)
        ->and($first->stages->pluck('name')->all())->toBe(['New lead', 'Qualified', 'Meeting / demo', 'Proposal sent', 'Negotiation', 'Signature', 'Won', 'Lost'])
        ->and(crmStage($first, 'Proposal sent')->required_fields)->toBe(['amount', 'offer_valid_until'])
        ->and(crmStage($first, 'Proposal sent')->on_enter_activity)->toBe(['type' => 'call', 'title' => 'Follow up on the proposal', 'due_in_days' => 3]);
});

it('builds the default stages from the configuration and falls back when it is broken', function () {
    config(['sales-crm.pipeline.default_stages' => [
        ['name' => 'Lead', 'kind' => 'open', 'probability' => 140, 'required_fields' => ['amount', 'nonsense']],
        ['name' => 'Done', 'kind' => 'won', 'probability' => 3],
        ['name' => 'Gone', 'kind' => 'lost'],
    ]]);

    $stages = DefaultPipeline::stages();

    expect($stages[0]['probability'])->toBe(100)
        ->and($stages[0]['required_fields'])->toBe(['amount'])
        ->and($stages[1]['probability'])->toBe(100);

    config(['sales-crm.pipeline.default_stages' => [['name' => 'Only open']]]);

    expect(array_column(DefaultPipeline::stages(), 'kind'))->toBe(['open', 'won', 'lost']);
});

it('creates, renames and deletes pipelines, keeping one active', function () {
    $default = crmPipeline();
    $second = app(SavePipeline::class)->handle(null, ['name' => 'Partners'], $this->admin);

    expect($second->stages)->toHaveCount(8)
        ->and($second->position)->toBe(1);

    app(SavePipeline::class)->handle(null, ['name' => 'Partner deals', 'is_active' => false], $this->admin, $second);
    expect($second->fresh()->name)->toBe('Partner deals')->and($second->fresh()->is_active)->toBeFalse();

    expect(fn () => app(SavePipeline::class)->handle(null, ['name' => 'Sales', 'is_active' => false], $this->admin, $default))
        ->toThrow(ValidationException::class);

    SalesCrm::shared()->createDeal(['title' => 'Busy', 'pipeline_id' => $second->id], $this->admin);
    expect(fn () => app(SavePipeline::class)->delete($second, $this->admin))->toThrow(ValidationException::class);

    $empty = app(SavePipeline::class)->handle(null, ['name' => 'Empty'], $this->admin);
    app(SavePipeline::class)->delete($empty, $this->admin);
    expect(Pipeline::query()->find($empty->id))->toBeNull();
});

it('adds open stages before the closed ones and keeps the kinds of a pipeline', function () {
    $pipeline = crmPipeline();
    $stage = app(SaveStage::class)->handle($pipeline, ['name' => 'Discovery', 'color' => 'info', 'probability' => 30, 'kind' => 'open', 'rotting_days' => 5, 'required_fields' => ['company'], 'on_enter_activity' => ['type' => 'task', 'title' => 'Prepare questions', 'due_in_days' => 1]], $this->admin);

    $names = $pipeline->stages()->pluck('name')->all();
    expect(array_search('Discovery', $names, true))->toBe(6)
        ->and($names[7])->toBe('Won')
        ->and($stage->required_fields)->toBe(['company']);

    $won = app(SaveStage::class)->handle($pipeline, ['name' => 'Closed', 'color' => 'success', 'probability' => 20, 'kind' => 'won'], $this->admin);
    expect($won->probability)->toBe(100);

    expect(fn () => app(SaveStage::class)->delete(crmStage($pipeline, 'Lost'), $this->admin))->toThrow(ValidationException::class);

    SalesCrm::shared()->createDeal(['title' => 'In discovery', 'stage_id' => $stage->id, 'company_name' => 'Acme'], $this->admin);
    expect(fn () => app(SaveStage::class)->handle($pipeline, ['name' => 'Discovery', 'color' => 'info', 'probability' => 30, 'kind' => 'won'], $this->admin, $stage))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(SaveStage::class)->delete($stage, $this->admin))->toThrow(ValidationException::class);
});

it('reorders the stages with all and only the stages of the pipeline', function () {
    $pipeline = crmPipeline();
    $ids = $pipeline->stages->pluck('id')->all();
    $reversed = array_reverse($ids);

    app(SaveStage::class)->reorder($pipeline, $reversed, $this->admin);
    expect($pipeline->stages()->pluck('id')->all())->toBe($reversed);

    expect(fn () => app(SaveStage::class)->reorder($pipeline, array_slice($ids, 1), $this->admin))->toThrow(ValidationException::class);
});

it('lets only managers configure pipelines and stages', function () {
    $seller = crmUser();
    $pipeline = crmPipeline();

    expect(fn () => app(SavePipeline::class)->handle(null, ['name' => 'Mine'], $seller))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SaveStage::class)->handle($pipeline, ['name' => 'X', 'color' => 'info', 'probability' => 1, 'kind' => 'open'], $seller))->toThrow(AuthorizationException::class);
});

it('reads the first open stage of a pipeline', function () {
    $pipeline = crmPipeline();

    expect($pipeline->firstOpenStage()?->name)->toBe('New lead')
        ->and(Stage::query()->where('kind', StageKind::Won->value)->count())->toBe(1);
});
