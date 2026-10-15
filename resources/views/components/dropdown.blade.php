@props(['align' => 'left', 'width' => 'w-56'])
<div class="relative inline-block" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape="open = false" {{ $attributes->only(['data-test']) }}>
    <div x-on:click="open = ! open">{{ $trigger }}</div>
    <div x-show="open" x-cloak x-transition.origin.top @class(['crm-dropdown', $width, 'right-0' => $align === 'right', 'left-0' => $align !== 'right']) x-on:click="if ($event.target.closest('[data-close]')) open = false">
        {{ $slot }}
    </div>
</div>
