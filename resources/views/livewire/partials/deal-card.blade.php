@php
    /*
     * A card of the board: what matters at a glance, without opening anything. `$fields` limits
     * what the card shows (null: everything).
     */
    use Sellinnate\SalesCrm\Enums\PaymentStatus;
    use Sellinnate\SalesCrm\Enums\SignatureStatus;
    use Sellinnate\SalesCrm\Support\Crm;
    use Sellinnate\SalesCrm\Support\Format;
    use Sellinnate\SalesCrm\Support\Phone;

    $show = fn (string $field): bool => $fields === null || in_array($field, $fields, true);
    $now = \Carbon\CarbonImmutable::now();
    $person = $deal->primaryPerson();
    $next = $deal->nextActivityState($now);
    $stale = $deal->staleDays($now);
    $offer = $deal->offerState($now);
    $amount = collect([
        $deal->amount_once_cents ? Format::money($deal->amount_once_cents) : null,
        $deal->recurring_cents && $deal->recurring_period ? Format::money($deal->recurring_cents).$deal->recurring_period->suffix() : null,
    ])->filter()->implode(' + ');
    $nextTone = ['overdue' => 'danger', 'today' => 'warning', 'planned' => 'neutral', 'none' => 'warning'][$next];
@endphp
<article
    wire:key="deal-card-{{ $deal->id }}"
    data-deal="{{ $deal->id }}"
    draggable="true"
    tabindex="0"
    x-on:dragstart="start($event)"
    x-on:dragend="end()"
    x-on:click="if (! $event.target.closest('a, button, [data-no-open]')) $wire.openDeal({{ $deal->id }})"
    x-on:keydown.enter.self.prevent="$wire.openDeal({{ $deal->id }})"
    @class(['crm-deal', 'crm-deal-closed' => ! $deal->isOpen()])
    aria-label="{{ $deal->title }}"
    data-test="deal-card"
