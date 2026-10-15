<div>
    <x-sales-crm::shell :title="__('sales-crm::ui.nav.companies')">
        <x-slot:actions>
            <button type="button" class="crm-btn crm-btn-primary" wire:click="startCreate" data-test="company-new"><x-sales-crm::icon name="plus" :size="14" /> {{ __('sales-crm::ui.new_company') }}</button>
        </x-slot:actions>

        <div class="mb-3 flex flex-wrap items-center gap-2">
            <div class="relative w-full sm:w-80">
                <span class="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-faint"><x-sales-crm::icon name="search" :size="14" /></span>
                <input type="search" class="crm-input pl-8" wire:model.live.debounce.300ms="search" placeholder="{{ __('sales-crm::ui.search_companies') }}" aria-label="{{ __('sales-crm::ui.search') }}" data-test="company-search">
            </div>
            @if ($this->workspaces !== [])
                <select class="crm-select w-auto" wire:model.live="workspace" aria-label="{{ __('sales-crm::ui.workspace') }}">
                    <option value="">{{ __('sales-crm::ui.all_workspaces') }}</option>
                    @foreach ($this->workspaces as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                </select>
            @endif
        </div>

        <div class="crm-card overflow-hidden" data-test="company-list">
            <table class="crm-table">
                <thead>
                    <tr>
                        <th>{{ __('sales-crm::fields.company.name') }}</th>
                        @if ($this->workspaces !== [])<th>{{ __('sales-crm::ui.workspace') }}</th>@endif
                        <th>{{ __('sales-crm::fields.company.vat_number') }}</th>
                        <th>{{ __('sales-crm::fields.company.city') }}</th>
                        <th>{{ __('sales-crm::fields.company.industry') }}</th>
                        <th class="text-right">{{ __('sales-crm::ui.nav.deals') }}</th>
                        <th class="text-right">{{ __('sales-crm::ui.nav.people') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->companies as $company)
                        <tr wire:key="company-{{ $company->id }}">
                            <td><a href="{{ route('sales-crm.companies.show', $company) }}" class="font-medium text-ink hover:text-brand" data-test="company-link">{{ $company->name }}</a>@if ($company->domain)<span class="block text-xs text-muted">{{ $company->domain }}</span>@endif</td>
                            @if ($this->workspaces !== [])<td class="text-xs text-muted">{{ $this->access()->label($company->workspaceRef()) }}</td>@endif
                            <td class="text-xs">{{ $company->vat_number }}</td>
                            <td class="text-xs">{{ $company->city }}</td>
                            <td class="text-xs">{{ $company->industry }}</td>
                            <td class="crm-num text-right">{{ $company->deals_count }}</td>
                            <td class="crm-num text-right">{{ $company->people_count }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7"><x-sales-crm::empty icon="building" :title="__('sales-crm::ui.no_companies')" /></td></tr>
                    @endforelse
                </tbody>
            </table>
            @if ($this->companies->hasPages())
                <div class="flex justify-end gap-1 border-t border-line px-3 py-2">
                    <button type="button" class="crm-btn crm-btn-sm" wire:click="previousPage" @disabled($this->companies->onFirstPage())>{{ __('sales-crm::ui.previous') }}</button>
                    <button type="button" class="crm-btn crm-btn-sm" wire:click="nextPage" @disabled(! $this->companies->hasMorePages())>{{ __('sales-crm::ui.next') }}</button>
                </div>
            @endif
        </div>

        <x-sales-crm::modal model="showCreate" :title="__('sales-crm::ui.new_company')" data-test="company-create-dialog">
            <form wire:submit="create" id="crm-company-form" class="grid gap-3 sm:grid-cols-2">
                @if ($this->workspaces !== [])
                    <x-sales-crm::field class="sm:col-span-2" :label="__('sales-crm::ui.workspace')">
                        <select class="crm-select" wire:model="draft.workspace">
                            @foreach ($this->workspaces as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                        </select>
                    </x-sales-crm::field>
                @endif
                <x-sales-crm::field class="sm:col-span-2" :label="__('sales-crm::fields.company.name')" error="draft.name"><input type="text" class="crm-input" wire:model="draft.name" required data-test="company-name"></x-sales-crm::field>
                <x-sales-crm::field :label="__('sales-crm::fields.company.vat_number')" error="draft.vat_number"><input type="text" class="crm-input" wire:model="draft.vat_number"></x-sales-crm::field>
                <x-sales-crm::field :label="__('sales-crm::fields.company.website')" error="draft.website"><input type="text" class="crm-input" wire:model="draft.website"></x-sales-crm::field>
                <x-sales-crm::field :label="__('sales-crm::fields.company.email')" error="draft.email"><input type="email" class="crm-input" wire:model="draft.email"></x-sales-crm::field>
                <x-sales-crm::field :label="__('sales-crm::fields.company.phone')" error="draft.phone"><input type="text" class="crm-input" wire:model="draft.phone"></x-sales-crm::field>
                <x-sales-crm::field :label="__('sales-crm::fields.company.city')" error="draft.city"><input type="text" class="crm-input" wire:model="draft.city"></x-sales-crm::field>
                <x-sales-crm::field :label="__('sales-crm::fields.company.industry')" error="draft.industry"><input type="text" class="crm-input" wire:model="draft.industry"></x-sales-crm::field>
                @if ($duplicate)
                    <div class="rounded-md border border-warning bg-warning-soft p-3 text-[13px] text-warning sm:col-span-2" data-test="company-duplicate">
                        {{ $duplicate }}
                        <button type="button" class="crm-link ml-1 font-medium" wire:click="create(true)" data-test="company-create-anyway">{{ __('sales-crm::ui.create_anyway') }}</button>
                    </div>
                @endif
            </form>
            <x-slot:footer>
                <button type="button" class="crm-btn" x-on:click="$wire.set('showCreate', false)">{{ __('sales-crm::ui.cancel') }}</button>
                <button type="submit" form="crm-company-form" class="crm-btn crm-btn-primary" data-test="company-save">{{ __('sales-crm::ui.create') }}</button>
            </x-slot:footer>
        </x-sales-crm::modal>
    </x-sales-crm::shell>
</div>
