@php
    /*
     * The fields a stage requires (and the reason of a loss), bound to `$model` (fill or draft) of the
     * component: the mini form of the board and the requirements of the quick add.
     */
    use Sellinnate\SalesCrm\Enums\ActivityType;
    use Sellinnate\SalesCrm\Enums\LostReason;
    use Sellinnate\SalesCrm\Enums\RecurringPeriod;
    use Sellinnate\SalesCrm\Enums\RequiredField;

    $owners = $owners ?? [];
    $withFile = $withFile ?? true;
@endphp
<div class="grid gap-3 sm:grid-cols-2" data-test="requirement-fields">
    @foreach ($fields as $field)
        @switch($field)
            @case(RequiredField::Company)
                <x-sales-crm::field class="sm:col-span-2" :label="$field->label()" :error="$model.'.company_name'">
                    <input type="text" class="crm-input" wire:model="{{ $model }}.company_name" list="crm-companies-{{ $model }}" data-test="req-company">
                </x-sales-crm::field>
                @break
            @case(RequiredField::Person)
                <x-sales-crm::field :label="__('sales-crm::fields.person.first_name')" :error="$model.'.person.first_name'">
                    <input type="text" class="crm-input" wire:model="{{ $model }}.person.first_name" data-test="req-person-first">
                </x-sales-crm::field>
                <x-sales-crm::field :label="__('sales-crm::fields.person.last_name')" :error="$model.'.person.last_name'">
                    <input type="text" class="crm-input" wire:model="{{ $model }}.person.last_name">
                </x-sales-crm::field>
                <x-sales-crm::field :label="__('sales-crm::fields.person.email')" :error="$model.'.person.email'">
                    <input type="email" class="crm-input" wire:model="{{ $model }}.person.email">
                </x-sales-crm::field>
                <x-sales-crm::field :label="__('sales-crm::fields.person.mobile')" :error="$model.'.person.mobile'">
                    <input type="text" class="crm-input" wire:model="{{ $model }}.person.mobile">
                </x-sales-crm::field>
                @break
            @case(RequiredField::Owner)
                <x-sales-crm::field :label="$field->label()" :error="$model.'.owner_id'">
                    <select class="crm-select" wire:model="{{ $model }}.owner_id" data-test="req-owner">
                        <option value="">—</option>
                        @foreach ($owners as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </x-sales-crm::field>
                @break
            @case(RequiredField::Amount)
                <x-sales-crm::field :label="__('sales-crm::fields.deal.amount_once')" :error="$model.'.amount_once'">
                    <input type="number" min="0" step="0.01" class="crm-input crm-num" wire:model="{{ $model }}.amount_once" data-test="req-amount">
                </x-sales-crm::field>
                <div class="grid grid-cols-[1fr_auto] gap-2">
                    <x-sales-crm::field :label="__('sales-crm::fields.deal.recurring')" :error="$model.'.recurring'">
                        <input type="number" min="0" step="0.01" class="crm-input crm-num" wire:model="{{ $model }}.recurring">
                    </x-sales-crm::field>
                    <x-sales-crm::field :label="__('sales-crm::fields.deal.recurring_period')">
                        <select class="crm-select" wire:model="{{ $model }}.recurring_period">
                            @foreach (RecurringPeriod::options() as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </x-sales-crm::field>
                </div>
                @break
            @case(RequiredField::ExpectedCloseDate)
                <x-sales-crm::field :label="$field->label()" :error="$model.'.expected_close_date'">
                    <input type="date" class="crm-input" wire:model="{{ $model }}.expected_close_date" data-test="req-close-date">
                </x-sales-crm::field>
                @break
            @case(RequiredField::OfferValidUntil)
                <x-sales-crm::field :label="$field->label()" :error="$model.'.offer_valid_until'">
                    <input type="date" class="crm-input" wire:model="{{ $model }}.offer_valid_until" data-test="req-offer-valid">
                </x-sales-crm::field>
                @break
            @case(RequiredField::PaymentTerms)
                <x-sales-crm::field class="sm:col-span-2" :label="$field->label()" :error="$model.'.payment_terms'">
                    <textarea rows="2" class="crm-input" wire:model="{{ $model }}.payment_terms"></textarea>
                </x-sales-crm::field>
                @break
            @case(RequiredField::NextActivity)
                <div class="grid gap-2 sm:col-span-2 sm:grid-cols-[auto_1fr_auto_auto]">
                    <x-sales-crm::field :label="__('sales-crm::fields.activity.type')">
                        <select class="crm-select" wire:model="{{ $model }}.next_activity.type">
                            @foreach (ActivityType::options() as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </x-sales-crm::field>
                    <x-sales-crm::field :label="__('sales-crm::ui.next_activity')" :error="$model.'.next_activity.title'">
                        <input type="text" class="crm-input" wire:model="{{ $model }}.next_activity.title" data-test="req-activity-title">
                    </x-sales-crm::field>
                    <x-sales-crm::field :label="__('sales-crm::fields.activity.due_date')" :error="$model.'.next_activity.due_date'">
                        <input type="date" class="crm-input" wire:model="{{ $model }}.next_activity.due_date">
                    </x-sales-crm::field>
                    <x-sales-crm::field :label="__('sales-crm::fields.activity.due_time')">
                        <input type="time" class="crm-input" wire:model="{{ $model }}.next_activity.due_time">
                    </x-sales-crm::field>
                </div>
                @break
            @case(RequiredField::OfferDocument)
                @if ($withFile)
                    <x-sales-crm::field class="sm:col-span-2" :label="$field->label()" :error="$model.'.offer_document'">
                        <input type="file" class="crm-input" wire:model="{{ $model }}.offer_document" data-test="req-offer-document">
                    </x-sales-crm::field>
                    @if (! in_array(RequiredField::OfferValidUntil, $fields, true))
                        <x-sales-crm::field :label="__('sales-crm::fields.deal.offer_valid_until')" :error="$model.'.offer_valid_until'">
                            <input type="date" class="crm-input" wire:model="{{ $model }}.offer_valid_until">
                        </x-sales-crm::field>
                    @endif
                @endif
                @break
        @endswitch
    @endforeach

    @if ($lostReason)
        <x-sales-crm::field :label="__('sales-crm::fields.deal.lost_reason')" :error="$model.'.lost_reason'">
            <select class="crm-select" wire:model="{{ $model }}.lost_reason" data-test="req-lost-reason">
                <option value="">—</option>
                @foreach (LostReason::options() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </x-sales-crm::field>
        <x-sales-crm::field :label="__('sales-crm::fields.deal.lost_note')" :error="$model.'.lost_note'">
            <input type="text" class="crm-input" wire:model="{{ $model }}.lost_note">
        </x-sales-crm::field>
    @endif
</div>
@if (isset($companies) && $companies !== [])
    <datalist id="crm-companies-{{ $model }}">
        @foreach ($companies as $name)
            <option value="{{ $name }}"></option>
        @endforeach
    </datalist>
@endif
