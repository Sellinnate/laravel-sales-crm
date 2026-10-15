@props(['title' => null, 'subtitle' => null, 'wide' => false])
@php
    $nav = \Sellinnate\SalesCrm\Livewire\Navigation::items();
@endphp
{{-- The frame of every page of the CRM: the navigation of the CRM and the page header with its actions. --}}
<div class="sales-crm" x-data x-on:crm-toast.window="$dispatch('crm-notify', $event.detail)">
    <header class="crm-nav">
        <div class="crm-container flex h-12 items-center gap-3">
            <a href="{{ route('sales-crm.deals') }}" class="flex items-center gap-2 text-sm font-semibold text-ink" data-test="crm-brand">
                <span class="flex size-6 items-center justify-center rounded-md bg-brand text-brand-ink"><x-sales-crm::icon name="briefcase" :size="14" /></span>
                <span class="max-sm:hidden">{{ config('sales-crm.ui.brand', 'Sales CRM') }}</span>
            </a>
            <nav class="flex min-w-0 flex-1 items-center gap-0.5 overflow-x-auto" aria-label="{{ __('sales-crm::ui.navigation') }}">
                @foreach ($nav as $item)
                    <a href="{{ $item['url'] }}" class="crm-nav-link" @if ($item['active']) aria-current="page" @endif data-test="crm-nav-{{ $item['key'] }}">
                        <x-sales-crm::icon :name="$item['icon']" :size="15" />
                        <span>{{ $item['label'] }}</span>
                        @if (($item['count'] ?? 0) > 0)
                            <span class="crm-badge crm-badge-danger" data-test="crm-nav-count-{{ $item['key'] }}">{{ $item['count'] }}</span>
                        @endif
                    </a>
                @endforeach
            </nav>
            @if (filled(config('sales-crm.ui.home_url')))
                <a href="{{ url((string) config('sales-crm.ui.home_url')) }}" class="crm-btn-icon" title="{{ __('sales-crm::ui.back_to_app') }}" aria-label="{{ __('sales-crm::ui.back_to_app') }}"><x-sales-crm::icon name="home" /></a>
            @endif
            @auth
                <x-sales-crm::avatar :name="\Sellinnate\SalesCrm\Support\Crm::userName(auth()->user())" :size="26" />
            @endauth
        </div>
    </header>

    <main @class(['crm-container py-5', 'max-w-none' => $wide])>
        @if ($title || isset($actions))
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div class="min-w-0">
                    @if ($title)<h1 class="crm-page-title">{{ $title }}</h1>@endif
                    @if ($subtitle)<p class="mt-0.5 text-[13px] text-muted">{{ $subtitle }}</p>@endif
                </div>
                @isset($actions)
                    <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
                @endisset
            </div>
        @endif

        {{ $slot }}
    </main>

    <div class="pointer-events-none fixed bottom-4 right-4 z-[60] flex flex-col gap-2"
         x-data="{ toasts: [] }"
         x-on:crm-notify.window="const toast = { id: Date.now() + Math.random(), message: $event.detail.message ?? $event.detail[0]?.message ?? '', tone: $event.detail.tone ?? $event.detail[0]?.tone ?? 'success' }; toasts.push(toast); setTimeout(() => toasts = toasts.filter(t => t.id !== toast.id), 4000)">
        <template x-for="toast in toasts" :key="toast.id">
            <div class="crm-toast" :class="toast.tone === 'danger' ? 'border-danger text-danger' : 'text-ink'" role="status" x-text="toast.message" data-test="crm-toast"></div>
        </template>
    </div>
</div>
