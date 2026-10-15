@php
    use Sellinnate\SalesCrm\Enums\ActivityType;
    use Sellinnate\SalesCrm\Enums\ApprovalStatus;
    use Sellinnate\SalesCrm\Enums\CommissionType;
    use Sellinnate\SalesCrm\Enums\DealSource;
    use Sellinnate\SalesCrm\Enums\DocumentKind;
    use Sellinnate\SalesCrm\Enums\LostReason;
    use Sellinnate\SalesCrm\Enums\PaymentStatus;
    use Sellinnate\SalesCrm\Enums\PersonRole;
    use Sellinnate\SalesCrm\Enums\Priority;
    use Sellinnate\SalesCrm\Enums\RecurringPeriod;
    use Sellinnate\SalesCrm\Enums\SignatureStatus;
    use Sellinnate\SalesCrm\Actions\ManageActivities;
    use Sellinnate\SalesCrm\Services\EmailTemplates;
    use Sellinnate\SalesCrm\Support\Crm;
    use Sellinnate\SalesCrm\Support\Format;
    use Sellinnate\SalesCrm\Support\Phone;

    $deal = $this->model;
    $members = $this->members;
    $now = \Carbon\CarbonImmutable::now();
    $multi = $this->access()->resolver()->enabled();
    $section = 'grid gap-3 sm:grid-cols-2';
