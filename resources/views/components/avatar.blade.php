@props(['name' => '', 'size' => 22])
@php
    $initials = collect(preg_split('/\s+/', trim((string) $name)) ?: [])->filter()->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('');
    $hue = abs(crc32((string) $name)) % 360;
@endphp
<span title="{{ $name }}" {{ $attributes->class(['inline-flex shrink-0 items-center justify-center rounded-full font-semibold text-white']) }} style="width: {{ $size }}px; height: {{ $size }}px; font-size: {{ max(9, (int) ($size * 0.42)) }}px; background: hsl({{ $hue }} 45% 45%)">{{ $initials ?: '?' }}</span>
