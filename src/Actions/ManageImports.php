<?php

namespace Sellinnate\SalesCrm\Actions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Sellinnate\SalesCrm\Enums\ImportStatus;
use Sellinnate\SalesCrm\Exceptions\ImportException;
use Sellinnate\SalesCrm\Jobs\RunImport;
use Sellinnate\SalesCrm\Models\Import;
use Sellinnate\SalesCrm\Models\Pipeline;
use Sellinnate\SalesCrm\Services\Import\ImportFields;
use Sellinnate\SalesCrm\Services\Import\SpreadsheetReader;
use Sellinnate\SalesCrm\Support\Crm;
use Sellinnate\SalesCrm\Workspaces\Workspace;

/**
 * The steps of an import of deals, for managers: upload the file (and choose the sheet of an XLSX),
 * map its columns and the values of its stage column, then run it in the queue.
 */
class ManageImports
{
    /**
     * Store the file of a new import into a pipeline of the workspace.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function upload(Model|Workspace|null $workspace, Pipeline $pipeline, UploadedFile $file, Authenticatable $by): Import
    {
        $workspace = Workspace::of($workspace);
        Gate::forUser($by)->authorize('create', [Pipeline::class, $workspace]);

        if (! $pipeline->inWorkspace($workspace)) {
            throw ValidationException::withMessages(['pipeline_id' => __('sales-crm::validation.pipeline_workspace')]);
        }

        Validator::make(['file' => $file], [
            'file' => ['required', 'file', 'max:'.max(1, (int) config('sales-crm.import.max_kilobytes', 10240)), 'extensions:'.implode(',', SpreadsheetReader::extensions())],
        ], [
            'file.extensions' => __('sales-crm::validation.import_extensions', ['extensions' => implode(', ', SpreadsheetReader::extensions())]),
        ], ['file' => mb_strtolower((string) __('sales-crm::fields.document.file'))])->validate();

        $disk = (string) config('sales-crm.import.disk', 'local');
        $extension = Str::lower($file->getClientOriginalExtension() ?: 'csv');
        $path = $file->storeAs(trim((string) config('sales-crm.import.directory', 'sales-crm/imports'), '/'), Str::uuid()->toString().'.'.$extension, ['disk' => $disk]);

        if ($path === false) {
            throw ValidationException::withMessages(['file' => __('sales-crm::validation.import_unreadable')]);
        }

        $import = (new Import([
            'user_id' => Crm::userKey($by),
            'pipeline_id' => $pipeline->id,
            'original_filename' => mb_substr($file->getClientOriginalName(), 0, 255),
            'disk' => $disk,
            'stored_path' => $path,
            'status' => ImportStatus::Uploaded,
        ]))->setWorkspace($workspace);

        try {
            $sheets = (new SpreadsheetReader($disk, $path))->sheets();
        } catch (ImportException $exception) {
            // Not a spreadsheet after all (a renamed or damaged file): an error of the form, no file left behind.
            Storage::disk($disk)->delete($path);

            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        }

        $import->sheet = $sheets[0] ?? null;
        $import->save();

        return $import;
    }

    /**
     * Save the mapping: the field of each column, the stage of each value of the stage column, the
     * default stage, the sheet and whether dates are month first.
     *
     * @param  array{sheet?: string|null, columns?: array<int|string, string|null>, stages?: array<string, int|string|null>, default_stage_id?: int|string|null, month_first?: bool}  $mapping
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function map(Import $import, array $mapping, Authenticatable $by): Import
    {
        Gate::forUser($by)->authorize('create', [Pipeline::class, $import->workspaceRef()]);

        if (! in_array($import->status, [ImportStatus::Uploaded, ImportStatus::Mapped], true)) {
            throw ValidationException::withMessages(['import' => __('sales-crm::validation.import_started')]);
        }

        $fields = array_keys(ImportFields::all($import->workspaceRef()));
        $columns = [];

        foreach ($mapping['columns'] ?? [] as $index => $field) {
            if (is_string($field) && in_array($field, $fields, true)) {
                $columns[(int) $index] = $field;
            }
        }

        if ($columns === []) {
            throw ValidationException::withMessages(['columns' => __('sales-crm::validation.import_no_columns')]);
        }

        $stageIds = $import->pipeline?->stages()->pluck('id')->map(intval(...))->all() ?? [];
        $stages = [];

        foreach ($mapping['stages'] ?? [] as $value => $stageId) {
            if (is_numeric($stageId) && in_array((int) $stageId, $stageIds, true)) {
                $stages[(string) $value] = (int) $stageId;
            }
        }

        $default = isset($mapping['default_stage_id']) && is_numeric($mapping['default_stage_id']) && in_array((int) $mapping['default_stage_id'], $stageIds, true)
            ? (int) $mapping['default_stage_id']
            : null;

        $sheet = $mapping['sheet'] ?? $import->sheet;
        $sheets = (new SpreadsheetReader($import->disk, $import->stored_path))->sheets();

        if ($sheets !== [] && ! in_array($sheet, $sheets, true)) {
            throw ValidationException::withMessages(['sheet' => __('sales-crm::validation.import_sheet')]);
        }

        $import->forceFill([
            'sheet' => $sheets === [] ? null : $sheet,
            'mapping' => ['columns' => $columns, 'stages' => $stages, 'default_stage_id' => $default, 'month_first' => (bool) ($mapping['month_first'] ?? false)],
            'status' => ImportStatus::Mapped,
        ])->save();

        return $import;
    }

    /**
     * Queue the import of a mapped file.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function run(Import $import, Authenticatable $by): Import
    {
        Gate::forUser($by)->authorize('create', [Pipeline::class, $import->workspaceRef()]);

        // From "ready" to "queued" in one conditional write: two clicks start it once, and from now on
        // its mapping and its sheet stay as the job will read them.
        if (Import::query()->whereKey($import->id)->where('status', ImportStatus::Mapped->value)->update(['status' => ImportStatus::Queued->value]) === 0) {
            throw ValidationException::withMessages(['import' => __('sales-crm::validation.import_not_mapped')]);
        }

        $import->status = ImportStatus::Queued;
        $import->syncOriginalAttribute('status');
        RunImport::dispatch($import->id, Crm::userKey($by));

        return $import;
    }

    /**
     * Read another sheet of the file: its columns are mapped again.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function sheet(Import $import, string $sheet, Authenticatable $by): Import
    {
        Gate::forUser($by)->authorize('create', [Pipeline::class, $import->workspaceRef()]);

        if (! in_array($import->status, [ImportStatus::Uploaded, ImportStatus::Mapped], true)) {
            throw ValidationException::withMessages(['import' => __('sales-crm::validation.import_started')]);
        }

        if (! in_array($sheet, (new SpreadsheetReader($import->disk, $import->stored_path))->sheets(), true)) {
            throw ValidationException::withMessages(['sheet' => __('sales-crm::validation.import_sheet')]);
        }

        $import->forceFill(['sheet' => $sheet, 'mapping' => null, 'status' => ImportStatus::Uploaded])->save();

        return $import;
    }
}
