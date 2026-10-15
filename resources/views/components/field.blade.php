@props(['label' => null, 'for' => null, 'error' => null, 'hint' => null])
<div {{ $attributes }}>
    @if ($label)<label class="crm-label" @if ($for) for="{{ $for }}" @endif>{{ $label }}</label>@endif
    {{ $slot }}
    @if ($error)
        @error($error) <p class="crm-error">{{ $message }}</p> @enderror
    @endif
    @if ($hint)<p class="crm-hint">{{ $hint }}</p>@endif
</div>
