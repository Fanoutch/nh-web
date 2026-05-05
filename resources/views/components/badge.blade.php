@props(['variant' => 'pending'])

@php
    $classes = match ($variant) {
        'ok', 'validated'      => 'bg-ok-soft text-ok',
        'error', 'rejected'    => 'bg-danger-soft text-danger',
        'pending'              => 'bg-neutral-soft text-neutral border border-neutral-border',
        'processing'           => 'bg-accent-soft-strong text-warn animate-pulse-amber',
        'nonvol'               => 'bg-neutral-soft text-neutral border border-neutral-border',
        'already', 'amber'     => 'bg-accent-soft-strong text-warn',
        default                => 'bg-neutral-soft text-neutral border border-neutral-border',
    };
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center gap-1 px-2 py-0.5 rounded font-mono text-[11px] font-medium tracking-wide $classes"]) }}>
    {{ $slot }}
</span>