>
    <div class="flex items-start gap-2">
        <div class="min-w-0 flex-auto">
            <div class="truncate text-[11px] text-muted" data-test="deal-card-company">{{ $deal->company?->name ?? __('sales-crm::ui.no_company') }}</div>
            <div class="line-clamp-2 text-[13px] font-semibold text-ink" data-test="deal-card-title">{{ $deal->title }}</div>
        </div>
        @if ($show('priority') && $deal->priority)
            <x-sales-crm::badge :tone="$deal->priority->tone()">{{ $deal->priority->label() }}</x-sales-crm::badge>
        @endif
        <div class="-mr-1 -mt-1 shrink-0" data-no-open>
            <x-sales-crm::dropdown align="right" width="w-56">
                <x-slot:trigger>
                    <button type="button" class="crm-btn-icon size-6" aria-label="{{ __('sales-crm::ui.deal_actions') }}" data-test="deal-card-menu"><x-sales-crm::icon name="more-vertical" :size="14" /></button>
                </x-slot:trigger>
                <button type="button" class="crm-menu-item" data-close wire:click="openDeal({{ $deal->id }})"><x-sales-crm::icon name="eye" :size="14" /> {{ __('sales-crm::ui.open_panel') }}</button>
                <a class="crm-menu-item" href="{{ route('sales-crm.deals.show', $deal) }}"><x-sales-crm::icon name="external-link" :size="14" /> {{ __('sales-crm::ui.open_page') }}</a>
                <div class="crm-menu-label">{{ __('sales-crm::ui.move_to') }}</div>
                @foreach ($stages as $stage)
                    @if ($stage->id !== $deal->stage_id)
                        <button type="button" class="crm-menu-item" data-close wire:click="moveTo({{ $deal->id }}, {{ $stage->id }})" data-test="deal-move-{{ $stage->id }}"><span class="crm-dot {{ $stage->color->dotClass() }}"></span> {{ $stage->name }}</button>
                    @endif
                @endforeach
            </x-sales-crm::dropdown>
        </div>
    </div>

    @if (($show('amount') && $amount !== '') || ($show('owner') && $deal->owner))
        <div class="flex items-center gap-2">
            <span class="crm-num min-w-0 flex-auto truncate text-[13px] text-ink" data-test="deal-card-amount">{{ $show('amount') ? $amount : '' }}</span>
            @if ($show('owner') && $deal->owner)
                <x-sales-crm::avatar :name="Crm::userName($deal->owner)" :size="20" />
            @endif
        </div>
    @endif

    <div class="flex flex-wrap items-center gap-1">
        @if ($show('next_activity') && $deal->isOpen())
            <x-sales-crm::badge :tone="$nextTone" icon="calendar-clock" data-test="deal-card-next" data-state="{{ $next }}">
                @switch($next)
                    @case('none') {{ __('sales-crm::ui.no_activity') }} @break
                    @case('today') {{ __('sales-crm::ui.today') }} {{ $deal->nextActivityHasTime() ? Format::time($deal->next_activity_at) : '' }} @break
                    @case('overdue') {{ __('sales-crm::ui.overdue') }} {{ Format::shortDate($deal->next_activity_at) }} @break
                    @default {{ Format::shortDate($deal->next_activity_at) }}
                @endswitch
            </x-sales-crm::badge>
        @endif
        @if ($show('stage_age') && $deal->isOpen())
            @if ($stale !== null)
                <x-sales-crm::badge tone="danger" icon="timer" data-test="deal-card-stale">{{ __('sales-crm::ui.stale_for', ['days' => $stale]) }}</x-sales-crm::badge>
            @else
                <span class="text-[11px] text-faint" title="{{ __('sales-crm::ui.days_in_stage') }}">{{ __('sales-crm::ui.days_short', ['days' => $deal->daysInStage($now)]) }}</span>
            @endif
        @endif
        @if ($show('offer') && $offer !== null)
            <x-sales-crm::badge :tone="$offer === 'expired' ? 'danger' : 'warning'" icon="receipt" data-test="deal-card-offer">{{ $offer === 'expired' ? __('sales-crm::ui.offer_expired') : __('sales-crm::ui.offer_expiring') }}</x-sales-crm::badge>
        @endif
        @if ($show('signature') && $deal->signature_status !== SignatureStatus::NotVerified)
            <x-sales-crm::badge :tone="$deal->signature_status->tone()" icon="file-check">{{ $deal->signature_status->label() }}</x-sales-crm::badge>
        @endif
        @if ($show('payment') && $deal->payment_status !== PaymentStatus::NotVerified)
            <x-sales-crm::badge :tone="$deal->payment_status->tone()" icon="coins">{{ $deal->payment_status->label() }}</x-sales-crm::badge>
        @endif
        @if ($show('approval') && $deal->approval_required && $deal->approval_status?->value === 'pending')
            <x-sales-crm::badge tone="warning" icon="lock">{{ __('sales-crm::ui.to_approve') }}</x-sales-crm::badge>
        @endif
        @if ($show('close_date') && $deal->expected_close_date && $deal->isOpen())
            <span class="text-[11px] text-muted" title="{{ __('sales-crm::fields.deal.expected_close_date') }}">→ {{ Format::date($deal->expected_close_date) }}</span>
        @endif
        @if ($show('blocker') && filled($deal->blocker))
            <span class="text-warning" title="{{ __('sales-crm::fields.deal.blocker') }}: {{ \Illuminate\Support\Str::limit($deal->blocker, 160) }}" data-test="deal-card-blocker"><x-sales-crm::icon name="alert" :size="14" /></span>
        @endif
        @if ($show('tags'))
            @foreach (array_slice($deal->tags ?? [], 0, 3) as $tag)
                <span class="rounded bg-sunken px-1 text-[11px] text-muted">#{{ $tag }}</span>
            @endforeach
        @endif
    </div>

    @if ($show('contact') && $person)
        <div class="flex items-center gap-1.5 border-t border-line pt-1.5 text-[11px] text-muted" data-no-open>
            <x-sales-crm::icon name="user" :size="12" />
            <span class="min-w-0 flex-auto truncate" data-test="deal-card-person">{{ $person->name }}</span>
            @if ($number = $person->callableNumber())
                <a href="tel:{{ $number }}" class="rounded p-0.5 text-brand" title="{{ __('sales-crm::ui.call_number', ['number' => Phone::display($number)]) }}"><x-sales-crm::icon name="phone" :size="13" /></a>
            @endif
            @if ($person->email)
                <a href="mailto:{{ $person->email }}" class="rounded p-0.5 text-brand" title="{{ __('sales-crm::ui.write_to', ['email' => $person->email]) }}"><x-sales-crm::icon name="mail" :size="13" /></a>
            @endif
        </div>
    @endif
</article>
