<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Sellinnate\SalesCrm\Actions\ManageImports;
use Sellinnate\SalesCrm\Enums\ImportStatus;
use Sellinnate\SalesCrm\Events\ImportFinished;
use Sellinnate\SalesCrm\Facades\SalesCrm;
use Sellinnate\SalesCrm\Models\Company;
use Sellinnate\SalesCrm\Models\Deal;
use Sellinnate\SalesCrm\Models\Note;
use Sellinnate\SalesCrm\Services\Import\Importer;
use Sellinnate\SalesCrm\Services\Import\ImportFields;
use Sellinnate\SalesCrm\Services\Import\SpreadsheetReader;

beforeEach(function () {
    Storage::fake('local');
    $this->admin = crmUser(['is_admin' => true, 'name' => 'Alex Admin', 'email' => 'alex@vendor.example']);
    $this->pipeline = crmPipeline();
    $this->imports = app(ManageImports::class);
});

function csvFile(string $name, array $rows, string $delimiter = ';'): UploadedFile
{
    $content = implode("\n", array_map(fn (array $row): string => implode($delimiter, array_map(fn ($cell) => str_contains((string) $cell, $delimiter) || str_contains((string) $cell, '"') ? '"'.str_replace('"', '""', (string) $cell).'"' : $cell, $row)), $rows));

    return UploadedFile::fake()->createWithContent($name, "\u{FEFF}".$content);
}

it('suggests the fields of the columns from English and Italian headers', function () {
    $suggested = ImportFields::suggest(['ID', 'Cliente', 'Deal stage', 'Status', 'Referente', 'E-mail', 'Cellulare', 'Importo', 'Note dopo contatto', 'Note'], null);

    expect($suggested)->toBe([0 => 'external_ref', 1 => 'company', 2 => 'stage', 3 => 'sub_status', 4 => 'person_name', 5 => 'person_email', 6 => 'person_mobile', 7 => 'amount_once', 8 => 'note', 9 => 'note']);
});

it('imports a CSV, matching stages, owners, people and amounts, and reports what it could not read', function () {
    Event::fake([ImportFinished::class]);
    $file = csvFile('pipeline.csv', [
        ['ID', 'Company', 'Title', 'Stage', 'Owner', 'Contact', 'Email', 'Amount', 'Recurring', 'Close date', 'Priority', 'Notes'],
        ['A-1', 'Acme S.r.l.', 'Website', 'Proposal sent', 'Alex Admin', 'Jane Doe', 'jane@acme.example', '1.500', '79 €/month', '13/10/2026', 'P1', 'Call after lunch'],
        ['A-2', 'Blue Harbor', '', 'Lost - price', 'Nobody Known', 'Bob', 'not-an-email', 'about 2k', '', '2 October', 'high', ''],
        ['', '', '', '', '', '', '', '', '', '', '', 'orphan note'],
    ]);

    $import = $this->imports->upload(null, $this->pipeline, $file, $this->admin);
    $this->imports->map($import, [
        'columns' => [0 => 'external_ref', 1 => 'company', 2 => 'title', 3 => 'stage', 4 => 'owner', 5 => 'person_name', 6 => 'person_email', 7 => 'amount_once', 8 => 'recurring_options', 9 => 'expected_close_date', 10 => 'priority', 11 => 'note'],
        'stages' => ['Proposal sent' => crmStage($this->pipeline, 'Proposal sent')->id, 'Lost - price' => crmStage($this->pipeline, 'Lost')->id],
        'default_stage_id' => crmStage($this->pipeline, 'New lead')->id,
    ], $this->admin);

    $preview = app(Importer::class)->outcomes($import->fresh());
    expect(array_column($preview, 'action'))->toBe(['create', 'create', 'reject']);

    $this->imports->run($import->fresh(), $this->admin);
    $import = $import->fresh();

    expect($import->status)->toBe(ImportStatus::Done)
        ->and($import->created_count)->toBe(2)
        ->and($import->rejected_count)->toBe(1)
        ->and($import->rejected[0]['row'])->toBe(4);
    Event::assertDispatched(ImportFinished::class);

    $website = Deal::query()->where('external_ref', 'A-1')->firstOrFail();
    expect($website->stage->name)->toBe('Proposal sent')
        ->and($website->owner_id)->toBe($this->admin->id)
        ->and($website->amount_once_cents)->toBe(150000)
        ->and($website->recurring_cents)->toBe(7900)
        ->and($website->expected_close_date->toDateString())->toBe('2026-10-13')
        ->and($website->priority?->value)->toBe('p1')
        ->and($website->source->value)->toBe('import')
        ->and($website->people->first()->email)->toBe('jane@acme.example')
        ->and(Note::query()->where('deal_id', $website->id)->value('body'))->toContain('Call after lunch');

    $lost = Deal::query()->where('external_ref', 'A-2')->firstOrFail();
    expect($lost->title)->toBe('Blue Harbor')
        ->and($lost->isLost())->toBeTrue()
        ->and($lost->lost_reason?->value)->toBe('other')
        ->and($lost->owner_id)->toBeNull()
        ->and($lost->amount_once_cents)->toBeNull()
        ->and(Note::query()->where('deal_id', $lost->id)->value('body'))->toContain('about 2k')->toContain('not-an-email');

    $messages = collect($import->warnings)->pluck('message')->implode(' | ');
    expect($messages)->toContain('Nobody Known')->toContain('high')->toContain('Other');
});

