<?php

use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Sellinnate\SalesCrm\Facades\SalesCrm;
use Sellinnate\SalesCrm\Livewire\Agenda;
use Sellinnate\SalesCrm\Livewire\Companies;
use Sellinnate\SalesCrm\Livewire\CompanyPage;
use Sellinnate\SalesCrm\Livewire\Import;
use Sellinnate\SalesCrm\Livewire\People;
use Sellinnate\SalesCrm\Livewire\PersonPage;
use Sellinnate\SalesCrm\Livewire\RecordFeed;
use Sellinnate\SalesCrm\Livewire\Reports;
use Sellinnate\SalesCrm\Livewire\Settings;
use Sellinnate\SalesCrm\Models\Company;
use Sellinnate\SalesCrm\Models\Deal;
use Sellinnate\SalesCrm\Models\Import as ImportModel;
use Sellinnate\SalesCrm\Models\Stage;
use Sellinnate\SalesCrm\Services\CustomFields;

beforeEach(function () {
    $this->seller = crmUser(['name' => 'Sam Seller']);
    $this->admin = crmUser(['name' => 'Alex Admin', 'is_admin' => true]);
    $this->pipeline = crmPipeline();
});

it('completes activities from the agenda', function () {
    $deal = SalesCrm::shared()->createDeal(['title' => 'Agenda deal'], $this->seller);
    $overdue = SalesCrm::scheduleActivity($deal, ['type' => 'call', 'title' => 'Overdue call', 'due_date' => CarbonImmutable::yesterday()->toDateString()], $this->seller);
    SalesCrm::scheduleActivity($deal, ['type' => 'meeting', 'title' => 'Next week meeting', 'due_date' => CarbonImmutable::today()->addDays(3)->toDateString()], $this->seller);
    $this->actingAs($this->seller);

    Livewire::test(Agenda::class)
        ->assertSee(['Overdue call', 'Next week meeting', 'Agenda deal'])
        ->call('complete', $overdue->id)
        ->assertDontSee('Overdue call');

    expect($overdue->fresh()->isDone())->toBeTrue();
});

it('creates companies and people, showing the possible duplicate', function () {
    $this->actingAs($this->seller);
    SalesCrm::shared()->company(['name' => 'Acme S.r.l.']);

    Livewire::test(Companies::class)
        ->call('startCreate')
        ->set('draft.name', 'ACME srl')
        ->call('create')
        ->assertSet('duplicate', fn (?string $duplicate): bool => str_contains((string) $duplicate, 'Acme S.r.l.'))
        ->call('create', true)
        ->assertRedirect();

    expect(Company::query()->count())->toBe(2);

    Livewire::test(People::class)
        ->call('startCreate')
        ->set('draft.first_name', 'Jane')
        ->set('draft.email', 'jane@acme.example')
        ->call('create')
        ->assertRedirect();

    Livewire::test(People::class)->set('search', 'jane')->assertSee('Jane');
});

it('edits a company and a person and works their feed', function () {
    $this->actingAs($this->seller);
    $deal = SalesCrm::shared()->createDeal(['title' => 'Linked deal', 'company_name' => 'Acme', 'person' => ['first_name' => 'Jane']], $this->seller);
    $company = $deal->company;
    $person = $deal->people->first();

    Livewire::test(CompanyPage::class, ['company' => $company])
        ->assertSee(['Linked deal', 'Jane'])
        ->set('form.city', 'Milan')
        ->call('save')
        ->assertHasNoErrors();
    expect($company->fresh()->city)->toBe('Milan');

    Livewire::test(PersonPage::class, ['person' => $person])
        ->set('form.job_title', 'CEO')
        ->call('save')
        ->assertHasNoErrors();
    expect($person->fresh()->job_title)->toBe('CEO');

    Livewire::test(RecordFeed::class, ['type' => 'company', 'recordId' => $company->id])
        ->set('noteBody', 'About Acme')
        ->call('addNote')
        ->set('activity.title', 'Check credit')
        ->call('saveActivity')
        ->assertSee(['About Acme', 'Check credit']);
});

it('shows the reports with their filters', function () {
    SalesCrm::shared()->createDeal(['title' => 'Report deal', 'amount_once' => 4200], $this->seller);
    $this->actingAs($this->seller);

    Livewire::test(Reports::class)
        ->assertSee('€4,200')
        ->set('owner', (string) $this->seller->id)
        ->assertSee('€4,200')
        ->set('owner', (string) $this->admin->id)
        ->assertDontSee('€4,200');
});

