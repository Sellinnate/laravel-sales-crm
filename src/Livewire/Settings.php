<?php

namespace Sellinnate\SalesCrm\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Sellinnate\SalesCrm\Actions\SaveEmailTemplate;
use Sellinnate\SalesCrm\Actions\SavePipeline;
use Sellinnate\SalesCrm\Actions\SaveStage;
use Sellinnate\SalesCrm\Actions\SaveWorkspaceSettings;
use Sellinnate\SalesCrm\Enums\StageKind;
use Sellinnate\SalesCrm\Livewire\Concerns\InteractsWithCrm;
use Sellinnate\SalesCrm\Models\EmailTemplate;
use Sellinnate\SalesCrm\Models\Pipeline;
use Sellinnate\SalesCrm\Models\Stage;
use Sellinnate\SalesCrm\Models\WorkspaceSetting;
use Sellinnate\SalesCrm\Services\CustomFields;
use Sellinnate\SalesCrm\Services\DefaultPipeline;
use Sellinnate\SalesCrm\Workspaces\Workspace;

/**
 * The settings of the CRM of a workspace: pipelines and stages (with probability, kind, stale days,
 * required fields and automation), custom fields, email templates and the entry stage of the deals
 * of integrations. Everybody sees them; managers change them.
 *
 * @property-read Collection<int, Pipeline> $pipelines
 * @property-read Pipeline|null $currentPipeline
 * @property-read Collection<int, EmailTemplate> $templates
 * @property-read array<string, string> $workspaces
 */
class Settings extends Component
{
    use InteractsWithCrm;

    public const TABS = ['pipelines', 'fields', 'templates', 'general'];

    #[Url(except: 'pipelines')]
    public string $tab = 'pipelines';

    #[Url(except: '')]
    public string $workspace = '';

    #[Url(except: '')]
    public string $pipeline = '';

    public bool $showPipeline = false;

    /** @var array<string, mixed> */
    public array $pipelineForm = [];

    public ?int $editingPipeline = null;

    public bool $showStage = false;

    /** @var array<string, mixed> */
    public array $stageForm = [];

    public ?int $editingStage = null;

    /** @var list<array<string, mixed>> */
    public array $fields = [];

    public bool $showTemplate = false;

    /** @var array<string, mixed> */
    public array $templateForm = [];

    public ?int $editingTemplate = null;

    public string $entryStage = '';

    public function mount(): void
    {
        $this->viewer();

        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'pipelines';
        }

        if ($this->access()->resolver()->enabled() && $this->scope() === null) {
            $this->workspace = (string) array_key_first($this->workspaceOptions());
        }

