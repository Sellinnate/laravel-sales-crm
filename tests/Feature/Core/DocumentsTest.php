<?php

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Sellinnate\SalesCrm\Actions\ManageDocuments;
use Sellinnate\SalesCrm\Actions\UpdateDeal;
use Sellinnate\SalesCrm\Facades\SalesCrm;
use Sellinnate\SalesCrm\Models\Document;
use Sellinnate\SalesCrm\Support\Crm;

beforeEach(function () {
    Storage::fake('local');
    $this->seller = crmUser();
    $this->admin = crmUser(['is_admin' => true]);
    $this->deal = SalesCrm::shared()->createDeal(['title' => 'Website'], $this->seller);
    $this->documents = app(ManageDocuments::class);
});

it('uploads versioned quotes whose validity becomes the validity of the offer', function () {
    $first = $this->documents->upload($this->deal, UploadedFile::fake()->create('quote.pdf', 100, 'application/pdf'), ['kind' => 'quote', 'valid_until' => '2030-01-31'], $this->seller);
    $second = $this->documents->upload($this->deal, UploadedFile::fake()->create('quote-v2.pdf', 100, 'application/pdf'), ['kind' => 'quote', 'valid_until' => '2030-02-28'], $this->seller);

    expect($first->version)->toBe(1)
        ->and($second->version)->toBe(2)
        ->and($this->deal->fresh()->offer_valid_until->toDateString())->toBe('2030-02-28');
    Storage::disk('local')->assertExists($second->path);
});

it('refuses files of the wrong type or too big', function () {
    config(['sales-crm.documents.max_kilobytes' => 10]);

    expect(fn () => $this->documents->upload($this->deal, UploadedFile::fake()->create('virus.exe', 1), ['kind' => 'other'], $this->seller))->toThrow(ValidationException::class)
        ->and(fn () => $this->documents->upload($this->deal, UploadedFile::fake()->create('big.pdf', 50, 'application/pdf'), ['kind' => 'other'], $this->seller))->toThrow(ValidationException::class);
});

it('keeps the only evidence of a signature until another one exists', function () {
    $contract = $this->documents->upload($this->deal, UploadedFile::fake()->create('contract.pdf', 10, 'application/pdf'), ['kind' => 'contract'], $this->seller);
    app(UpdateDeal::class)->handle($this->deal, ['signature_status' => 'signed'], $this->seller);

    expect(fn () => $this->documents->delete($contract, $this->seller))->toThrow(ValidationException::class);

    $this->documents->upload($this->deal, UploadedFile::fake()->create('signed.pdf', 10, 'application/pdf'), ['kind' => 'signature_evidence'], $this->seller);
    $this->documents->delete($contract, $this->seller);

    expect(Document::query()->find($contract->id))->toBeNull();
    Storage::disk('local')->assertMissing($contract->path);
});

it('lets who uploaded a document, or a manager, delete it', function () {
    $document = $this->documents->upload($this->deal, UploadedFile::fake()->create('notes.txt', 1, 'text/plain'), ['kind' => 'other'], $this->seller);

    expect(fn () => $this->documents->delete($document, crmUser()))->toThrow(AuthorizationException::class);

    $this->documents->delete($document, $this->admin);
    expect(Document::query()->count())->toBe(0);
});

it('downloads a document only through the CRM, and a quote with a signed link', function () {
    $quote = $this->documents->upload($this->deal, UploadedFile::fake()->create('quote.pdf', 10, 'application/pdf'), ['kind' => 'quote'], $this->seller);
    $other = $this->documents->upload($this->deal, UploadedFile::fake()->create('other.pdf', 10, 'application/pdf'), ['kind' => 'other'], $this->seller);

    $this->get(route('sales-crm.documents.download', $quote))->assertRedirect();
    $this->actingAs($this->seller)->get(route('sales-crm.documents.download', $quote))->assertOk()->assertDownload('quote.pdf');

    $this->get(URL::temporarySignedRoute('sales-crm.documents.signed', CarbonImmutable::now()->addDay(), ['document' => $quote->id]))->assertOk();
    $this->get(URL::temporarySignedRoute('sales-crm.documents.signed', CarbonImmutable::now()->addDay(), ['document' => $other->id]))->assertNotFound();
    $this->get(route('sales-crm.documents.signed', ['document' => $quote->id]))->assertForbidden();
});

it('keeps the file of a document whose deletion the host rolls back', function () {
    $document = $this->documents->upload($this->deal, UploadedFile::fake()->create('notes.txt', 1, 'text/plain'), ['kind' => 'other'], $this->seller);

    try {
        Crm::db()->transaction(function () use ($document): void {
            $this->documents->delete($document, $this->seller);

            throw new RuntimeException('The host changes its mind.');
        });
    } catch (RuntimeException) {
    }

    expect(Document::query()->find($document->id))->not->toBeNull();
    Storage::disk('local')->assertExists($document->path);
});
