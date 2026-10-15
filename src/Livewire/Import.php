<?php

namespace Sellinnate\SalesCrm\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Sellinnate\SalesCrm\Actions\ManageImports;
use Sellinnate\SalesCrm\Enums\ImportStatus;
use Sellinnate\SalesCrm\Exceptions\ImportException;
use Sellinnate\SalesCrm\Livewire\Concerns\InteractsWithCrm;
use Sellinnate\SalesCrm\Models\Import as ImportModel;
use Sellinnate\SalesCrm\Models\Pipeline;
use Sellinnate\SalesCrm\Services\DefaultPipeline;
use Sellinnate\SalesCrm\Services\Import\Importer;
use Sellinnate\SalesCrm\Services\Import\ImportFields;
use Sellinnate\SalesCrm\Services\Import\SpreadsheetReader;

/**
 * The guided import of deals from a CSV or XLSX file: upload and sheet, mapping of the columns
 * (suggested from the headers), stage of each value of the stage column, preview of the first rows,
 * run in the queue and report with the rejected rows.
 *
 * @property-read Collection<int, Pipeline> $pipelines
 * @property-read Collection<int, ImportModel> $recent
 * @property-read list<array{row: int, title: string, action: string, reason: string|null, warnings: list<string>}> $outcomes
 */
class Import extends Component
{
    use InteractsWithCrm;
    use WithFileUploads;

    #[Url(as: 'import', except: null)]
    public ?int $importId = null;

    public string $workspace = '';

    public string $pipelineId = '';

    /** @var TemporaryUploadedFile|null */
    public $upload = null;

    public string $sheet = '';

    /** @var array<int|string, string> */
    public array $columns = [];

    /**
     * The stage of each value of the stage column, as a list: a value is never a binding path
     * ("Closed.Won", "Prop. inviata" would be read as nested keys). Rows of `value` and `stage`, read
     * defensively: the browser sends them back.
     *
     * @var array<int|string, mixed>
     */
    public array $stages = [];

    public string $defaultStage = '';

    public bool $monthFirst = false;

    public function mount(): void
    {
        $this->viewer();
        abort_unless($this->managesAny(), 403);

        $workspaces = $this->manageableWorkspaces();
        $this->workspace = array_key_first($workspaces) ?? '';

        if ($this->importId !== null) {
            $import = ImportModel::query()->find($this->importId);

            if ($import === null || ! Gate::forUser($this->viewer())->allows('create', [Pipeline::class, $import->workspaceRef()])) {
                $this->importId = null;
            } else {
                $this->loadMapping($import);
            }
        }
    }

    public function render(): View
    {
        return $this->page('sales-crm::livewire.import', (string) __('sales-crm::ui.nav.import'));
    }

    public function import(): ?ImportModel
    {
        $import = $this->importId === null ? null : ImportModel::query()->with('pipeline.stages')->find($this->importId);

        // On every request: the id comes from the URL and from the browser.
        return $import !== null && Gate::forUser($this->viewer())->allows('create', [Pipeline::class, $import->workspaceRef()]) ? $import : null;
    }

    public function uploadFile(): void
    {
        $this->resetErrorBag();
        $workspace = $this->workspaceFromKey($this->workspace);
        $pipeline = $this->pipelines->firstWhere('id', (int) $this->pipelineId) ?? $this->pipelines->first();

        if (! $this->upload instanceof TemporaryUploadedFile || $pipeline === null) {
            $this->addError('upload', (string) __('sales-crm::ui.choose_file'));

            return;
        }

        try {
            $import = app(ManageImports::class)->upload($workspace, $pipeline, $this->upload, $this->viewer());
        } catch (ValidationException $exception) {
            $this->showErrors($exception, 'upload.');

            return;
        }

        $this->upload = null;
        $this->importId = $import->id;
        $this->loadMapping($import);
    }

    private function loadMapping(ImportModel $import): void
    {
        $this->sheet = (string) $import->sheet;
        $this->monthFirst = (bool) ($import->mapping['month_first'] ?? false);
        $this->defaultStage = (string) ($import->mapping['default_stage_id'] ?? $import->pipeline?->firstOpenStage()->id ?? '');

        try {
            $headers = app(Importer::class)->reader($import)->preview(1)['headers'];
        } catch (ImportException $exception) {
            $this->addError('upload.file', $exception->getMessage());
            $headers = [];
        }

        $saved = $import->mapping['columns'] ?? null;
        $suggested = ImportFields::suggest($headers, $import->workspaceRef());
        $this->columns = [];

        foreach (array_keys($headers) as $index) {
            $this->columns[(string) $index] = (string) ($saved[$index] ?? ($saved === null ? ($suggested[$index] ?? '') : ''));
        }

        $this->stages = [];

        foreach ((array) ($import->mapping['stages'] ?? []) as $value => $stage) {
            $this->stages[] = ['value' => (string) $value, 'stage' => (string) $stage];
        }
        $this->suggestStageValues();
    }

