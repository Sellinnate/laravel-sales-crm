@php
    use Sellinnate\SalesCrm\Enums\ActivityType;
    use Sellinnate\SalesCrm\Support\Crm;
    use Sellinnate\SalesCrm\Support\Format;
    use Sellinnate\SalesCrm\Support\Phone;
@endphp
<div>
    <x-sales-crm::shell :title="__('sales-crm::ui.nav.agenda')" :subtitle="__('sales-crm::ui.agenda_subtitle')">
        <div class="mb-3 flex flex-wrap items-center gap-2">
            <div class="crm-segmented">
                <button type="button" wire:click="$set('scope', 'mine')" aria-pressed="{{ $scope === 'mine' ? 'true' : 'false' }}" data-test="agenda-mine">{{ __('sales-crm::ui.agenda_mine') }}</button>
                <button type="button" wire:click="$set('scope', 'all')" aria-pressed="{{ $scope === 'all' ? 'true' : 'false' }}" data-test="agenda-all">{{ __('sales-crm::ui.agenda_all') }}</button>
            </div>
            <select class="crm-select crm-input-sm w-40" wire:model.live="type" aria-label="{{ __('sales-crm::fields.activity.type') }}">
                <option value="">{{ __('sales-crm::ui.any_type') }}</option>
                @foreach (ActivityType::options() as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
            </select>
            @if ($this->workspaces !== [])
                <select class="crm-select crm-input-sm w-48" wire:model.live="workspace" aria-label="{{ __('sales-crm::ui.workspace') }}">
                    <option value="">{{ __('sales-crm::ui.all_workspaces') }}</option>
                    @foreach ($this->workspaces as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                </select>
            @endif
        </div>

        <div class="grid gap-4 lg:grid-cols-3" data-test="agenda">
            @foreach (['overdue' => 'danger', 'today' => 'warning', 'upcoming' => 'neutral'] as $group => $tone)
                <section class="crm-card" data-test="agenda-{{ $group }}">
                    <header class="flex items-center justify-between border-b border-line px-4 py-2.5">
                        <h2 class="text-[13px] font-semibold text-ink">{{ __('sales-crm::ui.agenda.'.$group) }}</h2>
                        <x-sales-crm::badge :tone="$tone">{{ $this->groups[$group]->count() }}</x-sales-crm::badge>
                    </header>
                    <div class="flex flex-col divide-y divide-line">
                        @forelse ($this->groups[$group] as $activity)
                            @php $person = $activity->person ?? $activity->deal?->people()->first(); @endphp
                            <div class="flex items-start gap-3 px-4 py-2.5" wire:key="agenda-{{ $activity->id }}">
                                <button type="button" class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full border border-line-strong text-transparent hover:border-success hover:text-success" wire:click="complete({{ $activity->id }})" title="{{ __('sales-crm::ui.mark_done') }}" aria-label="{{ __('sales-crm::ui.mark_done') }}" data-test="agenda-complete-{{ $activity->id }}"><x-sales-crm::icon name="check" :size="12" /></button>
                                <div class="min-w-0 flex-auto">
                                    <p class="text-[13px] font-medium text-ink"><x-sales-crm::icon :name="$activity->type->icon()" :size="13" class="text-muted" /> {{ $activity->title }}</p>
                                    <p class="text-xs text-muted">
                                        {{ $activity->has_time ? Format::dateTime($activity->due_at) : Format::date($activity->due_at) }}
                                        @if ($scope === 'all' && $activity->assignee) · {{ Crm::userName($activity->assignee) }}@endif
                                    </p>
                                    <p class="truncate text-xs">
                                        @if ($activity->deal)<a href="{{ route('sales-crm.deals.show', $activity->deal) }}" class="crm-link">{{ $activity->deal->title }}</a>@elseif ($activity->company)<a href="{{ route('sales-crm.companies.show', $activity->company) }}" class="crm-link">{{ $activity->company->name }}</a>@elseif ($activity->person)<a href="{{ route('sales-crm.people.show', $activity->person) }}" class="crm-link">{{ $activity->person->name }}</a>@endif
                                        @if ($person && ($number = $person->callableNumber())) · <a href="tel:{{ $number }}" class="crm-link">{{ Phone::display($number) }}</a>@endif
                                    </p>
                                </div>
                            </div>
                        @empty
                            <p class="px-4 py-6 text-center text-xs text-muted">{{ __('sales-crm::ui.agenda_empty') }}</p>
                        @endforelse
                    </div>
                </section>
            @endforeach
        </div>
    </x-sales-crm::shell>
</div>
