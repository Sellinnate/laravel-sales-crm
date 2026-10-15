@php
    use Sellinnate\SalesCrm\Livewire\PersonPage;
    use Sellinnate\SalesCrm\Support\Format;
@endphp
<div>
    <x-sales-crm::shell :title="$person->name" :subtitle="trim(($person->job_title ?? '').($person->company ? ' · '.$person->company->name : ''), ' ·')">
        <x-slot:actions>
            @if ($this->canDelete())
                <button type="button" class="crm-btn" wire:click="delete" wire:confirm="{{ __('sales-crm::ui.confirm_delete_person', ['name' => $person->name]) }}" data-test="person-delete"><x-sales-crm::icon name="trash" :size="14" /> {{ __('sales-crm::ui.delete') }}</button>
            @endif
        </x-slot:actions>
        <a href="{{ route('sales-crm.people') }}" class="mb-3 inline-flex items-center gap-1 text-[13px] text-muted hover:text-ink"><x-sales-crm::icon name="arrow-left" :size="14" /> {{ __('sales-crm::ui.nav.people') }}</a>
        <div class="grid gap-4 lg:grid-cols-[380px_1fr]">
            <form wire:submit="save" class="crm-card crm-card-body grid h-fit gap-3" data-test="person-form">
                @foreach (PersonPage::FIELDS as $field)
                    <x-sales-crm::field :label="__('sales-crm::fields.person.'.$field)" :error="'form.'.$field">
                        @if ($field === 'company_id')
                            <select class="crm-select" wire:model="form.company_id">
                                <option value="">—</option>
                                @foreach ($this->companies as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                            </select>
                        @elseif ($field === 'notes')
                            <textarea rows="3" class="crm-input" wire:model="form.notes"></textarea>
                        @else
                            <input type="text" class="crm-input" wire:model="form.{{ $field }}" data-test="person-form-{{ $field }}">
                        @endif
                    </x-sales-crm::field>
                @endforeach
                <div class="flex justify-end"><button type="submit" class="crm-btn crm-btn-primary" data-test="person-form-save">{{ __('sales-crm::ui.save') }}</button></div>
            </form>
            <div class="flex flex-col gap-4">
                <section class="crm-card">
                    <h2 class="border-b border-line px-4 py-2.5 text-[13px] font-semibold">{{ __('sales-crm::ui.nav.deals') }} <span class="crm-count">{{ $person->deals->count() }}</span></h2>
                    <div class="divide-y divide-line">
                        @forelse ($person->deals as $deal)
                            <a href="{{ route('sales-crm.deals.show', $deal) }}" class="flex items-center justify-between gap-2 px-4 py-2 text-[13px] hover:bg-sunken" wire:key="person-deal-{{ $deal->id }}">
                                <span class="font-medium">{{ $deal->title }}</span>
                                <span class="flex items-center gap-2 text-xs text-muted"><span class="crm-dot {{ $deal->stage->color->dotClass() }}"></span>{{ $deal->stage->name }} · {{ $deal->isPriced() ? Format::money($deal->first_year_value_cents) : '—' }}</span>
                            </a>
                        @empty
                            <p class="px-4 py-4 text-xs text-muted">{{ __('sales-crm::ui.no_deals') }}</p>
                        @endforelse
                    </div>
                </section>
                <livewire:sales-crm.record-feed type="person" :record-id="$person->id" :key="'person-feed-'.$person->id" />
            </div>
        </div>
    </x-sales-crm::shell>
</div>