it('updates the deals already imported by their key, without taking back a verified signature', function () {
    $first = csvFile('first.csv', [['ID', 'Title', 'Amount'], ['K-1', 'Original', '100']]);
    $import = $this->imports->upload(null, $this->pipeline, $first, $this->admin);
    $this->imports->map($import, ['columns' => [0 => 'external_ref', 1 => 'title', 2 => 'amount_once']], $this->admin);
    $this->imports->run($import->fresh(), $this->admin);

    $deal = Deal::query()->where('external_ref', 'K-1')->firstOrFail();
    SalesCrm::updateDeal($deal, ['signature_status' => 'signed', 'signature_evidence' => 'https://sign.example/1'], $this->admin);

    $second = csvFile('second.csv', [['ID', 'Amount', 'Signature'], ['K-1', '250', 'to sign']]);
    $import = $this->imports->upload(null, $this->pipeline, $second, $this->admin);
    $this->imports->map($import, ['columns' => [0 => 'external_ref', 1 => 'amount_once', 2 => 'signature']], $this->admin);
    $this->imports->run($import->fresh(), $this->admin);

    $deal = $deal->fresh();
    expect($import->fresh()->updated_count)->toBe(1)
        ->and(Deal::query()->count())->toBe(1)
        ->and($deal->title)->toBe('Original')
        ->and($deal->amount_once_cents)->toBe(25000)
        ->and($deal->signature_status->value)->toBe('signed');
});

it('imports the chosen sheet of an XLSX file', function () {
    $path = sys_get_temp_dir().'/crm-test-'.uniqid().'.xlsx';
    $writer = new Writer;
    $writer->openToFile($path);
    $writer->getCurrentSheet()->setName('Start here');
    $writer->addRow(Row::fromValues(['Read me first']));
    $sheet = $writer->addNewSheetAndMakeItCurrent();
    $sheet->setName('Pipeline');
    $writer->addRow(Row::fromValues(['Ref', 'Company', 'Value']));
    $writer->addRow(Row::fromValues(['X-1', 'Orbital Robotics', 4200]));
    $writer->close();

    $import = $this->imports->upload(null, $this->pipeline, new UploadedFile($path, 'sheet.xlsx', null, null, true), $this->admin);
    expect($import->sheet)->toBe('Start here');

    $this->imports->map($import, ['sheet' => 'Pipeline', 'columns' => [0 => 'external_ref', 1 => 'company', 2 => 'amount_once']], $this->admin);
    $this->imports->run($import->fresh(), $this->admin);

    $deal = Deal::query()->where('external_ref', 'X-1')->firstOrFail();
    expect($deal->title)->toBe('Orbital Robotics')->and($deal->amount_once_cents)->toBe(420000);
    @unlink($path);
});

