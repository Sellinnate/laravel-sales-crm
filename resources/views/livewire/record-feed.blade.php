@php
    use Sellinnate\SalesCrm\Enums\ActivityType;
    use Sellinnate\SalesCrm\Enums\DocumentKind;
    use Sellinnate\SalesCrm\Support\Format;

    $now = \Carbon\CarbonImmutable::now();
@endphp
<div class="flex flex-col gap-4" data-test="record-feed">
    <div class="grid gap-3 md:grid-cols-2">
        <form wire:submit="addNote" class="crm-card crm-card-body flex flex-col gap-2">
            <h3 class="crm-section-title">{{ __('sales-crm::ui.action.note') }}</h3>
            <textarea rows="3" class="crm-input" wire:model="noteBody" placeholder="{{ __('sales-crm::ui.note_placeholder') }}" data-test="feed-note-body"></textarea>
            @error('note.body') <p class="crm-error">{{ $message }}</p> @enderror
            <div class="flex justify-end"><button type="submit" class="crm-btn crm-btn-primary crm-btn-sm" data-test="feed-note-save">{{ __('sales-crm::ui.add_note') }}</button></div>
        </form>
        <form wire:submit="saveActivity" class="crm-card crm-card-body grid gap-2 sm:grid-cols-2">
            <h3 class="crm-section-title sm:col-span-2">{{ __('sales-crm::ui.plan_activity') }}</h3>
            <select class="crm-select" wire:model="activity.type" aria-label="{{ __('sales-crm::fields.activity.type') }}">
                @foreach (ActivityType::options() as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
            </select>
            <input type="text" class="crm-input" wire:model="activity.title" placeholder="{{ __('sales-crm::fields.activity.title') }}" aria-label="{{ __('sales-crm::fields.activity.title') }}" data-test="feed-activity-title">
            <input type="date" class="crm-input" wire:model="activity.due_date" aria-label="{{ __('sales-crm::fields.activity.due_date') }}">
            <select class="crm-select" wire:model="activity.assigned_to" aria-label="{{ __('sales-crm::fields.activity.assigned_to') }}">
                <option value="">—</option>
                @foreach ($this->members as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
            </select>
            @error('activity.title') <p class="crm-error sm:col-span-2">{{ $message }}</p> @enderror
            <div class="flex justify-end sm:col-span-2"><button type="submit" class="crm-btn crm-btn-primary crm-btn-sm" data-test="feed-activity-save">{{ __('sales-crm::ui.plan') }}</button></div>
        </form>
    </div>

    @if ($type === 'company')
        <form wire:submit="uploadDocument" class="crm-card crm-card-body flex flex-wrap items-end gap-2">
            <x-sales-crm::field class="min-w-64 flex-auto" :label="__('sales-crm::fields.document.file')" error="document.file"><input type="file" class="crm-input" wire:model="upload"></x-sales-crm::field>
            <x-sales-crm::field :label="__('sales-crm::fields.document.kind')">
                <select class="crm-select" wire:model="documentKind">
                    @foreach (DocumentKind::options() as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                </select>
            </x-sales-crm::field>
            <button type="submit" class="crm-btn crm-btn-sm">{{ __('sales-crm::ui.upload') }}</button>
        </form>
    @endif

    @if ($this->openActivities->isNotEmpty())
        <section class="crm-card divide-y divide-line">
            @foreach ($this->openActivities as $item)
                <div class="flex items-center gap-3 px-4 py-2" wire:key="feed-activity-{{ $item->id }}">
                    <button type="button" class="flex size-5 items-center justify-center rounded-full border border-line-strong text-transparent hover:border-success hover:text-success" wire:click="completeActivity({{ $item->id }})" aria-label="{{ __('sales-crm::ui.mark_done') }}"><x-sales-crm::icon name="check" :size="12" /></button>
                    <span class="min-w-0 flex-auto truncate text-[13px]">{{ $item->type->label() }} · {{ $item->title }}</span>
                    <span @class(['text-xs', 'text-danger' => $item->state($now) === 'overdue', 'text-muted' => $item->state($now) !== 'overdue'])>{{ Format::date($item->due_at) }}</span>
                </div>
            @endforeach
        </section>
    @endif

    <section class="flex flex-col gap-2">
        <div class="crm-tabs overflow-x-auto" role="tablist">
            <button type="button" class="crm-tab" role="tab" aria-selected="{{ $kind === '' ? 'true' : 'false' }}" wire:click="$set('kind', '')">{{ __('sales-crm::ui.timeline.all') }} <span class="crm-count">{{ array_sum($this->counts) }}</span></button>
            @foreach ($this->kinds() as $key => $label)
                <button type="button" class="crm-tab" role="tab" aria-selected="{{ $kind === $key ? 'true' : 'false' }}" wire:click="$set('kind', '{{ $key }}')">{{ $label }} <span class="crm-count" data-test="feed-count-{{ $key }}">{{ $this->counts[$key] ?? 0 }}</span></button>
            @endforeach
        </div>
        @forelse ($this->timeline as $entry)
            <article class="flex gap-3 py-2" wire:key="feed-{{ $entry->key }}">
                <span class="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full bg-sunken text-muted"><x-sales-crm::icon :name="$entry->icon" :size="14" /></span>
                <div class="min-w-0 flex-auto">
                    <p class="text-[13px] font-medium text-ink">{{ $entry->title }} <span class="text-xs font-normal text-faint">{{ Format::dateTime($entry->at) }}@if ($entry->author) · {{ $entry->author }}@endif</span></p>
                    @if ($entry->meta)<p class="text-xs text-muted">{{ $entry->meta }}</p>@endif
                    @if ($entry->body)<p class="whitespace-pre-line break-words text-[13px]">{{ \Illuminate\Support\Str::limit($entry->body, 2000) }}</p>@endif
                    @if ($entry->kind === 'note' && $entry->id)
                        <button type="button" class="mt-1 text-xs text-muted hover:text-danger" wire:click="deleteNote({{ $entry->id }})" wire:confirm="{{ __('sales-crm::ui.confirm_delete') }}">{{ __('sales-crm::ui.delete') }}</button>
                    @endif
                </div>
            </article>
        @empty
            <x-sales-crm::empty icon="clock" :title="__('sales-crm::ui.timeline.empty')" />
        @endforelse
    </section>
</div>
