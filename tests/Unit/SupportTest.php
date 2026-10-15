<?php

use Carbon\CarbonImmutable;
use Sellinnate\SalesCrm\Services\CustomFields;
use Sellinnate\SalesCrm\Services\EmailTemplates;
use Sellinnate\SalesCrm\Services\SpreadsheetExport;
use Sellinnate\SalesCrm\Support\Format;
use Sellinnate\SalesCrm\Support\MessageId;
use Sellinnate\SalesCrm\Support\Phone;
use Sellinnate\SalesCrm\Support\Search;
use Sellinnate\SalesCrm\Workspaces\Workspace;
use Workbench\App\Models\Team;

it('formats money in the locale and currency of the CRM', function () {
    app()->setLocale('en');
    config(['sales-crm.currency' => 'EUR']);
    expect(Format::money(150000))->toBe('€1,500')
        ->and(Format::money(150050))->toBe('€1,500.50')
        ->and(Format::money(null))->toBe('');

    app()->setLocale('it');
    expect(Format::money(150000))->toBe('1.500 €');

    config(['sales-crm.locale' => 'en', 'sales-crm.currency' => 'USD']);
    expect(Format::money(99))->toBe('$0.99')
        ->and(Format::percent(57.1))->toBe('57.1%')
        ->and(Format::percent(null))->toBe('—')
        ->and(Format::date(CarbonImmutable::parse('2026-10-13')))->toBe('10/13/2026');
});

it('normalises phone numbers to E.164', function () {
    config(['sales-crm.phone.default_region' => 'IT']);

    expect(Phone::normalize('333 123 4567'))->toBe('+393331234567')
        ->and(Phone::normalize('+39 333 1234567'))->toBe('+393331234567')
        ->and(Phone::normalize('0039 333 1234567'))->toBe('+393331234567')
        ->and(Phone::normalize('call me'))->toBeNull()
        ->and(Phone::normalize(''))->toBeNull()
        ->and(Phone::display('+393331234567'))->toBe('+39 333 123 4567');
});

it('keeps workspaces as morph type and key', function () {
    $team = Team::query()->create(['name' => 'Alpha']);
    $workspace = Workspace::of($team);

    expect($workspace?->key())->toBe($team->getMorphClass().'|'.$team->id)
        ->and(Workspace::same($workspace, Workspace::fromKey($workspace?->key())))->toBeTrue()
        ->and(Workspace::same(null, null))->toBeTrue()
        ->and(Workspace::same($workspace, null))->toBeFalse()
        ->and(Workspace::fromKey('nope'))->toBeNull()
        ->and(Workspace::fromKey($workspace?->key())?->model()?->is($team))->toBeTrue();
});

it('normalises the values of custom fields by type', function () {
    $fields = [
        ['key' => 'seats', 'label' => 'Seats', 'type' => 'number'],
        ['key' => 'renewal', 'label' => 'Renewal', 'type' => 'boolean'],
        ['key' => 'plan', 'label' => 'Plan', 'type' => 'choice', 'options' => ['Basic', 'Pro']],
    ];

    expect(CustomFields::normalize($fields, ['seats' => '12', 'renewal' => 'true', 'plan' => ' Pro ', 'other' => 'x']))
        ->toBe(['seats' => 12, 'renewal' => true, 'plan' => 'Pro'])
        ->and(CustomFields::display($fields[1], true))->toBe('Yes')
        ->and(CustomFields::rules($fields))->toHaveKeys(['custom_values.seats', 'custom_values.renewal', 'custom_values.plan']);
});

it('escapes search patterns and neutralises spreadsheet formulas', function () {
    expect(Search::contains('50%_off!'))->toBe('%50!%!_off!!%')
        ->and(SpreadsheetExport::neutralize('=SUM(A1)'))->toBe("'=SUM(A1)")
        ->and(SpreadsheetExport::neutralize('+39 333 123 4567'))->toBe('+39 333 123 4567')
        ->and(SpreadsheetExport::neutralize('-12.5'))->toBe('-12.5')
        ->and(SpreadsheetExport::neutralize('+cmd|calc'))->toBe("'+cmd|calc")
        ->and(SpreadsheetExport::neutralize('-2+3+cmd'))->toBe("'-2+3+cmd")
        ->and(SpreadsheetExport::neutralize('@SUM(1)'))->toBe("'@SUM(1)")
        ->and(SpreadsheetExport::restore("'=SUM(A1)"))->toBe('=SUM(A1)')
        ->and(SpreadsheetExport::restore("'quoted"))->toBe("'quoted")
        ->and(Phone::normalize("'+39 333 1234567"))->toBe(Phone::normalize('+39 333 1234567'))
        ->and(SpreadsheetExport::neutralize('Acme'))->toBe('Acme');
});

it('generates and normalises message ids', function () {
    expect(MessageId::generate('sales@acme.example'))->toEndWith('@acme.example')
        ->and(MessageId::normalize('<abc@ACME.example>'))->toBe('abc@acme.example')
        ->and(MessageId::normalize('nothing'))->toBeNull()
        ->and(EmailTemplates::placeholders())->toContain('{{first_name}}');
});
