@php
    use Sellinnate\SalesCrm\Actions\SaveCompany;
    use Sellinnate\SalesCrm\Support\Format;
@endphp
<div>
    <x-sales-crm::shell :title="$company->name" :subtitle="$this->access()->resolver()->enabled() ? $this->access()->label($company->workspaceRef()) : $company->domain">
        <x-slot:actions>
            @if ($this->canDelete())
                <button type="button" class="crm-btn" wire:click="delete" wire:confirm="{{ __('sales-crm::ui.confirm_delete_company', ['name' => $company->name]) }}" data-test="company-delete"><x-sales-crm::icon name="trash" :size="14" /> {{ __('sales-crm::ui.delete') }}</button>
            @endif
        </x-slot:actions>
        <a href="{{ route('sales-crm.companies') }}" class="mb-3 inline-flex items-center gap-1 text-[13px] text-muted hover:text-ink"><x-sales-crm::icon name="arrow-left" :size="14" /> {{ __('sales-crm::ui.nav.companies') }}</a>
        <div class="grid gap-4 lg:grid-cols-[380px_1fr]">
            <div class="flex flex-col gap-4">
                <form wire:submit="save" class="crm-card crm-card-body grid gap-3" data-test="company-form">
                    @foreach (array_keys(SaveCompany::rules()) as $field)
                        <x-sales-crm::field :label="__('sales-crm::fields.company.'.$field)" :error="'form.'.$field">
                            @if ($field === 'notes')
                                <textarea rows="3" class="crm-input" wire:model="form.{{ $field }}"></textarea>
                            @else
                                <input type="text" class="crm-input" wire:model="form.{{ $field }}" data-test="company-form-{{ $field }}">
                            @endif
                        </x-sales-crm::field>
                    @endforeach
                    <div class="flex justify-end"><button type="submit" class="crm-btn crm-btn-primary" data-test="company-form-save">{{ __('sales-crm::ui.save') }}</button></div>
                </form>
            </div>
            <div class="flex flex-col gap-4">
                <section class="crm-card">
                    <h2 class="border-b border-line px-4 py-2.5 text-[13px] font-semibold">{{ __('sales-crm::ui.nav.deals') }} <span class="crm-count">{{ $company->deals->count() }}</span></h2>
                    <div class="divide-y divide-line">
                        @forelse ($company->deals as $deal)
                            <a href="{{ route('sales-crm.deals.show', $deal) }}" class="flex items-center justify-between gap-2 px-4 py-2 text-[13px] hover:bg-sunken" wire:key="company-deal-{{ $deal->id }}">
                                <span class="font-medium">{{ $deal->title }}</span>
                                <span class="flex items-center gap-2 text-xs text-muted"><span class="crm-dot {{ $deal->stage->color->dotClass() }}"></span>{{ $deal->stage->name }} · {{ $deal->isPriced() ? Format::money($deal->first_year_value_cents) : '—' }}</span>
                            </a>
                        @empty
                            <p class="px-4 py-4 text-xs text-muted">{{ __('sales-crm::ui.no_deals') }}</p>
                        @endforelse
                    </div>
                </section>
                <section class="crm-card">
                    <h2 class="border-b border-line px-4 py-2.5 text-[13px] font-semibold">{{ __('sales-crm::ui.nav.people') }} <span class="crm-count">{{ $company->people->count() }}</span></h2>
                    <div class="divide-y divide-line">
                        @forelse ($company->people as $person)
                            <a href="{{ route('sales-crm.people.show', $person) }}" class="flex items-center justify-between px-4 py-2 text-[13px] hover:bg-sunken" wire:key="company-person-{{ $person->id }}">
                                <span class="font-medium">{{ $person->name }}</span>
                                <span class="text-xs text-muted">{{ $person->job_title }}</span>
                            </a>
                        @empty
                            <p class="px-4 py-4 text-xs text-muted">{{ __('sales-crm::ui.no_people') }}</p>
                        @endforelse
                    </div>
                </section>
                <livewire:sales-crm.record-feed type="company" :record-id="$company->id" :key="'company-feed-'.$company->id" />
            </div>
        </div>
    </x-sales-crm::shell>
</div>
