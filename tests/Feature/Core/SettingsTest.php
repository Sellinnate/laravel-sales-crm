<?php

use Illuminate\Validation\ValidationException;
use Sellinnate\SalesCrm\Actions\SaveEmailTemplate;
use Sellinnate\SalesCrm\Actions\SaveWorkspaceSettings;
use Sellinnate\SalesCrm\Facades\SalesCrm;
use Sellinnate\SalesCrm\Models\EmailTemplate;
use Sellinnate\SalesCrm\Services\CustomFields;
use Sellinnate\SalesCrm\Services\DealColumns;

beforeEach(function () {
    $this->admin = crmUser(['is_admin' => true]);
    $this->seller = crmUser();
});

it('defines custom fields whose values live on the deals', function () {
    $fields = app(SaveWorkspaceSettings::class)->customFields(null, [
        ['key' => 'seats', 'label' => 'Seats', 'type' => 'number'],
        ['key' => 'plan', 'label' => 'Plan', 'type' => 'choice', 'options' => 'Basic, Pro'],
        ['key' => 'renewal', 'label' => 'Auto renewal', 'type' => 'boolean', 'options' => 'ignored'],
    ], $this->admin);

    expect($fields[1]['options'])->toBe(['Basic', 'Pro'])
        ->and($fields[2])->not->toHaveKey('options')
        ->and(CustomFields::of(null))->toHaveCount(3)
        ->and(DealColumns::all(null))->toHaveKey('custom:plan', 'Plan');

    $deal = SalesCrm::shared()->createDeal(['title' => 'Custom', 'custom_values' => ['seats' => '12', 'plan' => 'Pro', 'renewal' => '1']], $this->seller);
    expect($deal->custom_values)->toBe(['seats' => 12, 'plan' => 'Pro', 'renewal' => true])
        ->and(DealColumns::text($deal, 'custom:renewal', collect(CustomFields::of(null))->keyBy('key')->all()))->toBe('Yes');

    expect(fn () => SalesCrm::updateDeal($deal, ['custom_values' => ['plan' => 'Enterprise']], $this->seller))->toThrow(ValidationException::class);

    $deal = SalesCrm::updateDeal($deal, ['custom_values' => ['seats' => '']], $this->seller);
    expect($deal->custom_values)->toBe(['plan' => 'Pro', 'renewal' => true]);
});

it('validates the definitions of custom fields', function () {
    $save = fn (array $fields) => app(SaveWorkspaceSettings::class)->customFields(null, $fields, $this->admin);

    expect(fn () => $save([['key' => 'Bad Key', 'label' => 'X', 'type' => 'text']]))->toThrow(ValidationException::class)
        ->and(fn () => $save([['key' => 'a', 'label' => 'Same', 'type' => 'text'], ['key' => 'b', 'label' => 'same', 'type' => 'text']]))->toThrow(ValidationException::class)
        ->and(fn () => $save([['key' => 'c', 'label' => 'Choice', 'type' => 'choice', 'options' => '']]))->toThrow(ValidationException::class)
        ->and(fn () => app(SaveWorkspaceSettings::class)->customFields(null, [], $this->seller))->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);
});

it('manages email templates with unique names', function () {
    $template = app(SaveEmailTemplate::class)->handle(null, ['name' => 'Follow-up', 'subject' => 'Hi', 'body' => 'Body'], $this->admin);

    expect(fn () => app(SaveEmailTemplate::class)->handle(null, ['name' => 'Follow-up', 'subject' => 'X', 'body' => 'Y'], $this->admin))->toThrow(ValidationException::class)
        ->and(fn () => app(SaveEmailTemplate::class)->handle(null, ['name' => 'Mine', 'subject' => 'X', 'body' => 'Y'], $this->seller))->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);

    app(SaveEmailTemplate::class)->delete($template, $this->admin);
    expect(EmailTemplate::query()->count())->toBe(0);
});

it('accepts as entry stage only an open stage of the workspace', function () {
    $pipeline = crmPipeline();

    expect(fn () => app(SaveWorkspaceSettings::class)->handle(null, ['entry_stage_id' => crmStage($pipeline, 'Won')->id], $this->admin))->toThrow(ValidationException::class);

    $settings = app(SaveWorkspaceSettings::class)->handle(null, ['entry_stage_id' => crmStage($pipeline, 'Qualified')->id], $this->admin);
    expect($settings->entryStage?->name)->toBe('Qualified');
});
