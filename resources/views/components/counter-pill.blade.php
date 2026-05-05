@props(['value', 'label', 'variant' => 'default'])

@php
    $valueClass = match ($variant) {
        'danger'    => 'text-danger',
        'secondary' => 'text-ink-secondary',
        default     => 'text-ink-primary',
    };
    $labelClass = $variant === 'danger' ? 'text-danger' : 'text-ink-muted';
@endphp

<div class="flex flex-col items-center">
    <p class="font-mono text-lg font-medium tabular-nums leading-none {{ $valueClass }}">{{ $value }}</p>
    <p class="text-[10px] uppercase tracking-wide font-medium mt-1 {{ $labelClass }}">{{ $label }}</p>
</div>
