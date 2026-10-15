<?php

namespace Sellinnate\SalesCrm\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Sellinnate\SalesCrm\Enums\DocumentKind;
use Sellinnate\SalesCrm\Events\DocumentUploaded;
use Sellinnate\SalesCrm\Models\Company;
use Sellinnate\SalesCrm\Models\Deal;
use Sellinnate\SalesCrm\Models\Document;
use Sellinnate\SalesCrm\Services\DealActivityState;
use Sellinnate\SalesCrm\Services\DealFields;
use Sellinnate\SalesCrm\Support\Crm;
use Throwable;

/**
 * The documents of the CRM: upload a quote, a contract or an evidence on a deal or a company, with its
 * date and, for a quote, a version and a validity that becomes the validity of the offer of the deal;
 * delete one (who uploaded it, or a manager). Files live on the private disk of
 * `sales-crm.documents.disk` and are downloaded only through the CRM.
 */
class ManageDocuments
{
    public function __construct(private UpdateDeal $updateDeal, private DealActivityState $state, private DealFields $fields) {}

    /**
     * @param  array{kind?: string|null, document_date?: string|null, valid_until?: string|null}  $data
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function upload(Deal|Company $target, UploadedFile $file, array $data, Authenticatable $by): Document
    {
        Gate::forUser($by)->authorize('update', $target);

        $data = array_map(fn (mixed $value): mixed => is_string($value) && trim($value) === '' ? null : $value, $data);
        $mimes = config('sales-crm.documents.mimes', []);

        /** @var array{kind: string, document_date?: string|null, valid_until?: string|null} $validated */
        $validated = Validator::make(['file' => $file, ...$data], [
            'file' => ['required', 'file', 'max:'.max(1, (int) config('sales-crm.documents.max_kilobytes', 20480)), 'mimes:'.implode(',', is_array($mimes) ? $mimes : [])],
            'kind' => ['required', Rule::enum(DocumentKind::class)],
            'document_date' => ['nullable', 'date_format:Y-m-d'],
            'valid_until' => ['nullable', 'date_format:Y-m-d'],
        ], [], [
            'file' => mb_strtolower((string) __('sales-crm::fields.document.file')),
            'kind' => mb_strtolower((string) __('sales-crm::fields.document.kind')),
            'document_date' => mb_strtolower((string) __('sales-crm::fields.document.document_date')),
            'valid_until' => mb_strtolower((string) __('sales-crm::fields.document.valid_until')),
        ])->validate();

        $kind = DocumentKind::from($validated['kind']);
        $disk = (string) config('sales-crm.documents.disk', 'local');
        $directory = trim((string) config('sales-crm.documents.directory', 'sales-crm'), '/').'/'.Str::slug($target->workspaceRef()?->key() ?? 'shared');
        $path = $file->storeAs($directory, Str::uuid()->toString().'.'.Str::lower($file->getClientOriginalExtension() ?: 'bin'), ['disk' => $disk]);

        if ($path === false) {
            throw new RuntimeException('sales-crm: the document could not be stored on the disk.');
        }

        try {
            return Crm::db()->transaction(function () use ($target, $file, $validated, $kind, $path, $disk, $by): Document {
                // The file goes also when a transaction around this one (a new deal, a move) rolls back.
                Crm::afterRollBack(fn () => self::remove($disk, $path, null));

                // The row of the deal, locked as its changes lock it, gives one version per quote.
                if ($target instanceof Deal) {
                    Deal::query()->whereKey($target->id)->lockForUpdate()->first();
                }

                $version = $target instanceof Deal && $kind === DocumentKind::Quote
                    ? (int) Document::query()->where('deal_id', $target->id)->where('kind', $kind->value)->max('version') + 1
                    : 1;

                $document = (new Document([
                    'deal_id' => $target instanceof Deal ? $target->id : null,
                    'company_id' => $target instanceof Company ? $target->id : null,
                    'kind' => $kind,
                    'name' => mb_substr($file->getClientOriginalName(), 0, 255),
                    'disk' => $disk,
                    'path' => $path,
                    'mime' => $file->getMimeType(),
                    'size' => (int) $file->getSize(),
                    'version' => $version,
                    'document_date' => $validated['document_date'] ?? null,
                    'valid_until' => $kind === DocumentKind::Quote ? ($validated['valid_until'] ?? null) : null,
                    'uploaded_by' => Crm::userKey($by),
                ]))->setWorkspace($target->workspaceRef());
                $document->save();

                if ($target instanceof Deal) {
                    // The latest quote with a validity sets the validity of the offer.
                    if ($document->valid_until !== null) {
                        $this->updateDeal->handle($target, ['offer_valid_until' => $document->valid_until->toDateString()], $by);
                    }

                    $this->state->refresh($target->id, CarbonImmutable::now());
                }

                DocumentUploaded::dispatch($document, $by);

                return $document;
            });
        } catch (Throwable $exception) {
            self::remove($disk, $path, null);

            throw $exception;
        }
    }

    /**
     * Delete a document; the only evidence of the signature or of the payment of its deal stays until
     * the deal has another one or its status changes.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function delete(Document $document, Authenticatable $by): void
    {
        Gate::forUser($by)->authorize('delete', $document);

        Crm::db()->transaction(function () use ($document): void {
            $deal = $document->deal_id !== null ? Deal::query()->lockForUpdate()->find($document->deal_id) : null;
            $lost = $deal !== null ? array_diff_key($this->fields->evidenceErrors($deal, without: $document->id), $this->fields->evidenceErrors($deal)) : [];

            if ($lost !== []) {
                throw ValidationException::withMessages(['document' => __(isset($lost['signature_evidence']) ? 'sales-crm::validation.last_signature_evidence' : 'sales-crm::validation.last_payment_evidence')]);
            }

            $document->delete();
        });

        self::remove($document->disk, $document->path, $document->id);
    }

    /**
     * The stored files of a deal or a company being deleted go once the deletion commits; their rows go
     * with the cascade of the database.
     */
    public function purgeWith(Deal|Company $target): void
    {
        $documents = Document::query()->where($target instanceof Deal ? 'deal_id' : 'company_id', $target->id)->get(['id', 'disk', 'path']);

        Crm::db()->afterCommit(function () use ($documents): void {
            foreach ($documents as $document) {
                self::remove($document->disk, $document->path, $document->id);
            }
        });
    }

    /**
     * Delete a stored file: a disk that answers false or throws is logged, never an error for a
     * deletion already committed.
     */
    private static function remove(string $disk, string $path, ?int $documentId): void
    {
        try {
            $deleted = Storage::disk($disk)->delete($path);
        } catch (Throwable $exception) {
            report($exception);
            $deleted = false;
        }

        if (! $deleted) {
            Log::warning('sales-crm: document file not deleted from the disk', ['document_id' => $documentId, 'disk' => $disk, 'path' => $path]);
        }
    }
}
