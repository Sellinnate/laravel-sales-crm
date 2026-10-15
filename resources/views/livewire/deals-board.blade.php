@php
    use Sellinnate\SalesCrm\Actions\SaveView;
    use Sellinnate\SalesCrm\Enums\DealSource;
    use Sellinnate\SalesCrm\Enums\Priority;
    use Sellinnate\SalesCrm\Enums\RecurringPeriod;
    use Sellinnate\SalesCrm\Services\DealBoard;
    use Sellinnate\SalesCrm\Services\DealColumns;
    use Sellinnate\SalesCrm\Services\SpreadsheetExport;
    use Sellinnate\SalesCrm\Support\Crm;
    use Sellinnate\SalesCrm\Support\Format;

    $pipeline = $this->currentPipeline;
    $multi = $this->workspaces !== [];
@endphp
<div>
    @include('sales-crm::partials.board-script')

    <x-sales-crm::shell :title="__('sales-crm::ui.nav.deals')" :subtitle="$pipeline ? ($multi ? $this->access()->label($pipeline->workspaceRef()).' · ' : '').$pipeline->name : null">
        <x-slot:actions>
            <div class="crm-segmented" role="group" aria-label="{{ __('sales-crm::ui.view') }}" data-test="crm-view-toggle">
                <button type="button" wire:click="setView('kanban')" aria-pressed="{{ $view === 'kanban' ? 'true' : 'false' }}" data-test="crm-view-kanban"><x-sales-crm::icon name="kanban" :size="14" /> {{ __('sales-crm::ui.kanban') }}</button>
                <button type="button" wire:click="setView('list')" aria-pressed="{{ $view === 'list' ? 'true' : 'false' }}" data-test="crm-view-list"><x-sales-crm::icon name="list" :size="14" /> {{ __('sales-crm::ui.list') }}</button>
            </div>
            <button type="button" class="crm-btn-icon" wire:click="$set('showShortcuts', true)" title="{{ __('sales-crm::ui.shortcuts') }}" aria-label="{{ __('sales-crm::ui.shortcuts') }}"><x-sales-crm::icon name="help" /></button>
            @if ($pipeline)
                <button type="button" class="crm-btn crm-btn-primary" wire:click="startCreate" data-test="crm-new-deal"><x-sales-crm::icon name="plus" :size="14" /> {{ __('sales-crm::ui.new_deal') }}</button>
            @endif
        </x-slot:actions>

        <div class="flex flex-col gap-3" x-data="salesCrmShortcuts($wire)" x-on:keydown.window="handle($event)" data-test="crm-deals-page">
            {{-- Toolbar --}}
            <div class="flex flex-wrap items-center gap-2" data-test="crm-toolbar">
                @if ($multi)
                    <select class="crm-select w-auto min-w-48" wire:model.live="workspace" aria-label="{{ __('sales-crm::ui.workspace') }}" data-test="crm-workspace">
                        <option value="">{{ __('sales-crm::ui.all_workspaces') }}</option>
                        @foreach ($this->workspaces as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                @endif
                @if ($view === 'kanban' && $this->pipelines->count() > 1)
                    <select class="crm-select w-auto min-w-48" wire:model.live="pipeline" aria-label="{{ __('sales-crm::ui.pipeline') }}" data-test="crm-pipeline">
                        @foreach ($this->pipelines as $option)
                            <option value="{{ $option->id }}" @selected($pipeline?->id === $option->id)>{{ $multi && $workspace === '' ? $this->access()->label($option->workspaceRef()).' · ' : '' }}{{ $option->name }}{{ $option->is_active ? '' : ' ('.__('sales-crm::ui.inactive').')' }}</option>
                        @endforeach
                    </select>
                @endif
                <div class="relative w-full sm:w-72">
                    <span class="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-faint"><x-sales-crm::icon name="search" :size="14" /></span>
                    <input type="search" class="crm-input pl-8" wire:model.live.debounce.300ms="search" placeholder="{{ __('sales-crm::ui.search_deals') }}" aria-label="{{ __('sales-crm::ui.search') }}" data-search data-test="crm-search">
                </div>
                <div class="ml-auto flex flex-wrap items-center gap-1.5">
                    <x-sales-crm::dropdown align="right" width="w-64" data-test="crm-views">
                        <x-slot:trigger>
                            <button type="button" class="crm-btn crm-btn-ghost"><x-sales-crm::icon name="bookmark" :size="14" /> {{ __('sales-crm::ui.views') }}</button>
                        </x-slot:trigger>
                        @forelse ($this->savedViews as $saved)
                            <div class="flex items-center" wire:key="crm-view-{{ $saved->id }}">
                                <button type="button" class="crm-menu-item flex-auto" data-close wire:click="applyView({{ $saved->id }})" data-test="crm-view-apply">
                                    <x-sales-crm::icon :name="$saved->shared ? 'users' : 'bookmark'" :size="14" />
                                    <span class="truncate">{{ $saved->name }}</span>
                                    @if (! Crm::sameUser($saved->user_id, Crm::userKey(auth()->user())))<span class="text-[11px] text-faint">· {{ Crm::userName($saved->user) }}</span>@endif
                                </button>
                                @if (Crm::sameUser($saved->user_id, Crm::userKey(auth()->user())))
                                    <button type="button" class="crm-btn-icon" wire:click="deleteView({{ $saved->id }})" wire:confirm="{{ __('sales-crm::ui.confirm_delete_view', ['name' => $saved->name]) }}" aria-label="{{ __('sales-crm::ui.delete') }}"><x-sales-crm::icon name="x" :size="12" /></button>
                                @endif
                            </div>
                        @empty
                            <p class="px-2.5 py-2 text-xs text-muted">{{ __('sales-crm::ui.no_views') }}</p>
                        @endforelse
                        <div class="my-1 border-t border-line"></div>
                        <button type="button" class="crm-menu-item" data-close x-on:click="$wire.set('viewName', ''); $wire.set('viewShared', false); $wire.set('showSaveView', true)" data-test="crm-view-save"><x-sales-crm::icon name="plus" :size="14" /> {{ __('sales-crm::ui.save_view') }}</button>
                    </x-sales-crm::dropdown>
                    @if ($view === 'list')
                        <button type="button" class="crm-btn crm-btn-ghost" wire:click="chooseColumns" data-test="crm-columns"><x-sales-crm::icon name="columns" :size="14" /> {{ __('sales-crm::ui.columns_button') }}</button>
                        <x-sales-crm::dropdown align="right" width="w-40" data-test="crm-export">
                            <x-slot:trigger>
                                <button type="button" class="crm-btn crm-btn-ghost"><x-sales-crm::icon name="download" :size="14" /> {{ __('sales-crm::ui.export') }}</button>
                            </x-slot:trigger>
                            @foreach (SpreadsheetExport::formats() as $format)
                                <a class="crm-menu-item" href="{{ $this->exportUrl($format) }}" data-test="crm-export-{{ $format }}"><x-sales-crm::icon name="file-text" :size="14" /> {{ strtoupper($format) }}</a>
                            @endforeach
                        </x-sales-crm::dropdown>
                    @else
                        <button type="button" class="crm-btn crm-btn-ghost" wire:click="chooseCardFields" data-test="crm-card-fields"><x-sales-crm::icon name="list" :size="14" /> {{ __('sales-crm::ui.card_fields_button') }}</button>
                    @endif
                </div>
            </div>

            {{-- Quick filters and filters --}}
            <div class="flex flex-wrap items-center gap-1.5" data-test="crm-quick-filters">
                @foreach (DealBoard::QUICK_FILTERS as $filter)
                    <button type="button" class="crm-chip" aria-pressed="{{ in_array($filter, $quick, true) ? 'true' : 'false' }}" wire:click="toggleQuick('{{ $filter }}')" data-test="crm-quick-{{ $filter }}">{{ __('sales-crm::ui.quick.'.$filter) }}</button>
                @endforeach
                <span class="mx-1 h-4 w-px bg-line"></span>
                @if ($this->owners !== [])
                    <select class="crm-select crm-input-sm w-40" wire:model.live="owner" aria-label="{{ __('sales-crm::ui.columns.owner') }}" data-test="crm-owner-filter">
                        <option value="">{{ __('sales-crm::ui.any_owner') }}</option>
                        <option value="none">{{ __('sales-crm::ui.reports.no_owner') }}</option>
                        @foreach ($this->owners as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                @endif
                <select class="crm-select crm-input-sm w-36" wire:model.live="priority" aria-label="{{ __('sales-crm::ui.columns.priority') }}">
                    <option value="">{{ __('sales-crm::ui.any_priority') }}</option>
                    @foreach (Priority::options() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                <select class="crm-select crm-input-sm w-36" wire:model.live="source" aria-label="{{ __('sales-crm::ui.columns.source') }}">
                    <option value="">{{ __('sales-crm::ui.any_source') }}</option>
                    @foreach (DealSource::options() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                @if ($this->tags !== [])
                    <select class="crm-select crm-input-sm w-32" wire:model.live="tag" aria-label="{{ __('sales-crm::ui.columns.tags') }}" data-test="crm-tag-filter">
                        <option value="">{{ __('sales-crm::ui.any_tag') }}</option>
                        @foreach ($this->tags as $value => $label)
                            <option value="{{ $value }}">#{{ $label }}</option>
                        @endforeach
                    </select>
                @endif
                <select class="crm-select crm-input-sm w-48" wire:model.live="closed" aria-label="{{ __('sales-crm::ui.closed_deals') }}">
                    <option value="recent">{{ __('sales-crm::ui.closed_recent', ['days' => config('sales-crm.deals.closed_visible_days', 90)]) }}</option>
                    <option value="all">{{ __('sales-crm::ui.closed_all') }}</option>
                </select>
                @if ($search !== '' || $quick !== [] || $owner !== '' || $priority !== '' || $source !== '' || $tag !== '' || $closed !== 'recent')
                    <button type="button" class="crm-btn crm-btn-ghost crm-btn-sm" wire:click="resetFilters" data-test="crm-reset-filters"><x-sales-crm::icon name="x" :size="12" /> {{ __('sales-crm::ui.reset_filters') }}</button>
                @endif
            </div>

            {{-- Getting started: shown until the first deal exists, and dismissable. --}}
            @if ($this->isEmpty)
                <div class="crm-card crm-card-body" x-data="{ hidden: localStorage.getItem('sales-crm-guide') === 'hidden' }" x-show="! hidden" data-test="crm-guide">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h2 class="text-[15px] font-semibold text-ink">{{ __('sales-crm::ui.guide.title') }}</h2>
                            <p class="mt-0.5 text-[13px] text-muted">{{ __('sales-crm::ui.guide.intro') }}</p>
                        </div>
                        <button type="button" class="crm-btn-icon" x-on:click="hidden = true; localStorage.setItem('sales-crm-guide', 'hidden')" aria-label="{{ __('sales-crm::ui.close') }}"><x-sales-crm::icon name="x" /></button>
                    </div>
                    <ol class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                        <li class="rounded-md border border-line p-3">
                            <p class="text-xs font-semibold text-brand">1</p>
                            <p class="text-[13px] font-medium text-ink">{{ __('sales-crm::ui.guide.stages') }}</p>
                            <a href="{{ route('sales-crm.settings') }}" class="crm-link text-xs">{{ __('sales-crm::ui.guide.stages_link') }}</a>
                        </li>
                        <li class="rounded-md border border-line p-3">
                            <p class="text-xs font-semibold text-brand">2</p>
                            <p class="text-[13px] font-medium text-ink">{{ __('sales-crm::ui.guide.first_deal') }}</p>
                            <button type="button" class="crm-link text-xs" wire:click="startCreate">{{ __('sales-crm::ui.guide.first_deal_link') }}</button>
                        </li>
                        <li class="rounded-md border border-line p-3">
                            <p class="text-xs font-semibold text-brand">3</p>
                            <p class="text-[13px] font-medium text-ink">{{ __('sales-crm::ui.guide.import') }}</p>
                            @if (Route::has('sales-crm.import') && $this->canManageScope())
                                <a href="{{ route('sales-crm.import') }}" class="crm-link text-xs">{{ __('sales-crm::ui.guide.import_link') }}</a>
                            @endif
                        </li>
                        <li class="rounded-md border border-line p-3">
                            <p class="text-xs font-semibold text-brand">4</p>
                            <p class="text-[13px] font-medium text-ink">{{ __('sales-crm::ui.guide.daily') }}</p>
                            <p class="text-xs text-muted">{{ __('sales-crm::ui.guide.daily_hint') }}</p>
                        </li>
                    </ol>
                </div>
            @endif

            @if ($pipeline === null)
                <div class="crm-card">
                    <x-sales-crm::empty icon="briefcase" :title="__('sales-crm::ui.no_workspaces')" :description="__('sales-crm::ui.no_workspaces_hint')" data-test="crm-no-workspaces" />
                </div>
            @elseif ($view === 'kanban')
                {{-- The board: no fixed height, the columns grow with their cards. --}}
                <div class="crm-board" x-data="salesCrmBoard($wire)" wire:poll.{{ max(5, (int) config('sales-crm.ui.poll_seconds', 15)) }}s.visible="refreshIfChanged" data-test="crm-board">
                    @foreach ($this->columns as $column)
                        @php $stage = $column['stage']; @endphp
                        <section class="crm-column" wire:key="stage-{{ $stage->id }}" data-stage="{{ $stage->id }}" aria-label="{{ $stage->name }}" data-test="stage-column">
                            <header class="flex flex-col gap-1 border-b border-line px-3 pb-2 pt-2.5">
                                <div class="flex items-center gap-2">
                                    <span class="crm-dot {{ $stage->color->dotClass() }}"></span>
                                    <h3 class="min-w-0 flex-auto truncate text-[13px] font-semibold text-ink">{{ $stage->name }}</h3>
                                    <span class="crm-num text-[11px] text-muted" data-test="stage-count">{{ $column['count'] }}</span>
                                    <button type="button" class="crm-btn-icon size-6" wire:click="startCreate({{ $stage->id }})" aria-label="{{ __('sales-crm::ui.new_deal_in', ['stage' => $stage->name]) }}" data-test="stage-add-{{ $stage->id }}"><x-sales-crm::icon name="plus" :size="13" /></button>
                                </div>
                                <div class="flex items-center justify-between text-[11px] text-muted">
                                    <span class="crm-num" title="{{ __('sales-crm::ui.columns.first_year') }}" data-test="stage-total">{{ Format::money($column['value']) }}</span>
                                    @if ($stage->isOpen())
                                        <span class="crm-num" title="{{ __('sales-crm::ui.weighted_at', ['percent' => $stage->probability]) }}" data-test="stage-weighted">{{ Format::money($column['weighted']) }} · {{ $stage->probability }}%</span>
                                    @endif
                                </div>
                            </header>
                            <div class="crm-column-list" x-on:dragover="over($event)" x-on:dragleave="leave($event)" x-on:drop="drop($event, {{ $stage->id }})" data-test="stage-list-{{ $stage->id }}">
                                @foreach ($column['deals'] as $deal)
                                    @include('sales-crm::livewire.partials.deal-card', ['deal' => $deal, 'stages' => $pipeline->stages, 'fields' => $this->cardFieldList()])
                                @endforeach
                            </div>
                            @if ($column['more'] > 0)
                                <div class="border-t border-line p-2">
                                    <button type="button" class="crm-btn crm-btn-ghost crm-btn-sm w-full" wire:click="showMore({{ $stage->id }})" data-test="stage-more-{{ $stage->id }}">{{ __('sales-crm::ui.show_more', ['count' => min($column['more'], DealBoard::perColumn()), 'total' => $column['more']]) }}</button>
                                </div>
                            @endif
                        </section>
                    @endforeach
                </div>
            @else
                @include('sales-crm::livewire.partials.deal-list')
            @endif
        </div>

        {{-- The side panel of a deal --}}
        <x-sales-crm::slide-over model="showPanel" :title="__('sales-crm::ui.deal')" data-test="crm-deal-panel">
            @if ($openDealId !== null)
                <livewire:sales-crm.deal-panel :deal-id="$openDealId" :key="'deal-panel-'.$openDealId" />
            @endif
        </x-sales-crm::slide-over>

        {{-- The mini form of the fields the stage requires --}}
        <x-sales-crm::modal model="showRequirements" :title="__('sales-crm::ui.requirements_title', ['stage' => $this->pendingStageName])" :subtitle="__('sales-crm::ui.requirements_subtitle')" data-test="crm-requirements-dialog">
            @if ($showRequirements)
                @include('sales-crm::livewire.partials.requirement-fields', [
                    'fields' => $this->pendingMissing,
                    'lostReason' => $this->pendingNeedsReason,
                    'model' => 'fill',
                    'owners' => $this->owners,
                    'companies' => $this->companyNames,
                ])
            @endif
            <x-slot:footer>
                <button type="button" class="crm-btn" wire:click="cancelMove">{{ __('sales-crm::ui.cancel') }}</button>
                <button type="button" class="crm-btn crm-btn-primary" wire:click="completeMove" wire:loading.attr="disabled" data-test="crm-requirements-save"><x-sales-crm::icon name="arrow-right" :size="14" /> {{ __('sales-crm::ui.move') }}</button>
            </x-slot:footer>
        </x-sales-crm::modal>

        {{-- Quick add --}}
        <x-sales-crm::modal model="showCreate" size="lg" :title="__('sales-crm::ui.new_deal')" :subtitle="$pipeline ? ($multi ? $this->access()->label($pipeline->workspaceRef()).' · ' : '').$pipeline->name : null" data-test="crm-create-dialog">
            <form wire:submit="createDeal" id="crm-create-form" class="grid gap-3 sm:grid-cols-2">
                <x-sales-crm::field class="sm:col-span-2" :label="__('sales-crm::fields.deal.title')" error="draft.title">
                    <input type="text" class="crm-input" wire:model="draft.title" required autofocus placeholder="{{ __('sales-crm::ui.title_placeholder') }}" data-test="crm-draft-title">
                </x-sales-crm::field>
                <x-sales-crm::field :label="__('sales-crm::fields.deal.company_name')" error="draft.company_name" :hint="__('sales-crm::ui.company_reused')">
                    <input type="text" class="crm-input" wire:model="draft.company_name" list="crm-draft-companies" autocomplete="off" data-test="crm-draft-company">
                    <datalist id="crm-draft-companies">
                        @foreach ($this->companyNames as $name)
                            <option value="{{ $name }}"></option>
                        @endforeach
                    </datalist>
                </x-sales-crm::field>
                <x-sales-crm::field :label="__('sales-crm::fields.deal.stage_id')" error="draft.stage_id">
                    <select class="crm-select" wire:model.live="draft.stage_id" data-test="crm-draft-stage">
                        @foreach ($pipeline?->stages ?? [] as $stage)
                            <option value="{{ $stage->id }}">{{ $stage->name }}</option>
                        @endforeach
                    </select>
                </x-sales-crm::field>
                <x-sales-crm::field :label="__('sales-crm::ui.contact_first_name')" error="draft.person.first_name">
                    <input type="text" class="crm-input" wire:model="draft.person.first_name" data-test="crm-draft-person">
                </x-sales-crm::field>
                <x-sales-crm::field :label="__('sales-crm::fields.person.last_name')" error="draft.person.last_name">
                    <input type="text" class="crm-input" wire:model="draft.person.last_name">
                </x-sales-crm::field>
                <x-sales-crm::field :label="__('sales-crm::fields.person.email')" error="draft.person.email">
                    <input type="email" class="crm-input" wire:model="draft.person.email" data-test="crm-draft-email">
                </x-sales-crm::field>
                <x-sales-crm::field :label="__('sales-crm::fields.person.mobile')" error="draft.person.mobile">
                    <input type="text" class="crm-input" wire:model="draft.person.mobile">
                </x-sales-crm::field>
                <x-sales-crm::field :label="__('sales-crm::fields.deal.amount_once')" error="draft.amount_once">
                    <input type="number" min="0" step="0.01" class="crm-input crm-num" wire:model="draft.amount_once" data-test="crm-draft-amount">
                </x-sales-crm::field>
                <div class="grid grid-cols-[1fr_auto] gap-2">
                    <x-sales-crm::field :label="__('sales-crm::fields.deal.recurring')" error="draft.recurring">
                        <input type="number" min="0" step="0.01" class="crm-input crm-num" wire:model="draft.recurring">
                    </x-sales-crm::field>
                    <x-sales-crm::field :label="__('sales-crm::fields.deal.recurring_period')">
                        <select class="crm-select" wire:model="draft.recurring_period">
                            @foreach (RecurringPeriod::options() as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </x-sales-crm::field>
                </div>
                <x-sales-crm::field :label="__('sales-crm::fields.deal.expected_close_date')" error="draft.expected_close_date">
                    <input type="date" class="crm-input" wire:model="draft.expected_close_date">
                </x-sales-crm::field>
                <x-sales-crm::field :label="__('sales-crm::fields.deal.owner_id')" error="draft.owner_id">
                    <select class="crm-select" wire:model="draft.owner_id" data-test="crm-draft-owner">
                        <option value="">—</option>
                        @foreach ($this->owners as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </x-sales-crm::field>
                <x-sales-crm::field :label="__('sales-crm::fields.deal.priority')" error="draft.priority">
                    <select class="crm-select" wire:model="draft.priority">
                        <option value="">—</option>
                        @foreach (Priority::options() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </x-sales-crm::field>
                <x-sales-crm::field :label="__('sales-crm::fields.deal.source')" error="draft.source">
                    <select class="crm-select" wire:model="draft.source">
                        @foreach (DealSource::options() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </x-sales-crm::field>
                @if ($this->draftRequirements !== [] || $this->draftNeedsReason)
                    <div class="flex flex-col gap-2 border-t border-line pt-3 sm:col-span-2" data-test="crm-draft-requirements">
                        <span class="text-xs text-muted">{{ __('sales-crm::ui.required_by_stage') }}</span>
                        @include('sales-crm::livewire.partials.requirement-fields', [
                            'fields' => array_values(array_filter($this->draftRequirements, fn ($field) => ! in_array($field->value, ['company', 'person', 'owner', 'amount', 'expected_close_date'], true))),
                            'lostReason' => $this->draftNeedsReason,
                            'model' => 'draft',
                            'owners' => $this->owners,
                        ])
                    </div>
                @endif
                @foreach ($errors->getMessages() as $key => $messages)
                    @if (str_starts_with($key, 'draft.requirements.'))
                        <p class="crm-error sm:col-span-2" data-test="crm-draft-requirement-error">{{ $messages[0] }}</p>
                    @endif
                @endforeach
            </form>
            <x-slot:footer>
                <button type="button" class="crm-btn" x-on:click="$wire.set('showCreate', false)">{{ __('sales-crm::ui.cancel') }}</button>
                <button type="submit" form="crm-create-form" class="crm-btn crm-btn-primary" wire:loading.attr="disabled" data-test="crm-draft-save"><x-sales-crm::icon name="plus" :size="14" /> {{ __('sales-crm::ui.create_deal') }}</button>
            </x-slot:footer>
        </x-sales-crm::modal>

        {{-- Columns of the list --}}
        <x-sales-crm::modal model="showColumns" :title="__('sales-crm::ui.columns_title')" data-test="crm-columns-dialog">
            <div class="grid gap-1.5 sm:grid-cols-2">
                @foreach (DealColumns::all($this->scope()) as $key => $label)
                    <label class="flex items-center gap-2 text-[13px]"><input type="checkbox" class="crm-checkbox" wire:model="columnChoice" value="{{ $key }}"> {{ $label }}</label>
                @endforeach
            </div>
            <x-slot:footer>
                <button type="button" class="crm-btn" x-on:click="$wire.set('showColumns', false)">{{ __('sales-crm::ui.cancel') }}</button>
                <button type="button" class="crm-btn crm-btn-primary" wire:click="applyColumns" data-test="crm-columns-apply">{{ __('sales-crm::ui.apply') }}</button>
            </x-slot:footer>
        </x-sales-crm::modal>

        {{-- Fields of the cards --}}
        <x-sales-crm::modal model="showCardFields" :title="__('sales-crm::ui.card_fields_title')">
            <div class="grid gap-1.5 sm:grid-cols-2">
                @foreach (SaveView::CARD_FIELDS as $field)
                    <label class="flex items-center gap-2 text-[13px]"><input type="checkbox" class="crm-checkbox" wire:model="cardChoice" value="{{ $field }}" data-test="crm-card-field-{{ $field }}"> {{ __('sales-crm::ui.card_fields.'.$field) }}</label>
                @endforeach
            </div>
            <x-slot:footer>
                <button type="button" class="crm-btn" x-on:click="$wire.set('showCardFields', false)">{{ __('sales-crm::ui.cancel') }}</button>
                <button type="button" class="crm-btn crm-btn-primary" wire:click="applyCardFields" data-test="crm-card-fields-apply">{{ __('sales-crm::ui.apply') }}</button>
            </x-slot:footer>
        </x-sales-crm::modal>

        {{-- Save the view --}}
        <x-sales-crm::modal model="showSaveView" :title="__('sales-crm::ui.save_view')" :subtitle="__('sales-crm::ui.save_view_hint')">
            <div class="flex flex-col gap-3">
                <x-sales-crm::field :label="__('sales-crm::fields.view.name')" error="view.name">
                    <input type="text" class="crm-input" wire:model="viewName" placeholder="{{ __('sales-crm::ui.view_placeholder') }}" data-test="crm-view-name">
                </x-sales-crm::field>
                @if (! $multi || $workspace !== '')
                    <label class="flex items-start gap-2 text-[13px]"><input type="checkbox" class="crm-checkbox mt-0.5" wire:model="viewShared"> <span>{{ __('sales-crm::ui.view_shared') }}<span class="block text-xs text-muted">{{ __('sales-crm::ui.view_shared_hint') }}</span></span></label>
                @endif
                @error('view.shared') <p class="crm-error">{{ $message }}</p> @enderror
            </div>
            <x-slot:footer>
                <button type="button" class="crm-btn" x-on:click="$wire.set('showSaveView', false)">{{ __('sales-crm::ui.cancel') }}</button>
                <button type="button" class="crm-btn crm-btn-primary" wire:click="saveView" data-test="crm-view-save-confirm">{{ __('sales-crm::ui.save') }}</button>
            </x-slot:footer>
        </x-sales-crm::modal>

        {{-- Keyboard shortcuts --}}
        <x-sales-crm::modal model="showShortcuts" :title="__('sales-crm::ui.shortcuts')">
            <dl class="grid grid-cols-[auto_1fr] items-center gap-x-4 gap-y-2 text-[13px]" data-test="crm-shortcuts-dialog">
                <dt><kbd class="crm-kbd">N</kbd></dt><dd>{{ __('sales-crm::ui.shortcut.new') }}</dd>
                <dt><kbd class="crm-kbd">/</kbd></dt><dd>{{ __('sales-crm::ui.shortcut.search') }}</dd>
                <dt><kbd class="crm-kbd">K</kbd> <kbd class="crm-kbd">L</kbd></dt><dd>{{ __('sales-crm::ui.shortcut.views') }}</dd>
                <dt><kbd class="crm-kbd">[</kbd> <kbd class="crm-kbd">]</kbd></dt><dd>{{ __('sales-crm::ui.shortcut.move') }}</dd>
                <dt><kbd class="crm-kbd">Enter</kbd></dt><dd>{{ __('sales-crm::ui.shortcut.open') }}</dd>
                <dt><kbd class="crm-kbd">Esc</kbd></dt><dd>{{ __('sales-crm::ui.shortcut.close') }}</dd>
                <dt><kbd class="crm-kbd">?</kbd></dt><dd>{{ __('sales-crm::ui.shortcut.help') }}</dd>
            </dl>
        </x-sales-crm::modal>
    </x-sales-crm::shell>
</div>
