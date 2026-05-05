@props(['value', 'label', 'variant' => 'default', 'boxed' => false])

@php
    $valueClass = match ($variant) {
        'danger'    => 'text-danger',
        'secondary' => 'text-ink-secondary',
        default     => 'text-ink-primary',
    };
    $labelClass = $variant === 'danger' ? 'text-danger' : 'text-ink-muted';
    $wrapperClass = $boxed
        ? ($variant === 'danger'
            ? 'flex flex-col items-center px-4 py-1.5 bg-danger-soft border border-danger-border rounded-md'
            : 'flex flex-col items-center px-4 py-1.5 bg-app-card border border-app-border rounded-md')
        : 'flex flex-col items-center';
@endphp

<div class="{{ $wrapperClass }}">
    <p class="font-mono text-lg font-medium tabular-nums leading-none {{ $valueClass }}">{{ $value }}</p>
    <p class="text-[10px] uppercase tracking-wide font-medium mt-1 {{ $labelClass }}">{{ $label }}</p>
</div>