@endphp
<div class="flex flex-col gap-4 p-5" data-test="deal-panel" data-deal-id="{{ $deal->id }}">
    {{-- Header --}}
    <div class="flex flex-col gap-2">
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="text-xs text-muted">
                    @if ($multi){{ $this->access()->label($deal->workspaceRef()) }} · @endif{{ $deal->pipeline->name }}
                    @if ($deal->company)
                        · <a href="{{ route('sales-crm.companies.show', $deal->company) }}" class="crm-link">{{ $deal->company->name }}</a>
                    @endif
                </p>
                <h2 class="text-lg font-semibold text-ink" data-test="deal-panel-title">{{ $deal->title }}</h2>
            </div>
            <div class="flex shrink-0 items-center gap-1">
                @if (! $page)
                    <a href="{{ route('sales-crm.deals.show', $deal) }}" class="crm-btn-icon" title="{{ __('sales-crm::ui.open_page') }}" aria-label="{{ __('sales-crm::ui.open_page') }}"><x-sales-crm::icon name="external-link" /></a>
                @endif
                @if ($this->canDelete())
                    <button type="button" class="crm-btn-icon hover:text-danger" wire:click="deleteDeal" wire:confirm="{{ __('sales-crm::ui.confirm_delete_deal', ['title' => $deal->title]) }}" title="{{ __('sales-crm::ui.delete') }}" aria-label="{{ __('sales-crm::ui.delete') }}" data-test="deal-delete"><x-sales-crm::icon name="trash" /></button>
                @endif
            </div>
        </div>

        {{-- Stage path --}}
        <div class="flex flex-wrap gap-1" role="group" aria-label="{{ __('sales-crm::fields.deal.stage_id') }}" data-test="deal-stage-path">
            @foreach ($deal->pipeline->stages as $stage)
                <button type="button" wire:click="moveTo({{ $stage->id }})"
                    @class(['inline-flex items-center gap-1 rounded-full border px-2.5 py-0.5 text-xs font-medium', 'border-brand bg-brand text-brand-ink' => $stage->id === $deal->stage_id, 'border-line text-muted hover:border-line-strong hover:text-ink' => $stage->id !== $deal->stage_id])
                    @if ($stage->id === $deal->stage_id) aria-current="step" @endif data-test="deal-stage-{{ $stage->id }}">
                    <span class="crm-dot {{ $stage->color->dotClass() }}"></span>{{ $stage->name }}
                </button>
            @endforeach
        </div>

        <div class="flex flex-wrap items-center gap-1.5 text-xs">
            @if ($deal->isWon())<x-sales-crm::badge tone="success" icon="award">{{ __('sales-crm::ui.won_on', ['date' => Format::date($deal->won_at)]) }}</x-sales-crm::badge>@endif
            @if ($deal->isLost())<x-sales-crm::badge tone="danger" icon="circle-x">{{ __('sales-crm::ui.lost_because', ['reason' => $deal->lost_reason?->label()]) }}</x-sales-crm::badge>@endif
            @if ($deal->isPriced())<x-sales-crm::badge tone="brand">{{ __('sales-crm::ui.columns.first_year') }}: {{ Format::money($deal->first_year_value_cents) }}</x-sales-crm::badge>@endif
            @if ($deal->mrrCents() !== null)<x-sales-crm::badge>MRR {{ Format::money($deal->mrrCents()) }} · ARR {{ Format::money($deal->arrCents()) }}</x-sales-crm::badge>@endif
            @if ($deal->isOpen() && $deal->isPriced())<x-sales-crm::badge>{{ __('sales-crm::ui.columns.weighted') }}: {{ Format::money($deal->weightedCents()) }}</x-sales-crm::badge>@endif
            @if ($deal->offerState($now) === 'expired')<x-sales-crm::badge tone="danger" icon="receipt">{{ __('sales-crm::ui.offer_expired') }}</x-sales-crm::badge>@elseif ($deal->offerState($now) === 'expiring')<x-sales-crm::badge tone="warning" icon="receipt">{{ __('sales-crm::ui.offer_expiring') }}</x-sales-crm::badge>@endif
            @if (($stale = $deal->staleDays($now)) !== null)<x-sales-crm::badge tone="danger" icon="timer">{{ __('sales-crm::ui.stale_for', ['days' => $stale]) }}</x-sales-crm::badge>@endif
        </div>
    </div>

    {{-- Next step and next activity --}}
    <div class="grid gap-3 rounded-lg border border-line bg-sunken/50 p-3 sm:grid-cols-2">
        <x-sales-crm::field :label="__('sales-crm::fields.deal.next_step')" error="form.next_step">
            <textarea rows="2" class="crm-input" wire:model="form.next_step" wire:blur="save" data-test="deal-next-step"></textarea>
        </x-sales-crm::field>
        <div>
            <p class="crm-label">{{ __('sales-crm::ui.next_activity') }}</p>
            @php $nextActivity = $this->openActivities->first(); @endphp
            @if ($nextActivity)
                <p class="text-[13px] font-medium text-ink">{{ $nextActivity->type->label() }} · {{ $nextActivity->title }}</p>
                <p @class(['text-xs', 'text-danger' => $nextActivity->state($now) === 'overdue', 'text-warning' => $nextActivity->state($now) === 'today', 'text-muted' => $nextActivity->state($now) === 'planned'])>{{ $nextActivity->has_time ? Format::dateTime($nextActivity->due_at) : Format::date($nextActivity->due_at) }}</p>
            @elseif ($deal->isOpen())
                <p class="text-[13px] text-warning" data-test="deal-no-activity">{{ __('sales-crm::ui.no_activity_planned') }}</p>
            @endif
            <button type="button" class="crm-link mt-1 text-xs" wire:click="open('activity')">{{ __('sales-crm::ui.plan_activity') }}</button>
        </div>
    </div>

    {{-- Quick actions --}}
    <div class="flex flex-wrap gap-1.5" data-test="deal-actions">
        <button type="button" class="crm-btn crm-btn-sm" wire:click="open('note')" @if ($action === 'note') aria-pressed="true" @endif data-test="deal-action-note"><x-sales-crm::icon name="notebook-pen" :size="14" /> {{ __('sales-crm::ui.action.note') }}</button>
        <button type="button" class="crm-btn crm-btn-sm" wire:click="open('activity')" data-test="deal-action-activity"><x-sales-crm::icon name="calendar-clock" :size="14" /> {{ __('sales-crm::ui.action.activity') }}</button>
        @if (config('sales-crm.email.enabled', true))
            <button type="button" class="crm-btn crm-btn-sm" wire:click="open('email')" data-test="deal-action-email"><x-sales-crm::icon name="mail" :size="14" /> {{ __('sales-crm::ui.action.email') }}</button>
        @endif
        <button type="button" class="crm-btn crm-btn-sm" wire:click="open('document')" data-test="deal-action-document"><x-sales-crm::icon name="upload" :size="14" /> {{ __('sales-crm::ui.action.document') }}</button>
        <button type="button" class="crm-btn crm-btn-sm" wire:click="open('call')" data-test="deal-action-call"><x-sales-crm::icon name="phone" :size="14" /> {{ __('sales-crm::ui.action.call') }}</button>
    </div>

    @if ($action === 'note')
        <form wire:submit="addNote" class="crm-card crm-card-body flex flex-col gap-2" data-test="deal-note-form">
            <textarea rows="3" class="crm-input" wire:model="noteBody" placeholder="{{ __('sales-crm::ui.note_placeholder') }}" x-on:keydown.ctrl.enter.prevent="$wire.addNote()" x-on:keydown.meta.enter.prevent="$wire.addNote()" data-test="deal-note-body"></textarea>
            @error('note.body') <p class="crm-error">{{ $message }}</p> @enderror
            <div class="flex items-center justify-between">
                <p class="text-xs text-faint">{{ __('sales-crm::ui.note_hint') }}</p>
                <button type="submit" class="crm-btn crm-btn-primary crm-btn-sm" data-test="deal-note-save">{{ __('sales-crm::ui.add_note') }}</button>
            </div>
        </form>
    @elseif ($action === 'activity')
        <form wire:submit="saveActivity" class="crm-card crm-card-body grid gap-2 sm:grid-cols-4" data-test="deal-activity-form">
            <x-sales-crm::field :label="__('sales-crm::fields.activity.type')">
                <select class="crm-select" wire:model="activity.type">
                    @foreach (ActivityType::options() as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                </select>
            </x-sales-crm::field>
            <x-sales-crm::field class="sm:col-span-3" :label="__('sales-crm::fields.activity.title')" error="activity.title">
                <input type="text" class="crm-input" wire:model="activity.title" data-test="deal-activity-title">
            </x-sales-crm::field>
            <x-sales-crm::field :label="__('sales-crm::fields.activity.due_date')" error="activity.due_date">
                <input type="date" class="crm-input" wire:model="activity.due_date">
            </x-sales-crm::field>
            <x-sales-crm::field :label="__('sales-crm::fields.activity.due_time')" error="activity.due_time">
                <input type="time" class="crm-input" wire:model="activity.due_time">
            </x-sales-crm::field>
            <x-sales-crm::field :label="__('sales-crm::fields.activity.remind_minutes')">
                <select class="crm-select" wire:model="activity.remind_minutes">
                    <option value="">{{ __('sales-crm::ui.no_reminder') }}</option>
                    @foreach (ManageActivities::reminderOptions() as $minutes)<option value="{{ $minutes }}">{{ ManageActivities::reminderLabel($minutes) }}</option>@endforeach
                </select>
            </x-sales-crm::field>
            <x-sales-crm::field :label="__('sales-crm::fields.activity.assigned_to')" error="activity.assigned_to">
                <select class="crm-select" wire:model="activity.assigned_to">
                    <option value="">—</option>
                    @foreach ($members as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                </select>
            </x-sales-crm::field>
            <x-sales-crm::field class="sm:col-span-4" :label="__('sales-crm::fields.activity.notes')">
                <input type="text" class="crm-input" wire:model="activity.notes">
            </x-sales-crm::field>
            <div class="flex justify-end gap-2 sm:col-span-4">
                <button type="button" class="crm-btn crm-btn-sm" wire:click="$set('action', '')">{{ __('sales-crm::ui.cancel') }}</button>
                <button type="submit" class="crm-btn crm-btn-primary crm-btn-sm" data-test="deal-activity-save">{{ $editingActivity ? __('sales-crm::ui.save') : __('sales-crm::ui.plan') }}</button>
            </div>
        </form>
    @elseif ($action === 'email')
        <form wire:submit="sendEmail" class="crm-card crm-card-body grid gap-2" data-test="deal-email-form">
            @if ($this->templates->isNotEmpty())
                <div class="flex gap-2">
                    <select class="crm-select" wire:model="email.template" aria-label="{{ __('sales-crm::ui.template') }}">
                        <option value="">{{ __('sales-crm::ui.choose_template') }}</option>
                        @foreach ($this->templates as $template)<option value="{{ $template->id }}">{{ $template->name }}</option>@endforeach
                    </select>
                    <button type="button" class="crm-btn crm-btn-sm" wire:click="applyTemplate">{{ __('sales-crm::ui.use') }}</button>
                </div>
            @endif
            <x-sales-crm::field :label="__('sales-crm::fields.email.to')" error="email.to">
                <input type="text" class="crm-input" wire:model="email.to" list="crm-deal-emails" data-test="deal-email-to">
                <datalist id="crm-deal-emails">
                    @foreach ($deal->people as $person)
                        @if ($person->email)<option value="{{ $person->email }}">{{ $person->name }}</option>@endif
                    @endforeach
                </datalist>
            </x-sales-crm::field>
            <x-sales-crm::field :label="__('sales-crm::fields.email.cc')" error="email.cc">
                <input type="text" class="crm-input" wire:model="email.cc">
            </x-sales-crm::field>
            <x-sales-crm::field :label="__('sales-crm::fields.email.subject')" error="email.subject">
                <input type="text" class="crm-input" wire:model="email.subject" data-test="deal-email-subject">
            </x-sales-crm::field>
            <x-sales-crm::field :label="__('sales-crm::fields.email.body')" error="email.body" :hint="__('sales-crm::ui.variables_hint', ['variables' => EmailTemplates::placeholders()])">
                <textarea rows="6" class="crm-input" wire:model="email.body" x-on:keydown.ctrl.enter.prevent="$wire.sendEmail()" data-test="deal-email-body"></textarea>
            </x-sales-crm::field>
            <div class="flex justify-end gap-2">
                <button type="button" class="crm-btn crm-btn-sm" wire:click="$set('action', '')">{{ __('sales-crm::ui.cancel') }}</button>
                <button type="submit" class="crm-btn crm-btn-primary crm-btn-sm" data-test="deal-email-send"><x-sales-crm::icon name="send" :size="14" /> {{ __('sales-crm::ui.send') }}</button>
            </div>
        </form>
    @elseif ($action === 'document')
        <form wire:submit="uploadDocument" class="crm-card crm-card-body grid gap-2 sm:grid-cols-3" data-test="deal-document-form">
            <x-sales-crm::field class="sm:col-span-3" :label="__('sales-crm::fields.document.file')" error="document.file">
                <input type="file" class="crm-input" wire:model="upload" data-test="deal-document-file">
            </x-sales-crm::field>
            <x-sales-crm::field :label="__('sales-crm::fields.document.kind')" error="document.kind">
                <select class="crm-select" wire:model.live="document.kind">
                    @foreach (DocumentKind::options() as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                </select>
            </x-sales-crm::field>
            <x-sales-crm::field :label="__('sales-crm::fields.document.document_date')" error="document.document_date">
                <input type="date" class="crm-input" wire:model="document.document_date">
            </x-sales-crm::field>
            @if (($document['kind'] ?? 'quote') === 'quote')
                <x-sales-crm::field :label="__('sales-crm::fields.document.valid_until')" error="document.valid_until">
                    <input type="date" class="crm-input" wire:model="document.valid_until">
                </x-sales-crm::field>
            @endif
            <div class="flex justify-end gap-2 sm:col-span-3">
                <button type="button" class="crm-btn crm-btn-sm" wire:click="$set('action', '')">{{ __('sales-crm::ui.cancel') }}</button>
                <button type="submit" class="crm-btn crm-btn-primary crm-btn-sm" wire:loading.attr="disabled" data-test="deal-document-save">{{ __('sales-crm::ui.upload') }}</button>
            </div>
        </form>
    @elseif ($action === 'call')
        <form wire:submit="logCall" class="crm-card crm-card-body grid gap-2" data-test="deal-call-form">
            <x-sales-crm::field :label="__('sales-crm::ui.call_with_person')">
                <select class="crm-select" wire:model="call.person_id">
                    <option value="">—</option>
                    @foreach ($deal->people as $person)<option value="{{ $person->id }}">{{ $person->name }}</option>@endforeach
                </select>
            </x-sales-crm::field>
            <x-sales-crm::field :label="__('sales-crm::fields.activity.outcome')" error="call.outcome">
                <textarea rows="2" class="crm-input" wire:model="call.outcome" data-test="deal-call-outcome"></textarea>
            </x-sales-crm::field>
            <div class="flex justify-end gap-2">
                <button type="button" class="crm-btn crm-btn-sm" wire:click="$set('action', '')">{{ __('sales-crm::ui.cancel') }}</button>
                <button type="submit" class="crm-btn crm-btn-primary crm-btn-sm" data-test="deal-call-save">{{ __('sales-crm::ui.log_call') }}</button>
            </div>
        </form>
    @endif

    {{-- Open activities --}}
    @if ($this->openActivities->isNotEmpty())
        <section class="flex flex-col gap-1.5" data-test="deal-open-activities">
            <h3 class="crm-section-title">{{ __('sales-crm::ui.planned_activities') }}</h3>
            @foreach ($this->openActivities as $item)
                @php $state = $item->state($now); @endphp
                <div class="flex flex-wrap items-center gap-2 rounded-md border border-line px-3 py-2" wire:key="open-activity-{{ $item->id }}">
                    <x-sales-crm::icon :name="$item->type->icon()" :size="14" class="text-muted" />
                    <div class="min-w-0 flex-auto">
                        <p class="truncate text-[13px] font-medium text-ink">{{ $item->title }}</p>
                        <p @class(['text-xs', 'text-danger' => $state === 'overdue', 'text-warning' => $state === 'today', 'text-muted' => $state === 'planned'])>{{ $item->has_time ? Format::dateTime($item->due_at) : Format::date($item->due_at) }}@if ($item->assignee) · {{ Crm::userName($item->assignee) }}@endif</p>
                    </div>
                    <input type="text" class="crm-input crm-input-sm w-40" wire:model="outcomes.{{ $item->id }}" placeholder="{{ __('sales-crm::ui.outcome_placeholder') }}" aria-label="{{ __('sales-crm::fields.activity.outcome') }}">
                    <button type="button" class="crm-btn crm-btn-sm" wire:click="completeActivity({{ $item->id }})" data-test="activity-complete-{{ $item->id }}"><x-sales-crm::icon name="check" :size="13" /> {{ __('sales-crm::ui.done') }}</button>
                    <button type="button" class="crm-btn-icon" wire:click="editActivity({{ $item->id }})" aria-label="{{ __('sales-crm::ui.edit') }}"><x-sales-crm::icon name="pencil" :size="13" /></button>
                    <button type="button" class="crm-btn-icon hover:text-danger" wire:click="deleteActivity({{ $item->id }})" wire:confirm="{{ __('sales-crm::ui.confirm_delete') }}" aria-label="{{ __('sales-crm::ui.delete') }}"><x-sales-crm::icon name="trash" :size="13" /></button>
                </div>
            @endforeach
        </section>
    @endif

    {{-- People --}}
    <section class="flex flex-col gap-1.5" data-test="deal-people">
        <div class="flex items-center justify-between">
            <h3 class="crm-section-title">{{ __('sales-crm::ui.people') }}</h3>
            <button type="button" class="crm-link text-xs" wire:click="open('person')" data-test="deal-add-person">{{ __('sales-crm::ui.add_person') }}</button>
        </div>
        @foreach ($deal->people as $person)
            <div class="flex flex-wrap items-center gap-2 rounded-md border border-line px-3 py-2" wire:key="deal-person-{{ $person->id }}">
                <x-sales-crm::avatar :name="$person->name" :size="24" />
                <div class="min-w-0 flex-auto">
                    <a href="{{ route('sales-crm.people.show', $person) }}" class="text-[13px] font-medium text-ink hover:text-brand">{{ $person->name }}</a>
                    @if ($person->getRelationValue('pivot')?->getAttribute('is_primary'))<x-sales-crm::badge tone="brand">{{ __('sales-crm::ui.primary') }}</x-sales-crm::badge>@endif
                    <p class="text-xs text-muted">
                        @if ($person->job_title){{ $person->job_title }} · @endif
                        @if ($number = $person->callableNumber())<a href="tel:{{ $number }}" class="crm-link">{{ Phone::display($number) }}</a>@endif
                        @if ($person->email) · <a href="mailto:{{ $person->email }}" class="crm-link">{{ $person->email }}</a>@endif
                    </p>
                </div>
                <select class="crm-select crm-input-sm w-40" x-on:change="$wire.setRole({{ $person->id }}, $event.target.value)" aria-label="{{ __('sales-crm::fields.person.role') }}">
                    <option value="">—</option>
                    @foreach (PersonRole::options() as $value => $label)<option value="{{ $value }}" @selected($person->getRelationValue('pivot')?->getAttribute('role') === $value)>{{ $label }}</option>@endforeach
                </select>
                @if (! $person->getRelationValue('pivot')?->getAttribute('is_primary'))
                    <button type="button" class="crm-btn crm-btn-sm" wire:click="makePrimary({{ $person->id }})">{{ __('sales-crm::ui.make_primary') }}</button>
                @endif
                <button type="button" class="crm-btn-icon hover:text-danger" wire:click="removePerson({{ $person->id }})" aria-label="{{ __('sales-crm::ui.remove') }}"><x-sales-crm::icon name="x" :size="13" /></button>
            </div>
        @endforeach
        @if ($action === 'person')
            <form wire:submit="addPerson" class="crm-card crm-card-body grid gap-2 sm:grid-cols-2" data-test="deal-person-form">
                <x-sales-crm::field class="sm:col-span-2" :label="__('sales-crm::ui.existing_person')">
                    <select class="crm-select" wire:model="person.person_id">
                        <option value="">{{ __('sales-crm::ui.new_person') }}</option>
                        @foreach ($this->workspacePeople as $option)<option value="{{ $option->id }}">{{ $option->name }}{{ $option->email ? ' · '.$option->email : '' }}</option>@endforeach
                    </select>
                </x-sales-crm::field>
                <x-sales-crm::field :label="__('sales-crm::fields.person.first_name')" error="person.first_name"><input type="text" class="crm-input" wire:model="person.first_name" data-test="deal-person-first"></x-sales-crm::field>
                <x-sales-crm::field :label="__('sales-crm::fields.person.last_name')" error="person.last_name"><input type="text" class="crm-input" wire:model="person.last_name"></x-sales-crm::field>
                <x-sales-crm::field :label="__('sales-crm::fields.person.email')" error="person.email"><input type="email" class="crm-input" wire:model="person.email"></x-sales-crm::field>
                <x-sales-crm::field :label="__('sales-crm::fields.person.mobile')" error="person.mobile"><input type="text" class="crm-input" wire:model="person.mobile"></x-sales-crm::field>
                <x-sales-crm::field :label="__('sales-crm::fields.person.job_title')"><input type="text" class="crm-input" wire:model="person.job_title"></x-sales-crm::field>
                <x-sales-crm::field :label="__('sales-crm::fields.person.role')">
                    <select class="crm-select" wire:model="person.role">
                        <option value="">—</option>
                        @foreach (PersonRole::options() as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    </select>
                </x-sales-crm::field>
                <div class="flex justify-end gap-2 sm:col-span-2">
                    <button type="button" class="crm-btn crm-btn-sm" wire:click="$set('action', '')">{{ __('sales-crm::ui.cancel') }}</button>
                    <button type="submit" class="crm-btn crm-btn-primary crm-btn-sm" data-test="deal-person-save">{{ __('sales-crm::ui.add') }}</button>
                </div>
            </form>
        @endif
    </section>

    {{-- Fields --}}
    <form wire:submit="save" class="flex flex-col gap-4" data-test="deal-form">
        <details open class="crm-card">
            <summary class="cursor-pointer px-4 py-2.5 text-[13px] font-semibold text-ink">{{ __('sales-crm::ui.section.deal') }}</summary>
            <div class="{{ $section }} border-t border-line p-4">
                <x-sales-crm::field class="sm:col-span-2" :label="__('sales-crm::fields.deal.title')" error="form.title"><input type="text" class="crm-input" wire:model="form.title" data-test="deal-form-title"></x-sales-crm::field>
                <x-sales-crm::field :label="__('sales-crm::fields.deal.company_name')" error="form.company_name"><input type="text" class="crm-input" wire:model="form.company_name"></x-sales-crm::field>
                <x-sales-crm::field :label="__('sales-crm::fields.deal.owner_id')" error="form.owner_id">
                    <select class="crm-select" wire:model="form.owner_id" data-test="deal-form-owner">
                        <option value="">—</option>
                        @foreach ($members as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                    </select>
                </x-sales-crm::field>
                <x-sales-crm::field :label="__('sales-crm::fields.deal.priority')" error="form.priority">
                    <select class="crm-select" wire:model="form.priority">
                        <option value="">—</option>
                        @foreach (Priority::options() as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    </select>
                </x-sales-crm::field>
                <x-sales-crm::field :label="__('sales-crm::fields.deal.source')" error="form.source">
                    <select class="crm-select" wire:model="form.source">
                        @foreach (DealSource::options() as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    </select>
                </x-sales-crm::field>
                <x-sales-crm::field :label="__('sales-crm::fields.deal.tags')" error="form.tags" :hint="__('sales-crm::ui.tags_hint')"><input type="text" class="crm-input" wire:model="form.tags"></x-sales-crm::field>
                <x-sales-crm::field :label="__('sales-crm::fields.deal.external_ref')" error="form.external_ref"><input type="text" class="crm-input" wire:model="form.external_ref"></x-sales-crm::field>
                <x-sales-crm::field :label="__('sales-crm::fields.deal.expected_close_date')" error="form.expected_close_date"><input type="date" class="crm-input" wire:model="form.expected_close_date"></x-sales-crm::field>
                <x-sales-crm::field :label="__('sales-crm::fields.deal.last_contacted_at')" error="form.last_contacted_at"><input type="date" class="crm-input" wire:model="form.last_contacted_at"></x-sales-crm::field>
                @if ($deal->isLost())
                    <x-sales-crm::field :label="__('sales-crm::fields.deal.lost_reason')" error="form.lost_reason">
                        <select class="crm-select" wire:model="form.lost_reason">
                            @foreach (LostReason::options() as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                        </select>
                    </x-sales-crm::field>
                    <x-sales-crm::field :label="__('sales-crm::fields.deal.lost_note')"><input type="text" class="crm-input" wire:model="form.lost_note"></x-sales-crm::field>
                @endif
            </div>
        </details>

        <details open class="crm-card">
            <summary class="cursor-pointer px-4 py-2.5 text-[13px] font-semibold text-ink">{{ __('sales-crm::ui.section.amounts') }}</summary>
            <div class="{{ $section }} border-t border-line p-4">
                <x-sales-crm::field :label="__('sales-crm::fields.deal.amount_once')" error="form.amount_once"><input type="number" min="0" step="0.01" class="crm-input crm-num" wire:model="form.amount_once" data-test="deal-form-amount"></x-sales-crm::field>
                <x-sales-crm::field :label="__('sales-crm::fields.deal.deposit')" error="form.deposit"><input type="number" min="0" step="0.01" class="crm-input crm-num" wire:model="form.deposit"></x-sales-crm::field>
                <x-sales-crm::field :label="__('sales-crm::fields.deal.recurring')" error="form.recurring"><input type="number" min="0" step="0.01" class="crm-input crm-num" wire:model="form.recurring"></x-sales-crm::field>
                <x-sales-crm::field :label="__('sales-crm::fields.deal.recurring_period')">
                    <select class="crm-select" wire:model="form.recurring_period">
                        @foreach (RecurringPeriod::options() as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    </select>
                </x-sales-crm::field>
                <x-sales-crm::field class="sm:col-span-2" :label="__('sales-crm::fields.deal.recurring_options')"><input type="text" class="crm-input" wire:model="form.recurring_options"></x-sales-crm::field>
                <x-sales-crm::field :label="__('sales-crm::fields.deal.offer_valid_until')" error="form.offer_valid_until"><input type="date" class="crm-input" wire:model="form.offer_valid_until"></x-sales-crm::field>
                <x-sales-crm::field :label="__('sales-crm::fields.deal.payment_terms')" error="form.payment_terms"><input type="text" class="crm-input" wire:model="form.payment_terms"></x-sales-crm::field>
                <x-sales-crm::field class="sm:col-span-2" :label="__('sales-crm::fields.deal.offer_details')"><textarea rows="2" class="crm-input" wire:model="form.offer_details"></textarea></x-sales-crm::field>
            </div>
        </details>

        <details class="crm-card">
            <summary class="cursor-pointer px-4 py-2.5 text-[13px] font-semibold text-ink">{{ __('sales-crm::ui.section.state') }}</summary>
            <div class="{{ $section }} border-t border-line p-4">
                <x-sales-crm::field :label="__('sales-crm::fields.deal.sub_status')"><input type="text" class="crm-input" wire:model="form.sub_status"></x-sales-crm::field>
                <x-sales-crm::field :label="__('sales-crm::fields.deal.data_verified_at')"><input type="date" class="crm-input" wire:model="form.data_verified_at"></x-sales-crm::field>
                <x-sales-crm::field class="sm:col-span-2" :label="__('sales-crm::fields.deal.blocker')"><textarea rows="2" class="crm-input" wire:model="form.blocker"></textarea></x-sales-crm::field>
                <x-sales-crm::field class="sm:col-span-2" :label="__('sales-crm::fields.deal.missing_data')"><textarea rows="2" class="crm-input" wire:model="form.missing_data"></textarea></x-sales-crm::field>
                <x-sales-crm::field class="sm:col-span-2" :label="__('sales-crm::fields.deal.call_context')"><textarea rows="2" class="crm-input" wire:model="form.call_context"></textarea></x-sales-crm::field>
                <x-sales-crm::field class="sm:col-span-2" :label="__('sales-crm::fields.deal.closing_tip')"><textarea rows="2" class="crm-input" wire:model="form.closing_tip"></textarea></x-sales-crm::field>
            </div>
        </details>

        <details class="crm-card">
            <summary class="cursor-pointer px-4 py-2.5 text-[13px] font-semibold text-ink">{{ __('sales-crm::ui.section.signature') }}</summary>
            <div class="{{ $section }} border-t border-line p-4">
                <x-sales-crm::field :label="__('sales-crm::fields.deal.signature_status')" error="form.signature_status">
                    <select class="crm-select" wire:model="form.signature_status" data-test="deal-form-signature">
                        @foreach (SignatureStatus::options() as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    </select>
                </x-sales-crm::field>
                <x-sales-crm::field :label="__('sales-crm::fields.deal.signature_evidence')" error="form.signature_evidence"><input type="text" class="crm-input" wire:model="form.signature_evidence" placeholder="{{ __('sales-crm::ui.evidence_placeholder') }}"></x-sales-crm::field>
                <x-sales-crm::field :label="__('sales-crm::fields.deal.payment_status')" error="form.payment_status">
                    <select class="crm-select" wire:model="form.payment_status">
                        @foreach (PaymentStatus::options() as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    </select>
                </x-sales-crm::field>
                <x-sales-crm::field :label="__('sales-crm::fields.deal.amount_collected')" error="form.amount_collected"><input type="number" min="0" step="0.01" class="crm-input crm-num" wire:model="form.amount_collected"></x-sales-crm::field>
                <x-sales-crm::field class="sm:col-span-2" :label="__('sales-crm::fields.deal.payment_evidence')" error="form.payment_evidence"><input type="text" class="crm-input" wire:model="form.payment_evidence"></x-sales-crm::field>
                @if ($deal->signature_verified_at)
                    <p class="text-xs text-muted sm:col-span-2">{{ __('sales-crm::ui.verified_on', ['date' => Format::dateTime($deal->signature_verified_at)]) }}</p>
                @endif
            </div>
        </details>

        <details class="crm-card">
            <summary class="cursor-pointer px-4 py-2.5 text-[13px] font-semibold text-ink">{{ __('sales-crm::ui.section.approval') }}@if ($deal->approval_required) · <span class="text-xs text-muted">{{ $deal->approval_status?->label() }}</span>@endif</summary>
            <div class="{{ $section }} border-t border-line p-4">
                <label class="flex items-center gap-2 text-[13px] sm:col-span-2"><input type="checkbox" class="crm-checkbox" wire:model.live="form.approval_required" data-test="deal-form-approval"> {{ __('sales-crm::fields.deal.approval_required') }}</label>
                @if ($form['approval_required'] ?? false)
                    <x-sales-crm::field :label="__('sales-crm::fields.deal.approver_id')" error="form.approver_id">
                        <select class="crm-select" wire:model="form.approver_id">
                            <option value="">—</option>
                            @foreach ($members as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                        </select>
                    </x-sales-crm::field>
                    <x-sales-crm::field :label="__('sales-crm::fields.deal.approval_status')" error="form.approval_status">
                        <select class="crm-select" wire:model="form.approval_status">
                            @foreach (ApprovalStatus::options() as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                        </select>
                    </x-sales-crm::field>
                    <x-sales-crm::field class="sm:col-span-2" :label="__('sales-crm::fields.deal.approval_note')"><textarea rows="2" class="crm-input" wire:model="form.approval_note"></textarea></x-sales-crm::field>
                @endif
            </div>
        </details>

        <details class="crm-card">
            <summary class="cursor-pointer px-4 py-2.5 text-[13px] font-semibold text-ink">{{ __('sales-crm::ui.section.commission') }}@if ($deal->commissionCents() !== null) · <span class="text-xs text-muted">{{ Format::money($deal->commissionCents()) }}</span>@endif</summary>
            <div class="{{ $section }} border-t border-line p-4">
                <x-sales-crm::field :label="__('sales-crm::fields.deal.commission_type')">
                    <select class="crm-select" wire:model="form.commission_type">
                        <option value="">—</option>
                        @foreach (CommissionType::options() as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    </select>
                </x-sales-crm::field>
                <x-sales-crm::field :label="__('sales-crm::fields.deal.commission_value')" error="form.commission_value" :hint="__('sales-crm::ui.commission_hint')"><input type="number" min="0" step="0.01" class="crm-input crm-num" wire:model="form.commission_value"></x-sales-crm::field>
                <x-sales-crm::field class="sm:col-span-2" :label="__('sales-crm::fields.deal.commission_note')"><input type="text" class="crm-input" wire:model="form.commission_note"></x-sales-crm::field>
            </div>
        </details>

        @if ($this->customFields() !== [])
            <details open class="crm-card" data-test="deal-custom-fields">
                <summary class="cursor-pointer px-4 py-2.5 text-[13px] font-semibold text-ink">{{ __('sales-crm::ui.section.custom') }}</summary>
                <div class="{{ $section }} border-t border-line p-4">
                    @foreach ($this->customFields() as $key => $field)
                        <x-sales-crm::field :label="$field['label']" :error="'form.custom_values.'.$key">
                            @switch($field['type'])
                                @case('boolean')
                                    <input type="checkbox" class="crm-checkbox" wire:model="custom.{{ $key }}">
                                    @break
                                @case('choice')
                                    <select class="crm-select" wire:model="custom.{{ $key }}">
                                        <option value="">—</option>
                                        @foreach ($field['options'] ?? [] as $option)<option value="{{ $option }}">{{ $option }}</option>@endforeach
                                    </select>
                                    @break
                                @case('date')
                                    <input type="date" class="crm-input" wire:model="custom.{{ $key }}">
                                    @break
                                @case('number')
                                    <input type="number" step="any" class="crm-input crm-num" wire:model="custom.{{ $key }}">
                                    @break
                                @default
                                    <input type="text" class="crm-input" wire:model="custom.{{ $key }}" data-test="deal-custom-{{ $key }}">
                            @endswitch
                        </x-sales-crm::field>
                    @endforeach
                </div>
            </details>
        @endif

        <div class="sticky bottom-0 -mx-5 flex items-center justify-end gap-2 border-t border-line bg-raised px-5 py-2.5">
            @if ($errors->isNotEmpty())<p class="mr-auto text-xs text-danger">{{ __('sales-crm::ui.fix_errors') }}</p>@endif
            <button type="submit" class="crm-btn crm-btn-primary" wire:loading.attr="disabled" data-test="deal-save">{{ __('sales-crm::ui.save_changes') }}</button>
        </div>
    </form>

    {{-- Documents --}}
    @if ($this->documents->isNotEmpty())
        <section class="flex flex-col gap-1.5" data-test="deal-documents">
            <h3 class="crm-section-title">{{ __('sales-crm::ui.timeline.document') }}</h3>
            @foreach ($this->documents as $file)
                <div class="flex items-center gap-2 rounded-md border border-line px-3 py-2 text-[13px]" wire:key="deal-document-{{ $file->id }}">
                    <x-sales-crm::icon :name="$file->kind->icon()" :size="14" class="text-muted" />
                    <a href="{{ route('sales-crm.documents.download', $file) }}" class="min-w-0 flex-auto truncate crm-link">{{ $file->name }}</a>
                    <span class="text-xs text-muted">{{ $file->kind->label() }}@if ($file->version > 1) v{{ $file->version }}@endif @if ($file->valid_until) · {{ __('sales-crm::ui.timeline.valid_until', ['date' => Format::date($file->valid_until)]) }}@endif</span>
                    <button type="button" class="crm-btn-icon hover:text-danger" wire:click="deleteDocument({{ $file->id }})" wire:confirm="{{ __('sales-crm::ui.confirm_delete') }}" aria-label="{{ __('sales-crm::ui.delete') }}"><x-sales-crm::icon name="trash" :size="13" /></button>
                </div>
            @endforeach
        </section>
    @endif

    {{-- Timeline --}}
    <section class="flex flex-col gap-2" data-test="deal-timeline">
        <div class="crm-tabs overflow-x-auto" role="tablist">
            <button type="button" class="crm-tab" role="tab" aria-selected="{{ $kind === '' ? 'true' : 'false' }}" wire:click="$set('kind', '')">{{ __('sales-crm::ui.timeline.all') }} <span class="crm-count" data-test="timeline-count-all">{{ array_sum($this->counts) }}</span></button>
            @foreach ($this->kinds() as $key => $label)
                <button type="button" class="crm-tab" role="tab" aria-selected="{{ $kind === $key ? 'true' : 'false' }}" wire:click="$set('kind', '{{ $key }}')" data-test="timeline-tab-{{ $key }}">{{ $label }} <span class="crm-count" data-test="timeline-count-{{ $key }}">{{ $this->counts[$key] ?? 0 }}</span></button>
            @endforeach
        </div>
        @forelse ($this->timeline as $entry)
            <article class="flex gap-3 rounded-md px-1 py-2" wire:key="timeline-{{ $entry->key }}" data-test="timeline-entry" data-kind="{{ $entry->kind }}">
                <span class="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full bg-sunken text-muted"><x-sales-crm::icon :name="$entry->icon" :size="14" /></span>
                <div class="min-w-0 flex-auto">
                    <div class="flex flex-wrap items-baseline gap-x-2">
                        <p class="text-[13px] font-medium text-ink">{{ $entry->title }}</p>
                        @if ($entry->pinned)<x-sales-crm::badge tone="warning" icon="pin">{{ __('sales-crm::ui.pinned') }}</x-sales-crm::badge>@endif
                        <span class="text-xs text-faint">{{ Format::dateTime($entry->at) }}@if ($entry->author) · {{ $entry->author }}@endif</span>
                    </div>
                    @if ($entry->meta)<p class="text-xs text-muted">{{ $entry->meta }}</p>@endif
                    @if ($entry->body)<p class="mt-0.5 whitespace-pre-line break-words text-[13px] text-ink/90">{{ \Illuminate\Support\Str::limit($entry->body, 2000) }}</p>@endif
                    @if ($entry->kind === 'note' && $entry->id)
                        <div class="mt-1 flex gap-2 text-xs">
                            <button type="button" class="crm-link" wire:click="pinNote({{ $entry->id }}, {{ $entry->pinned ? 'false' : 'true' }})">{{ $entry->pinned ? __('sales-crm::ui.unpin') : __('sales-crm::ui.pin') }}</button>
                            <button type="button" class="text-muted hover:text-danger" wire:click="deleteNote({{ $entry->id }})" wire:confirm="{{ __('sales-crm::ui.confirm_delete') }}">{{ __('sales-crm::ui.delete') }}</button>
                        </div>
                    @elseif ($entry->kind === 'email' && $entry->id && config('sales-crm.email.enabled', true))
                        <button type="button" class="crm-link mt-1 text-xs" wire:click="startEmail({{ $entry->id }})">{{ __('sales-crm::ui.reply') }}</button>
                    @elseif ($entry->kind === 'activity' && $entry->id)
                        <button type="button" class="crm-link mt-1 text-xs" wire:click="reopenActivity({{ $entry->id }})">{{ __('sales-crm::ui.reopen') }}</button>
                    @endif
                </div>
            </article>
        @empty
            <x-sales-crm::empty icon="clock" :title="__('sales-crm::ui.timeline.empty')" />
        @endforelse
    </section>

    {{-- The fields the stage requires, asked by the panel --}}
    <x-sales-crm::modal model="showRequirements" :title="__('sales-crm::ui.requirements_title', ['stage' => (string) $deal->pipeline->stages->firstWhere('id', $pendingStage)?->name])" :subtitle="__('sales-crm::ui.requirements_subtitle')">
        @if ($showRequirements)
            @include('sales-crm::livewire.partials.requirement-fields', ['fields' => $this->pendingMissing(), 'lostReason' => $this->pendingNeedsReason(), 'model' => 'fill', 'owners' => $members])
        @endif
        <x-slot:footer>
            <button type="button" class="crm-btn" x-on:click="$wire.set('showRequirements', false)">{{ __('sales-crm::ui.cancel') }}</button>
            <button type="button" class="crm-btn crm-btn-primary" wire:click="completeMove" data-test="panel-requirements-save">{{ __('sales-crm::ui.move') }}</button>
        </x-slot:footer>
    </x-sales-crm::modal>
</div>
