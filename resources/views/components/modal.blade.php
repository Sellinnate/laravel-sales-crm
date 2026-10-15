@props(['model', 'title' => null, 'subtitle' => null, 'size' => 'md', 'footer' => null])
{{-- A dialog bound to a boolean property of the Livewire component (`model`). Esc and the backdrop close it. --}}
<div x-data="{ open: $wire.entangle('{{ $model }}').live }" x-show="open" x-cloak x-on:keydown.escape.window="open = false" {{ $attributes->only(['data-test']) }}>
    <div class="crm-overlay" x-show="open" x-transition.opacity x-on:click="open = false"></div>
    <div @class(['crm-modal', 'crm-modal-lg' => $size === 'lg']) x-show="open" x-transition role="dialog" aria-modal="true" @if ($title) aria-label="{{ $title }}" @endif>
        @if ($title)
            <div class="crm-modal-header">
                <div class="min-w-0">
                    <h2 class="text-[15px] font-semibold text-ink">{{ $title }}</h2>
                    @if ($subtitle)<p class="mt-0.5 text-xs text-muted">{{ $subtitle }}</p>@endif
                </div>
                <button type="button" class="crm-btn-icon" x-on:click="open = false" aria-label="{{ __('sales-crm::ui.close') }}"><x-sales-crm::icon name="x" /></button>
            </div>
        @endif
        <div class="crm-modal-body">{{ $slot }}</div>
        @if ($footer)
            <div class="crm-modal-footer">{{ $footer }}</div>
        @endif
    </div>
</div>
