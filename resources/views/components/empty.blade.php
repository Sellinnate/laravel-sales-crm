@props(['icon' => 'sparkles', 'title', 'description' => null])
<div {{ $attributes->class(['flex flex-col items-center justify-center gap-2 px-6 py-12 text-center']) }}>
    <span class="flex size-10 items-center justify-center rounded-full bg-sunken text-muted"><x-sales-crm::icon :name="$icon" :size="20" /></span>
    <p class="text-sm font-semibold text-ink">{{ $title }}</p>
    @if ($description)<p class="max-w-md text-[13px] text-muted">{{ $description }}</p>@endif
    {{ $slot }}
</div>