        $this->loadWorkspace();
    }

    public function render(): View
    {
        return $this->page('sales-crm::livewire.settings', (string) __('sales-crm::ui.nav.settings'));
    }

    public function updatedWorkspace(): void
    {
        $this->pipeline = '';
        unset($this->pipelines, $this->currentPipeline, $this->templates);
        $this->loadWorkspace();
    }

    public function scope(): ?Workspace
    {
        return $this->workspace === '' ? null : $this->workspaceFromKey($this->workspace);
    }

    public function canEdit(): bool
    {
        return (! $this->access()->resolver()->enabled() || $this->scope() !== null) && $this->canManage($this->scope());
    }

    private function loadWorkspace(): void
    {
        $this->fields = array_map(fn (array $field): array => [...$field, 'options' => implode(', ', $field['options'] ?? [])], CustomFields::of($this->scope()));
        $this->entryStage = (string) (WorkspaceSetting::for($this->scope())->entry_stage_id ?? '');
    }

    // ---- Pipelines and stages -------------------------------------------------------------------

    public function editPipeline(?int $pipelineId = null): void
    {
        $pipeline = $pipelineId === null ? null : $this->pipelines->firstWhere('id', $pipelineId);
        $this->editingPipeline = $pipeline?->id;
        $this->pipelineForm = ['name' => $pipeline->name ?? '', 'is_active' => $pipeline->is_active ?? true];
        $this->resetErrorBag();
        $this->showPipeline = true;
    }

    public function savePipeline(): void
    {
        $pipeline = $this->editingPipeline === null ? null : $this->pipelines->firstWhere('id', $this->editingPipeline);

        try {
            $saved = app(SavePipeline::class)->handle($this->scope(), $this->pipelineForm, $this->viewer(), $pipeline);
        } catch (ValidationException $exception) {
            $this->showErrors($exception, 'pipelineForm.');

            return;
        }

        $this->showPipeline = false;
        $this->pipeline = (string) $saved->id;
        unset($this->pipelines, $this->currentPipeline);
        $this->notify((string) __('sales-crm::ui.saved'));
    }

    public function deletePipeline(int $pipelineId): void
    {
        $pipeline = $this->pipelines->firstWhere('id', $pipelineId);

        if ($pipeline === null) {
            return;
        }

        try {
            app(SavePipeline::class)->delete($pipeline, $this->viewer());
        } catch (ValidationException $exception) {
            $this->notify((string) collect($exception->errors())->flatten()->first(), 'danger');

            return;
        }

        $this->pipeline = '';
        unset($this->pipelines, $this->currentPipeline);
    }

    public function editStage(?int $stageId = null): void
    {
        $stage = $stageId === null ? null : $this->currentPipeline?->stages->firstWhere('id', $stageId);
        $this->editingStage = $stage?->id;
        $this->stageForm = [
            'name' => $stage->name ?? '',
            'color' => $stage?->color->value ?? 'neutral',
            'probability' => (string) ($stage->probability ?? 50),
            'kind' => $stage?->kind->value ?? 'open',
            'rotting_days' => (string) ($stage->rotting_days ?? ''),
            'required_fields' => $stage->required_fields ?? [],
            'on_enter_activity' => [
                'type' => $stage->on_enter_activity['type'] ?? 'task',
                'title' => $stage->on_enter_activity['title'] ?? '',
                'due_in_days' => (string) ($stage->on_enter_activity['due_in_days'] ?? '1'),
            ],
        ];
        $this->resetErrorBag();
        $this->showStage = true;
    }

    public function saveStage(): void
    {
        $pipeline = $this->currentPipeline;

        if ($pipeline === null) {
            return;
        }

        $stage = $this->editingStage === null ? null : $pipeline->stages->firstWhere('id', $this->editingStage);
        $data = $this->stageForm;

        if (blank($data['on_enter_activity']['title'] ?? null)) {
            $data['on_enter_activity'] = null;
        }

        try {
            app(SaveStage::class)->handle($pipeline, $data, $this->viewer(), $stage);
        } catch (ValidationException $exception) {
            $this->showErrors($exception, 'stageForm.');

            return;
        }

        $this->showStage = false;
        unset($this->currentPipeline);
        $this->notify((string) __('sales-crm::ui.saved'));
    }

    public function moveStage(int $stageId, int $direction): void
    {
        $pipeline = $this->currentPipeline;

        if ($pipeline === null) {
            return;
        }

        /** @var list<int> $ids */
        $ids = $pipeline->stages->pluck('id')->map(intval(...))->values()->all();
        $index = array_search($stageId, $ids, true);

        if (! is_int($index)) {
            return;
        }

        $target = $index + ($direction < 0 ? -1 : 1);

        if (! isset($ids[$target])) {
            return;
        }

        [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];

        try {
            app(SaveStage::class)->reorder($pipeline, array_values($ids), $this->viewer());
        } catch (ValidationException $exception) {
            $this->notify((string) collect($exception->errors())->flatten()->first(), 'danger');
        }

        unset($this->currentPipeline);
    }

    public function deleteStage(int $stageId): void
    {
        $stage = $this->currentPipeline?->stages->firstWhere('id', $stageId);

        if (! $stage instanceof Stage) {
            return;
        }

        try {
            app(SaveStage::class)->delete($stage, $this->viewer());
        } catch (ValidationException $exception) {
            $this->notify((string) collect($exception->errors())->flatten()->first(), 'danger');

            return;
        }

        unset($this->currentPipeline);
    }

    // ---- Custom fields ------------------------------------------------------------------------

    public function addField(): void
    {
        $this->fields[] = ['key' => '', 'label' => '', 'type' => 'text', 'options' => ''];
    }

    public function removeField(int $index): void
    {
        $this->fields = array_values(array_filter($this->fields, fn (int $key): bool => $key !== $index, ARRAY_FILTER_USE_KEY));
    }

    public function saveFields(): void
    {
        $this->resetErrorBag();

        try {
            app(SaveWorkspaceSettings::class)->customFields($this->scope(), $this->fields, $this->viewer());
        } catch (ValidationException $exception) {
            $this->showErrors($exception);

            return;
        }

        $this->loadWorkspace();
        $this->notify((string) __('sales-crm::ui.saved'));
    }

    // ---- Email templates -----------------------------------------------------------------------

    public function editTemplate(?int $templateId = null): void
    {
        $template = $templateId === null ? null : $this->templates->firstWhere('id', $templateId);
        $this->editingTemplate = $template?->id;
        $this->templateForm = ['name' => $template->name ?? '', 'subject' => $template->subject ?? '', 'body' => $template->body ?? ''];
        $this->resetErrorBag();
        $this->showTemplate = true;
    }

    public function saveTemplate(): void
    {
        $template = $this->editingTemplate === null ? null : $this->templates->firstWhere('id', $this->editingTemplate);

        try {
            app(SaveEmailTemplate::class)->handle($this->scope(), $this->templateForm, $this->viewer(), $template);
        } catch (ValidationException $exception) {
            $this->showErrors($exception, 'templateForm.');

            return;
        }

        $this->showTemplate = false;
        unset($this->templates);
        $this->notify((string) __('sales-crm::ui.saved'));
    }

    public function deleteTemplate(int $templateId): void
    {
        $template = $this->templates->firstWhere('id', $templateId);

        if ($template !== null) {
            app(SaveEmailTemplate::class)->delete($template, $this->viewer());
            unset($this->templates);
        }
    }

    // ---- General ----------------------------------------------------------------------------

    public function saveGeneral(): void
    {
        try {
            app(SaveWorkspaceSettings::class)->handle($this->scope(), ['entry_stage_id' => $this->entryStage === '' ? null : $this->entryStage], $this->viewer());
        } catch (ValidationException $exception) {
            $this->showErrors($exception);

            return;
        }

        $this->notify((string) __('sales-crm::ui.saved'));
    }

    // ---- Reads ------------------------------------------------------------------------------

    /**
     * @return Collection<int, Pipeline>
     */
    #[Computed]
    public function pipelines(): Collection
    {
        if ($this->access()->resolver()->enabled() && $this->scope() === null) {
            return new Collection;
        }

        app(DefaultPipeline::class)->ensureFor($this->scope(), $this->viewer());

        return Pipeline::query()->inWorkspace($this->scope())->ordered()->withCount('deals')->get();
    }

    #[Computed]
    public function currentPipeline(): ?Pipeline
    {
        $pipeline = ($this->pipeline !== '' ? $this->pipelines->firstWhere('id', (int) $this->pipeline) : null) ?? $this->pipelines->first();

        return $pipeline?->load(['stages' => fn ($query) => $query->withCount('deals')]);
    }

    /**
     * @return Collection<int, EmailTemplate>
     */
    #[Computed]
    public function templates(): Collection
    {
        return EmailTemplate::query()->inWorkspace($this->scope())->orderBy('name')->get();
    }

    /**
     * The open stages of the workspace, for the entry stage.
     *
     * @return array<int, string>
     */
    public function openStages(): array
    {
        $stages = [];

        foreach ($this->pipelines as $pipeline) {
            foreach ($pipeline->stages()->where('kind', StageKind::Open->value)->get() as $stage) {
                $stages[$stage->id] = $pipeline->name.' · '.$stage->name;
            }
        }

        return $stages;
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function workspaces(): array
    {
        return $this->workspaceOptions();
    }
}