it('runs the import wizard from upload to report', function () {
    Storage::fake('local');
    $this->actingAs($this->admin);
    $csv = UploadedFile::fake()->createWithContent('deals.csv', "Ref;Company;Stage;Amount\nR-1;Acme;Negotiation phase;1.200\nR-2;Blue Harbor;New;300\n");

    $component = Livewire::test(Import::class)
        ->set('upload', $csv)
        ->call('uploadFile')
        ->assertHasNoErrors()
        ->assertSet('columns', ['0' => 'external_ref', '1' => 'company', '2' => 'stage', '3' => 'amount_once']);

    $negotiation = Stage::query()->where('name', 'Negotiation')->firstOrFail();
    expect($component->get('stages'))->toBe([
        ['value' => 'Negotiation phase', 'stage' => (string) $negotiation->id],
        ['value' => 'New', 'stage' => (string) Stage::query()->where('name', 'New lead')->value('id')],
    ]);

    $component->call('saveMapping')->assertSee('Acme')->call('run')->assertSee(__('sales-crm::ui.import.created'));

    expect(Deal::query()->where('external_ref', 'R-1')->first()?->stage_id)->toBe($negotiation->id)
        ->and(Deal::query()->count())->toBe(2);
});

it('maps stage values that contain a dot', function () {
    Storage::fake('local');
    $this->actingAs($this->admin);
    $csv = UploadedFile::fake()->createWithContent('deals.csv', "Title;Stage\nPortal;Closed.Won\nApp;Prop. inviata\n");
    $won = Stage::query()->where('name', 'Won')->firstOrFail();

    $component = Livewire::test(Import::class)->set('upload', $csv)->call('uploadFile')->assertHasNoErrors()
        ->set('columns', ['0' => 'title', '1' => 'stage']);
    $index = collect($component->get('stages'))->search(fn (array $row): bool => $row['value'] === 'Closed.Won');

    $component->set('stages.'.$index.'.stage', (string) $won->id)->call('saveMapping')->assertHasNoErrors();

    expect(ImportModel::query()->latest('id')->first()?->mapping['stages'] ?? [])->toHaveKey('Closed.Won', $won->id)
        ->and(collect($component->get('stages'))->firstWhere('value', 'Prop. inviata'))->not->toBeNull();
});

it('refuses a damaged spreadsheet with a message, leaving no file behind', function () {
    Storage::fake('local');
    $this->actingAs($this->admin);

    Livewire::test(Import::class)
        ->set('upload', UploadedFile::fake()->createWithContent('deals.xlsx', 'not a zip at all'))
        ->call('uploadFile')
        ->assertHasErrors('upload.file')
        ->assertSee(__('sales-crm::validation.import_unreadable'));

    expect(ImportModel::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles((string) config('sales-crm.import.directory')))->toBe([]);
});

it('closes the import to users who do not manage the CRM', function () {
    $this->actingAs($this->seller);

    Livewire::test(Import::class)->assertForbidden();
});

it('configures pipelines, stages, custom fields and templates from the settings', function () {
    $this->actingAs($this->admin);

    $component = Livewire::test(Settings::class)->assertSee(['Sales pipeline', 'Proposal sent']);
    $component->call('editStage')->set('stageForm.name', 'Discovery')->set('stageForm.probability', '25')->call('saveStage')->assertHasNoErrors()->assertSee('Discovery');
    $component->call('editPipeline')->set('pipelineForm.name', 'Partners')->call('savePipeline')->assertHasNoErrors()->assertSee('Partners');

    $component->set('tab', 'fields')->call('addField')->set('fields.0.label', 'Seats')->set('fields.0.key', 'seats')->set('fields.0.type', 'number')->call('saveFields')->assertHasNoErrors();
    expect(CustomFields::of(null))->toHaveCount(1);

    $component->set('tab', 'templates')->call('editTemplate')->set('templateForm.name', 'Intro')->set('templateForm.subject', 'Hi')->set('templateForm.body', 'Hello {{first_name}}')->call('saveTemplate')->assertHasNoErrors()->assertSee('Intro');

    $stage = Stage::query()->where('name', 'Qualified')->firstOrFail();
    $component->set('tab', 'general')->set('entryStage', (string) $stage->id)->call('saveGeneral')->assertHasNoErrors();
});

it('shows the settings read-only to users who do not manage the CRM', function () {
    $this->actingAs($this->seller);

    Livewire::test(Settings::class)->assertSee(__('sales-crm::ui.settings.readonly'))->assertDontSee('data-test="stage-new"', false);
});