it('refuses unsupported files, unknown sheets and imports without columns, for non managers too', function () {
    expect(fn () => $this->imports->upload(null, $this->pipeline, UploadedFile::fake()->create('deck.pdf', 10, 'application/pdf'), $this->admin))->toThrow(ValidationException::class)
        ->and(fn () => $this->imports->upload(null, $this->pipeline, csvFile('x.csv', [['A'], ['1']]), crmUser()))->toThrow(AuthorizationException::class);

    $import = $this->imports->upload(null, $this->pipeline, csvFile('x.csv', [['A'], ['1']]), $this->admin);

    expect(fn () => $this->imports->map($import, ['columns' => [0 => 'nonsense']], $this->admin))->toThrow(ValidationException::class)
        ->and(fn () => $this->imports->run($import, $this->admin))->toThrow(ValidationException::class);
});

it('downloads the rejected rows as CSV, for managers', function () {
    $import = $this->imports->upload(null, $this->pipeline, csvFile('rows.csv', [['Title', 'Note'], ['', 'no title here'], ['Good one', '']]), $this->admin);
    $this->imports->map($import, ['columns' => [0 => 'title', 1 => 'note']], $this->admin);
    $this->imports->run($import->fresh(), $this->admin);

    $this->actingAs(crmUser())->get(route('sales-crm.imports.rejected', $import))->assertForbidden();

    $response = $this->actingAs($this->admin)->get(route('sales-crm.imports.rejected', $import));
    $response->assertOk();
    expect($response->streamedContent())->toContain('no title here')->not->toContain('Good one');
});

it('numbers the XLSX rows as the sheet does, across empty rows', function () {
    Storage::fake('local');
    $path = sys_get_temp_dir().'/crm-test-'.uniqid().'.xlsx';
    $writer = new Writer;
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues(['Title', 'Company']));
    $writer->addRow(Row::fromValues([]));
    $writer->addRow(Row::fromValues(['', '']));
    $writer->addRow(Row::fromValues(['Mobile app', 'Orbital Robotics']));
    $writer->close();
    Storage::disk('local')->put('imports/rows.xlsx', (string) file_get_contents($path));
    @unlink($path);

    $rows = iterator_to_array((new SpreadsheetReader('local', 'imports/rows.xlsx'))->rows());

    expect(array_keys($rows))->toBe([1, 4])
        ->and($rows[4])->toBe(['Mobile app', 'Orbital Robotics']);
});

it('reads the delimiter outside the quoted fields of the header', function () {
    Storage::fake('local');
    Storage::disk('local')->put('imports/quoted.csv', "\"Company, legal name\";\"Title, short\";Value\n\"Acme, Inc.\";Website;1200\n");

    $rows = iterator_to_array((new SpreadsheetReader('local', 'imports/quoted.csv'))->rows());

    expect($rows[1])->toBe(['Company, legal name', 'Title, short', 'Value'])
        ->and($rows[2])->toBe(['Acme, Inc.', 'Website', '1200']);
});

it('matches and completes the companies by the VAT number and the website of the rows', function () {
    $acme = SalesCrm::shared()->company(['name' => 'Acme S.r.l.', 'vat_number' => 'IT01234567890']);
    $file = csvFile('companies.csv', [
        ['Title', 'Ragione sociale', 'Partita IVA', 'Sito web'],
        ['Portal', 'ACME Italia srl', 'IT01234567890', ''],
        ['App', 'Quartz Labs', 'IT09876543210', 'https://www.quartzlabs.example'],
    ]);

    $import = $this->imports->upload(null, $this->pipeline, $file, $this->admin);
    $this->imports->map($import, ['columns' => [0 => 'title', 1 => 'company', 2 => 'company_vat', 3 => 'company_website']], $this->admin);
    $this->imports->run($import->fresh(), $this->admin);

    $quartz = Company::query()->where('name', 'Quartz Labs')->first();
    expect(Deal::query()->where('title', 'Portal')->value('company_id'))->toBe($acme->id)
        ->and(Company::query()->count())->toBe(2)
        ->and($quartz?->vat_number)->toBe('IT09876543210')
        ->and($quartz?->domain)->toBe('quartzlabs.example');
});
