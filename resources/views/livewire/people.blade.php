@php
    use Sellinnate\SalesCrm\Support\Phone;
@endphp
<div>
    <x-sales-crm::shell :title="__('sales-crm::ui.nav.people')">
        <x-slot:actions>
            <button type="button" class="crm-btn crm-btn-primary" wire:click="startCreate" data-test="person-new"><x-sales-crm::icon name="plus" :size="14" /> {{ __('sales-crm::ui.new_person') }}</button>
        </x-slot:actions>

        <div class="mb-3 flex flex-wrap items-center gap-2">
            <div class="relative w-full sm:w-80">
                <span class="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-faint"><x-sales-crm::icon name="search" :size="14" /></span>
                <input type="search" class="crm-input pl-8" wire:model.live.debounce.300ms="search" placeholder="{{ __('sales-crm::ui.search_people') }}" aria-label="{{ __('sales-crm::ui.search') }}" data-test="person-search">
            </div>
            @if ($this->workspaces !== [])
                <select class="crm-select w-auto" wire:model.live="workspace" aria-label="{{ __('sales-crm::ui.workspace') }}">
                    <option value="">{{ __('sales-crm::ui.all_workspaces') }}</option>
                    @foreach ($this->workspaces as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                </select>
            @endif
        </div>

        <div class="crm-card overflow-hidden" data-test="person-list">
            <table class="crm-table">
                <thead>
                    <tr>
                        <th>{{ __('sales-crm::ui.name') }}</th>
                        <th>{{ __('sales-crm::fields.person.company_id') }}</th>
                        <th>{{ __('sales-crm::fields.person.email') }}</th>
                        <th>{{ __('sales-crm::fields.person.mobile') }}</th>
                        <th class="text-right">{{ __('sales-crm::ui.nav.deals') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->people as $person)
                        <tr wire:key="person-{{ $person->id }}">
                            <td><a href="{{ route('sales-crm.people.show', $person) }}" class="font-medium text-ink hover:text-brand" data-test="person-link">{{ $person->name }}</a>@if ($person->job_title)<span class="block text-xs text-muted">{{ $person->job_title }}</span>@endif</td>
                            <td class="text-xs">@if ($person->company)<a href="{{ route('sales-crm.companies.show', $person->company) }}" class="crm-link">{{ $person->company->name }}</a>@endif</td>
                            <td class="text-xs">@if ($person->email)<a href="mailto:{{ $person->email }}" class="crm-link">{{ $person->email }}</a>@endif</td>
                            <td class="text-xs">@if ($number = $person->callableNumber())<a href="tel:{{ $number }}" class="crm-link">{{ Phone::display($number) }}</a>@endif</td>
                            <td class="crm-num text-right">{{ $person->deals_count }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5"><x-sales-crm::empty icon="users" :title="__('sales-crm::ui.no_people')" /></td></tr>
                    @endforelse
                </tbody>
            </table>
            @if ($this->people->hasPages())
                <div class="flex justify-end gap-1 border-t border-line px-3 py-2">
                    <button type="button" class="crm-btn crm-btn-sm" wire:click="previousPage" @disabled($this->people->onFirstPage())>{{ __('sales-crm::ui.previous') }}</button>
                    <button type="button" class="crm-btn crm-btn-sm" wire:click="nextPage" @disabled(! $this->people->hasMorePages())>{{ __('sales-crm::ui.next') }}</button>
                </div>
            @endif
        </div>

        <x-sales-crm::modal model="showCreate" :title="__('sales-crm::ui.new_person')" data-test="person-create-dialog">
            <form wire:submit="create" id="crm-person-form" class="grid gap-3 sm:grid-cols-2">
                @if ($this->workspaces !== [])
                    <x-sales-crm::field class="sm:col-span-2" :label="__('sales-crm::ui.workspace')">
                        <select class="crm-select" wire:model.live="draft.workspace">
                            @foreach ($this->workspaces as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                        </select>
                    </x-sales-crm::field>
                @endif
                <x-sales-crm::field :label="__('sales-crm::fields.person.first_name')" error="draft.first_name"><input type="text" class="crm-input" wire:model="draft.first_name" data-test="person-first-name"></x-sales-crm::field>
                <x-sales-crm::field :label="__('sales-crm::fields.person.last_name')" error="draft.last_name"><input type="text" class="crm-input" wire:model="draft.last_name"></x-sales-crm::field>
                <x-sales-crm::field :label="__('sales-crm::fields.person.email')" error="draft.email"><input type="email" class="crm-input" wire:model="draft.email" data-test="person-email"></x-sales-crm::field>
                <x-sales-crm::field :label="__('sales-crm::fields.person.mobile')" error="draft.mobile"><input type="text" class="crm-input" wire:model="draft.mobile"></x-sales-crm::field>
                <x-sales-crm::field :label="__('sales-crm::fields.person.job_title')" error="draft.job_title"><input type="text" class="crm-input" wire:model="draft.job_title"></x-sales-crm::field>
                <x-sales-crm::field :label="__('sales-crm::fields.person.company_id')" error="draft.company_id">
                    <select class="crm-select" wire:model="draft.company_id">
                        <option value="">—</option>
                        @foreach ($this->companies as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                    </select>
                </x-sales-crm::field>
                @if ($duplicate)
                    <div class="rounded-md border border-warning bg-warning-soft p-3 text-[13px] text-warning sm:col-span-2" data-test="person-duplicate">
                        {{ $duplicate }}
                        <button type="button" class="crm-link ml-1 font-medium" wire:click="create(true)">{{ __('sales-crm::ui.create_anyway') }}</button>
                    </div>
                @endif
            </form>
            <x-slot:footer>
                <button type="button" class="crm-btn" x-on:click="$wire.set('showCreate', false)">{{ __('sales-crm::ui.cancel') }}</button>
                <button type="submit" form="crm-person-form" class="crm-btn crm-btn-primary" data-test="person-save">{{ __('sales-crm::ui.create') }}</button>
            </x-slot:footer>
        </x-sales-crm::modal>
    </x-sales-crm::shell>
</div>
