@props(['model', 'title' => null])
{{-- A side panel bound to a boolean property of the Livewire component (`model`). --}}
<div x-data="{ open: $wire.entangle('{{ $model }}').live }" x-show="open" x-cloak x-on:keydown.escape.window="if (! document.querySelector('.crm-modal:not([style*=\'display: none\'])')) open = false" {{ $attributes->only(['data-test']) }}>
    <div class="crm-overlay" x-show="open" x-transition.opacity x-on:click="open = false"></div>
    <aside class="crm-slide-over" x-show="open" x-transition:enter="transition ease-out duration-150" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0" x-transition:leave="transition ease-in duration-100" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full" role="dialog" aria-modal="true" @if ($title) aria-label="{{ $title }}" @endif>
        <div class="flex items-center justify-between border-b border-line px-5 py-3">
            <h2 class="text-sm font-semibold text-muted">{{ $title }}</h2>
            <button type="button" class="crm-btn-icon" x-on:click="open = false" aria-label="{{ __('sales-crm::ui.close') }}" data-test="slide-over-close"><x-sales-crm::icon name="x" /></button>
        </div>
        <div class="flex-1 overflow-y-auto">{{ $slot }}</div>
    </aside>
</div>
