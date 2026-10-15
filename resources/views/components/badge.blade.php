@props(['tone' => 'neutral', 'icon' => null])
<span {{ $attributes->class(['crm-badge', 'crm-badge-'.$tone]) }}>@if ($icon)<x-sales-crm::icon :name="$icon" :size="12" />@endif{{ $slot }}</span>
