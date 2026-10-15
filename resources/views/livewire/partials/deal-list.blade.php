@php
    /*
     * The list of deals: dense table with the columns chosen, sorting, inline edit of stage, owner,
     * priority, next step, expected close and amount, multiple selection and bulk actions.
     */
    use Sellinnate\SalesCrm\Enums\Priority;
    use Sellinnate\SalesCrm\Services\DealColumns;
    use Sellinnate\SalesCrm\Support\Crm;

    $columns = $this->listColumnsChosen();
    $custom = $this->customFields();
    $deals = $this->deals;
    $owners = $this->owners;
    $canManage = $this->canManageScope();
@endphp
<div class="crm-card overflow-hidden" data-test="crm-list">
    @if ($selected !== [])
        <div class="flex flex-wrap items-center gap-2 border-b border-line bg-brand-soft/40 px-3 py-2 text-[13px]" data-test="crm-bulk-bar">
            <span class="font-medium">{{ trans_choice('sales-crm::ui.selected', count($selected), ['count' => count($selected)]) }}</span>
            <select class="crm-select crm-input-sm w-44" wire:model.live="bulkAction" data-test="crm-bulk-action">
                <option value="">{{ __('sales-crm::ui.bulk_choose') }}</option>
                @if ($this->scope() !== null || ! $this->access()->resolver()->enabled())
                    <option value="stage">{{ __('sales-crm::ui.bulk.stage') }}</option>
                    <option value="owner">{{ __('sales-crm::ui.bulk.owner') }}</option>
                @endif
                <option value="priority">{{ __('sales-crm::ui.bulk.priority') }}</option>
                @if ($canManage)
                    <option value="delete">{{ __('sales-crm::ui.bulk.delete') }}</option>
                @endif
            </select>
            @if ($bulkAction === 'stage')
                <select class="crm-select crm-input-sm w-44" wire:model="bulkValue" data-test="crm-bulk-value">
                    <option value="">—</option>
                    @foreach ($this->currentPipeline?->stages ?? [] as $stage)
                        <option value="{{ $stage->id }}">{{ $stage->name }}</option>
                    @endforeach
                </select>
            @elseif ($bulkAction === 'owner')
                <select class="crm-select crm-input-sm w-44" wire:model="bulkValue">
                    <option value="">{{ __('sales-crm::ui.reports.no_owner') }}</option>
                    @foreach ($owners as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            @elseif ($bulkAction === 'priority')
                <select class="crm-select crm-input-sm w-36" wire:model="bulkValue">
                    <option value="">—</option>
                    @foreach (Priority::options() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            @endif
            <button type="button" class="crm-btn crm-btn-primary crm-btn-sm" wire:click="applyBulk" @if ($bulkAction === 'delete') wire:confirm="{{ __('sales-crm::ui.confirm_bulk_delete') }}" @endif data-test="crm-bulk-apply">{{ __('sales-crm::ui.apply') }}</button>
            <button type="button" class="crm-btn crm-btn-ghost crm-btn-sm" wire:click="$set('selected', [])">{{ __('sales-crm::ui.clear_selection') }}</button>
        </div>
    @endif

    <div class="overflow-x-auto">
        <table class="crm-table">
            <thead>
                <tr>
                    <th class="w-8"><input type="checkbox" class="crm-checkbox" aria-label="{{ __('sales-crm::ui.select_all') }}" x-on:change="$wire.set('selected', $event.target.checked ? @js($deals->pluck('id')->map(fn ($id) => (string) $id)->values()) : [])"></th>
                    <th>
                        <button type="button" class="inline-flex items-center gap-1" wire:click="sortBy('title')">{{ __('sales-crm::fields.deal.title') }}@if ($sort === 'title')<x-sales-crm::icon :name="$direction === 'asc' ? 'chevron-down' : 'chevron-right'" :size="12" />@endif</button>
                    </th>
                    @foreach ($columns as $key => $label)
                        <th>
                            @if (isset(DealColumns::SORTABLE[$key]))
                                <button type="button" class="inline-flex items-center gap-1" wire:click="sortBy('{{ DealColumns::SORTABLE[$key] }}')">{{ $label }}@if ($sort === DealColumns::SORTABLE[$key])<x-sales-crm::icon :name="$direction === 'asc' ? 'chevron-down' : 'chevron-right'" :size="12" />@endif</button>
                            @else
                                {{ $label }}
                            @endif
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($deals as $deal)
                    <tr wire:key="list-deal-{{ $deal->id }}" data-test="crm-list-row">
                        <td><input type="checkbox" class="crm-checkbox" wire:model.live="selected" value="{{ $deal->id }}" aria-label="{{ $deal->title }}"></td>
                        <td class="min-w-56">
                            <button type="button" class="text-left font-medium text-ink hover:text-brand" wire:click="openDeal({{ $deal->id }})" data-test="crm-list-open">{{ $deal->title }}</button>
                        </td>
                        @foreach ($columns as $key => $label)
                            <td @class(['crm-num text-right' => in_array($key, ['amount_once', 'recurring', 'first_year', 'weighted'], true), 'whitespace-nowrap' => ! in_array($key, ['next_step'], true)])>
                                @switch($key)
                                    @case('stage')
                                        <select class="crm-select crm-input-sm w-40" x-on:change="$wire.updateField({{ $deal->id }}, 'stage_id', $event.target.value)" aria-label="{{ $label }}">
                                            @foreach ($deal->pipeline->stages as $stage)
                                                <option value="{{ $stage->id }}" @selected($stage->id === $deal->stage_id)>{{ $stage->name }}</option>
                                            @endforeach
                                        </select>
                                        @break
                                    @case('owner')
                                        <select class="crm-select crm-input-sm w-40" x-on:change="$wire.updateField({{ $deal->id }}, 'owner_id', $event.target.value)" aria-label="{{ $label }}">
                                            <option value="">—</option>
                                            @if ($deal->owner && ! array_key_exists((string) $deal->owner_id, $owners))
                                                <option value="{{ $deal->owner_id }}" selected>{{ Crm::userName($deal->owner) }}</option>
                                            @endif
                                            @foreach ($owners as $id => $name)
                                                <option value="{{ $id }}" @selected((string) $deal->owner_id === (string) $id)>{{ $name }}</option>
                                            @endforeach
                                        </select>
                                        @break
                                    @case('priority')
                                        <select class="crm-select crm-input-sm w-24" x-on:change="$wire.updateField({{ $deal->id }}, 'priority', $event.target.value)" aria-label="{{ $label }}">
                                            <option value="">—</option>
                                            @foreach (Priority::options() as $value => $name)
                                                <option value="{{ $value }}" @selected($deal->priority?->value === $value)>{{ $name }}</option>
                                            @endforeach
                                        </select>
                                        @break
                                    @case('next_step')
                                        <input type="text" class="crm-input crm-input-sm min-w-48" value="{{ $deal->next_step }}" x-on:change="$wire.updateField({{ $deal->id }}, 'next_step', $event.target.value)" aria-label="{{ $label }}">
                                        @break
                                    @case('close')
                                        <input type="date" class="crm-input crm-input-sm w-36" value="{{ $deal->expected_close_date?->toDateString() }}" x-on:change="$wire.updateField({{ $deal->id }}, 'expected_close_date', $event.target.value)" aria-label="{{ $label }}">
                                        @break
                                    @case('amount_once')
                                        <input type="number" min="0" step="0.01" class="crm-input crm-input-sm w-28 text-right" value="{{ $deal->amount_once_cents === null ? '' : $deal->amount_once_cents / 100 }}" x-on:change="$wire.updateField({{ $deal->id }}, 'amount_once', $event.target.value)" aria-label="{{ $label }}">
                                        @break
                                    @default
                                        {{ DealColumns::text($deal, $key, $custom) }}
                                @endswitch
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td colspan="{{ count($columns) + 2 }}"><x-sales-crm::empty icon="search" :title="__('sales-crm::ui.no_deals')" :description="__('sales-crm::ui.no_deals_hint')" /></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($deals->hasPages())
        <div class="flex items-center justify-between border-t border-line px-3 py-2 text-xs text-muted">
            <span>{{ __('sales-crm::ui.page_of', ['from' => $deals->firstItem(), 'to' => $deals->lastItem(), 'total' => $deals->total()]) }}</span>
            <div class="flex gap-1">
                <button type="button" class="crm-btn crm-btn-sm" wire:click="previousPage" @disabled($deals->onFirstPage())>{{ __('sales-crm::ui.previous') }}</button>
                <button type="button" class="crm-btn crm-btn-sm" wire:click="nextPage" @disabled(! $deals->hasMorePages())>{{ __('sales-crm::ui.next') }}</button>
            </div>
        </div>
    @endif
</div>
