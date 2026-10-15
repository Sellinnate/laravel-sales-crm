@php
    use Sellinnate\SalesCrm\Enums\ActivityType;
    use Sellinnate\SalesCrm\Enums\RequiredField;
    use Sellinnate\SalesCrm\Enums\StageColor;
    use Sellinnate\SalesCrm\Enums\StageKind;
    use Sellinnate\SalesCrm\Services\CustomFields;
    use Sellinnate\SalesCrm\Services\EmailTemplates;

    $canEdit = $this->canEdit();
    $pipeline = $this->currentPipeline;
@endphp
<div>
    <x-sales-crm::shell :title="__('sales-crm::ui.nav.settings')">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <div class="crm-tabs" role="tablist">
                @foreach (\Sellinnate\SalesCrm\Livewire\Settings::TABS as $key)
                    <button type="button" class="crm-tab" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" wire:click="$set('tab', '{{ $key }}')" data-test="settings-tab-{{ $key }}">{{ __('sales-crm::ui.settings.tab_'.$key) }}</button>
                @endforeach
            </div>
            @if ($this->workspaces !== [])
                <select class="crm-select w-56" wire:model.live="workspace" aria-label="{{ __('sales-crm::ui.workspace') }}">
                    @foreach ($this->workspaces as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                </select>
            @endif
        </div>

        @unless ($canEdit)
            <p class="mb-3 rounded-md border border-line bg-sunken px-3 py-2 text-[13px] text-muted" data-test="settings-readonly">{{ __('sales-crm::ui.settings.readonly') }}</p>
        @endunless

        @if ($tab === 'pipelines')
            <div class="grid gap-4 lg:grid-cols-[280px_1fr]" data-test="settings-pipelines">
                <section class="crm-card h-fit">
                    <div class="flex items-center justify-between border-b border-line px-4 py-2.5">
                        <h2 class="text-[13px] font-semibold">{{ __('sales-crm::ui.settings.pipelines') }}</h2>
                        @if ($canEdit)<button type="button" class="crm-btn crm-btn-sm" wire:click="editPipeline" data-test="pipeline-new"><x-sales-crm::icon name="plus" :size="12" /> {{ __('sales-crm::ui.add') }}</button>@endif
                    </div>
                    @foreach ($this->pipelines as $item)
                        <div @class(['flex items-center gap-2 border-b border-line px-4 py-2 last:border-0', 'bg-brand-soft/40' => $pipeline?->id === $item->id]) wire:key="settings-pipeline-{{ $item->id }}">
                            <button type="button" class="min-w-0 flex-auto truncate text-left text-[13px] font-medium" wire:click="$set('pipeline', '{{ $item->id }}')">{{ $item->name }}@unless ($item->is_active) <span class="text-xs text-muted">({{ __('sales-crm::ui.inactive') }})</span>@endunless</button>
                            <span class="crm-count">{{ $item->deals_count }}</span>
                            @if ($canEdit)
                                <button type="button" class="crm-btn-icon" wire:click="editPipeline({{ $item->id }})" aria-label="{{ __('sales-crm::ui.edit') }}"><x-sales-crm::icon name="pencil" :size="13" /></button>
                                <button type="button" class="crm-btn-icon hover:text-danger" wire:click="deletePipeline({{ $item->id }})" wire:confirm="{{ __('sales-crm::ui.confirm_delete') }}" aria-label="{{ __('sales-crm::ui.delete') }}"><x-sales-crm::icon name="trash" :size="13" /></button>
                            @endif
                        </div>
                    @endforeach
                </section>

                @if ($pipeline)
                    <section class="crm-card" data-test="settings-stages">
                        <div class="flex items-center justify-between border-b border-line px-4 py-2.5">
                            <h2 class="text-[13px] font-semibold">{{ __('sales-crm::ui.settings.stages_of', ['pipeline' => $pipeline->name]) }}</h2>
                            @if ($canEdit)<button type="button" class="crm-btn crm-btn-sm" wire:click="editStage" data-test="stage-new"><x-sales-crm::icon name="plus" :size="12" /> {{ __('sales-crm::ui.settings.new_stage') }}</button>@endif
                        </div>
                        <table class="crm-table">
                            <thead><tr><th>{{ __('sales-crm::fields.stage.name') }}</th><th>{{ __('sales-crm::fields.stage.kind') }}</th><th class="text-right">{{ __('sales-crm::fields.stage.probability') }}</th><th>{{ __('sales-crm::fields.stage.rotting_days') }}</th><th>{{ __('sales-crm::fields.stage.required_fields') }}</th><th></th></tr></thead>
                            <tbody>
                                @foreach ($pipeline->stages as $stage)
                                    <tr wire:key="settings-stage-{{ $stage->id }}" data-test="settings-stage-row">
                                        <td><span class="crm-dot {{ $stage->color->dotClass() }}"></span> <span class="font-medium">{{ $stage->name }}</span> <span class="crm-count">{{ $stage->deals_count }}</span>@if ($stage->on_enter_activity)<span class="block text-xs text-muted"><x-sales-crm::icon name="zap" :size="11" /> {{ $stage->on_enter_activity['title'] ?? '' }}</span>@endif</td>
                                        <td><x-sales-crm::badge :tone="$stage->kind->tone()">{{ $stage->kind->label() }}</x-sales-crm::badge></td>
                                        <td class="crm-num text-right">{{ $stage->probability }}%</td>
                                        <td class="text-xs">{{ $stage->rotting_days ?? '—' }}</td>
                                        <td class="text-xs">{{ collect($stage->requiredFields())->map->label()->implode(', ') ?: '—' }}</td>
                                        <td class="whitespace-nowrap text-right">
                                            @if ($canEdit)
                                                <button type="button" class="crm-btn-icon" wire:click="moveStage({{ $stage->id }}, -1)" aria-label="{{ __('sales-crm::ui.move_up') }}">↑</button>
                                                <button type="button" class="crm-btn-icon" wire:click="moveStage({{ $stage->id }}, 1)" aria-label="{{ __('sales-crm::ui.move_down') }}">↓</button>
                                                <button type="button" class="crm-btn-icon" wire:click="editStage({{ $stage->id }})" aria-label="{{ __('sales-crm::ui.edit') }}" data-test="stage-edit-{{ $stage->id }}"><x-sales-crm::icon name="pencil" :size="13" /></button>
                                                <button type="button" class="crm-btn-icon hover:text-danger" wire:click="deleteStage({{ $stage->id }})" wire:confirm="{{ __('sales-crm::ui.confirm_delete') }}" aria-label="{{ __('sales-crm::ui.delete') }}"><x-sales-crm::icon name="trash" :size="13" /></button>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </section>
                @endif
            </div>
        @elseif ($tab === 'fields')
            <form wire:submit="saveFields" class="crm-card crm-card-body flex max-w-4xl flex-col gap-3" data-test="settings-fields">
                <p class="text-[13px] text-muted">{{ __('sales-crm::ui.settings.fields_hint', ['max' => CustomFields::limit()]) }}</p>
                @foreach ($fields as $index => $field)
                    <div class="grid gap-2 rounded-md border border-line p-3 sm:grid-cols-[1fr_1fr_140px_1fr_auto]" wire:key="custom-field-{{ $index }}">
                        <x-sales-crm::field :label="__('sales-crm::fields.custom.label')" :error="'fields.'.$index.'.label'"><input type="text" class="crm-input" wire:model="fields.{{ $index }}.label" @disabled(! $canEdit) data-test="custom-field-label-{{ $index }}"></x-sales-crm::field>
                        <x-sales-crm::field :label="__('sales-crm::fields.custom.key')" :error="'fields.'.$index.'.key'"><input type="text" class="crm-input font-mono" wire:model="fields.{{ $index }}.key" @disabled(! $canEdit) data-test="custom-field-key-{{ $index }}"></x-sales-crm::field>
                        <x-sales-crm::field :label="__('sales-crm::fields.custom.type')">
                            <select class="crm-select" wire:model.live="fields.{{ $index }}.type" @disabled(! $canEdit)>
                                @foreach (CustomFields::TYPES as $type)<option value="{{ $type }}">{{ __('sales-crm::ui.settings.type_'.$type) }}</option>@endforeach
                            </select>
                        </x-sales-crm::field>
                        <x-sales-crm::field :label="__('sales-crm::fields.custom.options')" :error="'fields.'.$index.'.options'">
                            <input type="text" class="crm-input" wire:model="fields.{{ $index }}.options" placeholder="{{ __('sales-crm::ui.settings.options_placeholder') }}" @disabled(! $canEdit || ($field['type'] ?? 'text') !== 'choice')>
                        </x-sales-crm::field>
                        @if ($canEdit)<button type="button" class="crm-btn-icon mt-5 hover:text-danger" wire:click="removeField({{ $index }})" aria-label="{{ __('sales-crm::ui.remove') }}"><x-sales-crm::icon name="trash" :size="13" /></button>@endif
                    </div>
                @endforeach
                @error('fields') <p class="crm-error">{{ $message }}</p> @enderror
                @if ($canEdit)
                    <div class="flex justify-between">
                        <button type="button" class="crm-btn" wire:click="addField" data-test="custom-field-add"><x-sales-crm::icon name="plus" :size="14" /> {{ __('sales-crm::ui.settings.add_field') }}</button>
                        <button type="submit" class="crm-btn crm-btn-primary" data-test="custom-fields-save">{{ __('sales-crm::ui.save') }}</button>
                    </div>
                @endif
            </form>
        @elseif ($tab === 'templates')
            <section class="crm-card max-w-4xl" data-test="settings-templates">
                <div class="flex items-center justify-between border-b border-line px-4 py-2.5">
                    <h2 class="text-[13px] font-semibold">{{ __('sales-crm::ui.settings.templates') }}</h2>
                    @if ($canEdit)<button type="button" class="crm-btn crm-btn-sm" wire:click="editTemplate" data-test="template-new"><x-sales-crm::icon name="plus" :size="12" /> {{ __('sales-crm::ui.add') }}</button>@endif
                </div>
                @forelse ($this->templates as $template)
                    <div class="flex items-center gap-2 border-b border-line px-4 py-2 last:border-0" wire:key="template-{{ $template->id }}">
                        <div class="min-w-0 flex-auto"><p class="text-[13px] font-medium">{{ $template->name }}</p><p class="truncate text-xs text-muted">{{ $template->subject }}</p></div>
                        @if ($canEdit)
                            <button type="button" class="crm-btn-icon" wire:click="editTemplate({{ $template->id }})" aria-label="{{ __('sales-crm::ui.edit') }}"><x-sales-crm::icon name="pencil" :size="13" /></button>
                            <button type="button" class="crm-btn-icon hover:text-danger" wire:click="deleteTemplate({{ $template->id }})" wire:confirm="{{ __('sales-crm::ui.confirm_delete') }}" aria-label="{{ __('sales-crm::ui.delete') }}"><x-sales-crm::icon name="trash" :size="13" /></button>
                        @endif
                    </div>
                @empty
                    <p class="px-4 py-4 text-xs text-muted">{{ __('sales-crm::ui.settings.no_templates') }}</p>
                @endforelse
            </section>
        @else
            <form wire:submit="saveGeneral" class="crm-card crm-card-body flex max-w-2xl flex-col gap-3" data-test="settings-general">
                <x-sales-crm::field :label="__('sales-crm::ui.settings.entry_stage')" error="entry_stage_id" :hint="__('sales-crm::ui.settings.entry_stage_hint')">
                    <select class="crm-select" wire:model="entryStage" @disabled(! $canEdit)>
                        <option value="">{{ __('sales-crm::ui.settings.entry_stage_default') }}</option>
                        @foreach ($this->openStages() as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
                    </select>
                </x-sales-crm::field>
                @if ($canEdit)<div class="flex justify-end"><button type="submit" class="crm-btn crm-btn-primary">{{ __('sales-crm::ui.save') }}</button></div>@endif
            </form>
        @endif

        <x-sales-crm::modal model="showPipeline" :title="$editingPipeline ? __('sales-crm::ui.settings.edit_pipeline') : __('sales-crm::ui.settings.new_pipeline')" :subtitle="$editingPipeline ? null : __('sales-crm::ui.settings.new_pipeline_hint')">
            <div class="flex flex-col gap-3">
                <x-sales-crm::field :label="__('sales-crm::fields.pipeline.name')" error="pipelineForm.name"><input type="text" class="crm-input" wire:model="pipelineForm.name" data-test="pipeline-name"></x-sales-crm::field>
                <label class="flex items-center gap-2 text-[13px]"><input type="checkbox" class="crm-checkbox" wire:model="pipelineForm.is_active"> {{ __('sales-crm::fields.pipeline.is_active') }}</label>
                @error('pipelineForm.is_active') <p class="crm-error">{{ $message }}</p> @enderror
            </div>
            <x-slot:footer>
                <button type="button" class="crm-btn" x-on:click="$wire.set('showPipeline', false)">{{ __('sales-crm::ui.cancel') }}</button>
                <button type="button" class="crm-btn crm-btn-primary" wire:click="savePipeline" data-test="pipeline-save">{{ __('sales-crm::ui.save') }}</button>
            </x-slot:footer>
        </x-sales-crm::modal>

        <x-sales-crm::modal model="showStage" size="lg" :title="$editingStage ? __('sales-crm::ui.settings.edit_stage') : __('sales-crm::ui.settings.new_stage')" data-test="stage-dialog">
            <div class="grid gap-3 sm:grid-cols-2">
                <x-sales-crm::field :label="__('sales-crm::fields.stage.name')" error="stageForm.name"><input type="text" class="crm-input" wire:model="stageForm.name" data-test="stage-name"></x-sales-crm::field>
                <x-sales-crm::field :label="__('sales-crm::fields.stage.color')">
                    <select class="crm-select" wire:model="stageForm.color">
                        @foreach (StageColor::options() as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    </select>
                </x-sales-crm::field>
                <x-sales-crm::field :label="__('sales-crm::fields.stage.kind')" error="stageForm.kind">
                    <select class="crm-select" wire:model.live="stageForm.kind">
                        @foreach (StageKind::options() as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    </select>
                </x-sales-crm::field>
                <x-sales-crm::field :label="__('sales-crm::fields.stage.probability')" error="stageForm.probability"><input type="number" min="0" max="100" class="crm-input" wire:model="stageForm.probability" @disabled(($stageForm['kind'] ?? 'open') !== 'open')></x-sales-crm::field>
                <x-sales-crm::field :label="__('sales-crm::fields.stage.rotting_days')" error="stageForm.rotting_days" :hint="__('sales-crm::ui.settings.rotting_hint')"><input type="number" min="1" max="365" class="crm-input" wire:model="stageForm.rotting_days" @disabled(($stageForm['kind'] ?? 'open') !== 'open')></x-sales-crm::field>
                <div class="sm:col-span-2">
                    <p class="crm-label">{{ __('sales-crm::fields.stage.required_fields') }}</p>
                    <div class="grid gap-1.5 sm:grid-cols-2">
                        @foreach (RequiredField::cases() as $field)
                            <label class="flex items-center gap-2 text-[13px]"><input type="checkbox" class="crm-checkbox" wire:model="stageForm.required_fields" value="{{ $field->value }}" data-test="stage-required-{{ $field->value }}"> {{ $field->label() }}</label>
                        @endforeach
                    </div>
                </div>
                <div class="grid gap-2 rounded-md border border-line p-3 sm:col-span-2 sm:grid-cols-[auto_1fr_120px]">
                    <p class="text-xs text-muted sm:col-span-3">{{ __('sales-crm::ui.settings.automation_hint') }}</p>
                    <select class="crm-select" wire:model="stageForm.on_enter_activity.type" aria-label="{{ __('sales-crm::fields.stage.automation_type') }}">
                        @foreach (ActivityType::options() as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    </select>
                    <input type="text" class="crm-input" wire:model="stageForm.on_enter_activity.title" placeholder="{{ __('sales-crm::fields.stage.automation_title') }}" aria-label="{{ __('sales-crm::fields.stage.automation_title') }}">
                    <input type="number" min="0" max="365" class="crm-input" wire:model="stageForm.on_enter_activity.due_in_days" placeholder="{{ __('sales-crm::fields.stage.automation_days') }}" aria-label="{{ __('sales-crm::fields.stage.automation_days') }}">
                </div>
                @error('stageForm.kind') <p class="crm-error sm:col-span-2">{{ $message }}</p> @enderror
            </div>
            <x-slot:footer>
                <button type="button" class="crm-btn" x-on:click="$wire.set('showStage', false)">{{ __('sales-crm::ui.cancel') }}</button>
                <button type="button" class="crm-btn crm-btn-primary" wire:click="saveStage" data-test="stage-save">{{ __('sales-crm::ui.save') }}</button>
            </x-slot:footer>
        </x-sales-crm::modal>

        <x-sales-crm::modal model="showTemplate" size="lg" :title="$editingTemplate ? __('sales-crm::ui.settings.edit_template') : __('sales-crm::ui.settings.new_template')">
            <div class="flex flex-col gap-3">
                <x-sales-crm::field :label="__('sales-crm::fields.template.name')" error="templateForm.name"><input type="text" class="crm-input" wire:model="templateForm.name" data-test="template-name"></x-sales-crm::field>
                <x-sales-crm::field :label="__('sales-crm::fields.template.subject')" error="templateForm.subject"><input type="text" class="crm-input" wire:model="templateForm.subject"></x-sales-crm::field>
                <x-sales-crm::field :label="__('sales-crm::fields.template.body')" error="templateForm.body" :hint="__('sales-crm::ui.variables_hint', ['variables' => EmailTemplates::placeholders()])"><textarea rows="8" class="crm-input" wire:model="templateForm.body"></textarea></x-sales-crm::field>
            </div>
            <x-slot:footer>
                <button type="button" class="crm-btn" x-on:click="$wire.set('showTemplate', false)">{{ __('sales-crm::ui.cancel') }}</button>
                <button type="button" class="crm-btn crm-btn-primary" wire:click="saveTemplate" data-test="template-save">{{ __('sales-crm::ui.save') }}</button>
            </x-slot:footer>
        </x-sales-crm::modal>
    </x-sales-crm::shell>
</div>
