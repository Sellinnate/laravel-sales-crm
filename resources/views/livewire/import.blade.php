@php
    use Sellinnate\SalesCrm\Enums\ImportStatus;
    use Sellinnate\SalesCrm\Services\Import\ImportFields;
    use Sellinnate\SalesCrm\Services\Import\SpreadsheetReader;
    use Sellinnate\SalesCrm\Support\Format;

    $import = $this->import();
    $workspaces = $this->manageableWorkspaces();
@endphp
<div>
    <x-sales-crm::shell :title="__('sales-crm::ui.nav.import')" :subtitle="__('sales-crm::ui.import.subtitle')">
        @if ($import === null)
            <form wire:submit="uploadFile" class="crm-card crm-card-body grid max-w-2xl gap-3" data-test="import-upload">
                <h2 class="text-[15px] font-semibold">{{ __('sales-crm::ui.import.step_upload') }}</h2>
                @if ($workspaces !== [])
                    <x-sales-crm::field :label="__('sales-crm::ui.workspace')">
                        <select class="crm-select" wire:model.live="workspace">
                            @foreach ($workspaces as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                        </select>
                    </x-sales-crm::field>
                @endif
                <x-sales-crm::field :label="__('sales-crm::ui.pipeline')">
                    <select class="crm-select" wire:model="pipelineId" data-test="import-pipeline">
                        @foreach ($this->pipelines as $pipeline)<option value="{{ $pipeline->id }}">{{ $pipeline->name }}</option>@endforeach
                    </select>
                </x-sales-crm::field>
                <x-sales-crm::field :label="__('sales-crm::fields.document.file')" error="upload.file" :hint="__('sales-crm::ui.import.formats', ['formats' => strtoupper(implode(', ', SpreadsheetReader::extensions()))])">
                    <input type="file" class="crm-input" wire:model="upload" accept=".{{ implode(',.', SpreadsheetReader::extensions()) }}" data-test="import-file">
                </x-sales-crm::field>
                @error('upload') <p class="crm-error">{{ $message }}</p> @enderror
                @error('upload.file') <p class="crm-error" data-test="import-upload-error">{{ $message }}</p> @enderror
                <div class="flex justify-end"><button type="submit" class="crm-btn crm-btn-primary" wire:loading.attr="disabled" data-test="import-upload-submit"><x-sales-crm::icon name="upload" :size="14" /> {{ __('sales-crm::ui.import.continue') }}</button></div>
            </form>

            @if ($this->recent->isNotEmpty())
                <section class="crm-card mt-4 max-w-2xl">
                    <h2 class="border-b border-line px-4 py-2.5 text-[13px] font-semibold">{{ __('sales-crm::ui.import.recent') }}</h2>
                    @foreach ($this->recent as $past)
                        <a href="{{ route('sales-crm.import', ['import' => $past->id]) }}" class="flex items-center justify-between border-b border-line px-4 py-2 text-[13px] last:border-0 hover:bg-sunken">
                            <span>{{ $past->original_filename }}<span class="block text-xs text-muted">{{ Format::dateTime($past->created_at) }}</span></span>
                            <x-sales-crm::badge :tone="$past->status->tone()">{{ $past->status->label() }}</x-sales-crm::badge>
                        </a>
                    @endforeach
                </section>
            @endif
        @elseif (in_array($import->status, [ImportStatus::Uploaded, ImportStatus::Mapped], true))
            <div class="flex flex-col gap-4" data-test="import-mapping">
                <div class="crm-card crm-card-body flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="text-[13px] font-semibold">{{ $import->original_filename }}</p>
                        <p class="text-xs text-muted">{{ __('sales-crm::ui.import.into', ['pipeline' => $import->pipeline?->name]) }}</p>
                    </div>
                    <div class="flex items-center gap-2">
                        @if (count($this->sheets()) > 1)
                            <select class="crm-select w-48" wire:model.live="sheet" aria-label="{{ __('sales-crm::ui.import.sheet') }}" data-test="import-sheet">
                                @foreach ($this->sheets() as $name)<option value="{{ $name }}">{{ $name }}</option>@endforeach
                            </select>
                        @endif
                        <button type="button" class="crm-btn" wire:click="restart">{{ __('sales-crm::ui.import.restart') }}</button>
                    </div>
                </div>

                <section class="crm-card">
                    <h2 class="border-b border-line px-4 py-2.5 text-[13px] font-semibold">{{ __('sales-crm::ui.import.step_mapping') }}</h2>
                    <div class="grid gap-2 p-4 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($this->headers() as $index => $header)
                            <x-sales-crm::field :label="$header !== '' ? $header : __('sales-crm::ui.import.column', ['number' => $index + 1])">
                                <select class="crm-select" wire:model.live="columns.{{ $index }}" data-test="import-column-{{ $index }}">
                                    <option value="">{{ __('sales-crm::ui.import.ignore') }}</option>
                                    @foreach (ImportFields::grouped($import->workspaceRef()) as $group => $fields)
                                        <optgroup label="{{ $group }}">
                                            @foreach ($fields as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                                        </optgroup>
                                    @endforeach
                                </select>
                            </x-sales-crm::field>
                        @endforeach
                    </div>
                    @error('mapping.columns') <p class="crm-error px-4 pb-3">{{ $message }}</p> @enderror
                    <label class="flex items-center gap-2 border-t border-line px-4 py-2.5 text-[13px]"><input type="checkbox" class="crm-checkbox" wire:model="monthFirst"> {{ __('sales-crm::ui.import.month_first') }}</label>
                </section>

                <section class="crm-card">
                    <h2 class="border-b border-line px-4 py-2.5 text-[13px] font-semibold">{{ __('sales-crm::ui.import.step_stages') }}</h2>
                    <div class="grid gap-2 p-4 sm:grid-cols-2">
                        @foreach ($stages as $index => $row)
                            <x-sales-crm::field :label="$row['value']" wire:key="import-stage-{{ $index }}">
                                <select class="crm-select" wire:model="stages.{{ $index }}.stage" data-test="import-stage-{{ $index }}">
                                    <option value="">{{ __('sales-crm::ui.import.default_stage_option') }}</option>
                                    @foreach ($import->pipeline?->stages ?? [] as $stage)<option value="{{ $stage->id }}">{{ $stage->name }}</option>@endforeach
                                </select>
                            </x-sales-crm::field>
                        @endforeach
                        <x-sales-crm::field :label="__('sales-crm::ui.import.default_stage')">
                            <select class="crm-select" wire:model="defaultStage" data-test="import-default-stage">
                                @foreach ($import->pipeline?->stages ?? [] as $stage)<option value="{{ $stage->id }}">{{ $stage->name }}</option>@endforeach
                            </select>
                        </x-sales-crm::field>
                    </div>
                </section>

                <section class="crm-card">
                    <div class="flex items-center justify-between border-b border-line px-4 py-2.5">
                        <h2 class="text-[13px] font-semibold">{{ __('sales-crm::ui.import.step_preview') }}</h2>
                        <button type="button" class="crm-btn crm-btn-sm" wire:click="saveMapping" data-test="import-preview">{{ __('sales-crm::ui.import.refresh_preview') }}</button>
                    </div>
                    @if ($this->outcomes !== [])
                        <table class="crm-table" data-test="import-outcomes">
                            <thead><tr><th>{{ __('sales-crm::import.row') }}</th><th>{{ __('sales-crm::fields.deal.title') }}</th><th>{{ __('sales-crm::ui.import.outcome') }}</th></tr></thead>
                            <tbody>
                                @foreach ($this->outcomes as $outcome)
                                    <tr>
                                        <td class="crm-num">{{ $outcome['row'] }}</td>
                                        <td>{{ $outcome['title'] }}</td>
                                        <td>
                                            <x-sales-crm::badge :tone="['create' => 'success', 'update' => 'info', 'reject' => 'danger'][$outcome['action']]">{{ __('sales-crm::ui.import.action_'.$outcome['action']) }}</x-sales-crm::badge>
                                            @if ($outcome['reason'])<span class="text-xs text-danger">{{ $outcome['reason'] }}</span>@endif
                                            @foreach ($outcome['warnings'] as $warning)<span class="block text-xs text-warning">{{ $warning }}</span>@endforeach
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @else
                        <p class="px-4 py-4 text-xs text-muted">{{ __('sales-crm::ui.import.preview_hint') }}</p>
                    @endif
                </section>

                <div class="flex justify-end"><button type="button" class="crm-btn crm-btn-primary" wire:click="run" data-test="import-run"><x-sales-crm::icon name="zap" :size="14" /> {{ __('sales-crm::ui.import.run') }}</button></div>
            </div>
        @else
            <section class="crm-card crm-card-body max-w-2xl" @if (in_array($import->status, [ImportStatus::Queued, ImportStatus::Running], true)) wire:poll.3s @endif data-test="import-report">
                <div class="flex items-center justify-between">
                    <h2 class="text-[15px] font-semibold">{{ $import->original_filename }}</h2>
                    <x-sales-crm::badge :tone="$import->status->tone()" data-test="import-status">{{ $import->status->label() }}</x-sales-crm::badge>
                </div>
                @if ($import->error)<p class="mt-2 text-[13px] text-danger">{{ $import->error }}</p>@endif
                <dl class="mt-3 grid grid-cols-4 gap-2 text-center">
                    <div class="rounded-md border border-line p-2"><dt class="text-xs text-muted">{{ __('sales-crm::ui.import.rows') }}</dt><dd class="text-lg font-semibold crm-num">{{ $import->total_rows }}</dd></div>
                    <div class="rounded-md border border-line p-2"><dt class="text-xs text-muted">{{ __('sales-crm::ui.import.created') }}</dt><dd class="text-lg font-semibold text-success crm-num" data-test="import-created">{{ $import->created_count }}</dd></div>
                    <div class="rounded-md border border-line p-2"><dt class="text-xs text-muted">{{ __('sales-crm::ui.import.updated') }}</dt><dd class="text-lg font-semibold text-info crm-num">{{ $import->updated_count }}</dd></div>
                    <div class="rounded-md border border-line p-2"><dt class="text-xs text-muted">{{ __('sales-crm::ui.import.rejected') }}</dt><dd class="text-lg font-semibold text-danger crm-num">{{ $import->rejected_count }}</dd></div>
                </dl>
                @if ($import->rejected_count > 0)
                    <a href="{{ route('sales-crm.imports.rejected', $import) }}" class="crm-btn mt-3"><x-sales-crm::icon name="download" :size="14" /> {{ __('sales-crm::ui.import.download_rejected') }}</a>
                    <ul class="mt-2 text-xs text-danger">
                        @foreach (array_slice($import->rejected ?? [], 0, 20) as $row)<li>{{ __('sales-crm::import.row') }} {{ $row['row'] }}: {{ $row['reason'] }}</li>@endforeach
                    </ul>
                @endif
                @if (($import->warnings ?? []) !== [])
                    <h3 class="crm-section-title mt-3">{{ __('sales-crm::ui.import.warnings') }}</h3>
                    <ul class="mt-1 text-xs text-warning">
                        @foreach (array_slice($import->warnings ?? [], 0, 50) as $row)<li>{{ __('sales-crm::import.row') }} {{ $row['row'] }}: {{ $row['message'] }}</li>@endforeach
                    </ul>
                @endif
                <div class="mt-4 flex gap-2">
                    <a href="{{ route('sales-crm.deals') }}" class="crm-btn crm-btn-primary">{{ __('sales-crm::ui.back_to_deals') }}</a>
                    <button type="button" class="crm-btn" wire:click="restart">{{ __('sales-crm::ui.import.new') }}</button>
                </div>
            </section>
        @endif
    </x-sales-crm::shell>
</div>