    public function updatedColumns(): void
    {
        $this->suggestStageValues();
    }

    public function updatedSheet(): void
    {
        $import = $this->import();

        if ($import === null) {
            return;
        }

        try {
            $import = app(ManageImports::class)->sheet($import, $this->sheet, $this->viewer());
        } catch (ValidationException $exception) {
            $this->showErrors($exception, 'mapping.');
            $this->sheet = (string) $import->sheet;

            return;
        }

        $this->loadMapping($import);
    }

    private function suggestStageValues(): void
    {
        $import = $this->import();
        $column = array_search('stage', $this->columns, true);

        if ($import === null || $column === false || $import->pipeline === null) {
            return;
        }

        $values = app(Importer::class)->values($import, (int) $column);
        $suggested = Importer::suggestStages($values, $import->pipeline->stages);
        $chosen = $this->stageMap();
        $stages = [];

        foreach ($values as $value) {
            $stages[] = ['value' => (string) $value, 'stage' => (string) ($chosen[$value] ?? $suggested[$value] ?? '')];
        }

        $this->stages = $stages;
    }

    public function saveMapping(): void
    {
        $import = $this->import();

        if ($import === null) {
            return;
        }

        $this->resetErrorBag();

        try {
            app(ManageImports::class)->map($import, [
                'sheet' => $this->sheet === '' ? null : $this->sheet,
                'columns' => array_filter($this->columns, fn (string $field): bool => $field !== ''),
                'stages' => array_filter($this->stageMap(), fn (string $stage): bool => $stage !== ''),
                'default_stage_id' => $this->defaultStage === '' ? null : $this->defaultStage,
                'month_first' => $this->monthFirst,
            ], $this->viewer());
        } catch (ValidationException $exception) {
            $this->showErrors($exception, 'mapping.');

            return;
        }

        unset($this->outcomes);
    }

    public function run(): void
    {
        $import = $this->import();

        if ($import === null) {
            return;
        }

        $this->saveMapping();

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        app(ManageImports::class)->run($import->fresh() ?? $import, $this->viewer());
        $this->notify((string) __('sales-crm::ui.import.started'));
    }

    public function restart(): void
    {
        $this->reset('importId', 'columns', 'stages', 'defaultStage', 'sheet', 'monthFirst', 'upload');
    }

    /**
     * @return list<array{row: int, title: string, action: string, reason: string|null, warnings: list<string>}>
     */
    #[Computed]
    public function outcomes(): array
    {
        $import = $this->import();

        if ($import === null || $import->status !== ImportStatus::Mapped) {
            return [];
        }

        try {
            return app(Importer::class)->outcomes($import);
        } catch (ImportException) {
            return [];
        }
    }

    /**
     * @return array<string, string>
     */
    public function manageableWorkspaces(): array
    {
        if (! $this->access()->resolver()->enabled()) {
            return [];
        }

        return array_filter($this->workspaceOptions(), fn (string $label, string $key): bool => $this->canManage($this->workspaceFromKey($key)), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * @return Collection<int, Pipeline>
     */
    #[Computed]
    public function pipelines(): Collection
    {
        $workspace = $this->workspaceFromKey($this->workspace);

        // The workspace comes from the browser: nothing read, nothing created where the user doesn't manage.
        if (($workspace === null && $this->access()->resolver()->enabled()) || ! $this->canManage($workspace)) {
            return new Collection;
        }

        app(DefaultPipeline::class)->ensureFor($workspace, $this->viewer());

        return Pipeline::query()->inWorkspace($workspace)->ordered()->get();
    }

    /**
     * @return list<string>
     */
    public function sheets(): array
    {
        $import = $this->import();

        try {
            return $import === null ? [] : (new SpreadsheetReader($import->disk, $import->stored_path))->sheets();
        } catch (ImportException) {
            return [];
        }
    }

    /**
     * @return list<string>
     */
    public function headers(): array
    {
        $import = $this->import();

        try {
            return $import === null ? [] : app(Importer::class)->reader($import)->preview(1)['headers'];
        } catch (ImportException) {
            return [];
        }
    }

    /**
     * @return Collection<int, ImportModel>
     */
    #[Computed]
    public function recent(): Collection
    {
        // Only the imports the user may open: those of the workspaces they manage.
        return ImportModel::query()->visibleTo($this->viewer())->latest('id')->limit(50)->get()
            ->filter(fn (ImportModel $import): bool => $this->canManage($import->workspaceRef()))
            ->take(10)
            ->values();
    }

    /**
     * The stages chosen, by value of the stage column.
     *
     * @return array<string, string>
     */
    private function stageMap(): array
    {
        $map = [];

        foreach ($this->stages as $row) {
            if (is_array($row) && is_scalar($row['value'] ?? null)) {
                $map[(string) $row['value']] = is_scalar($row['stage'] ?? null) ? (string) $row['stage'] : '';
            }
        }

        return $map;
    }
}
