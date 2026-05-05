@props(['variant' => 'default'])

@php
    $bg = $variant === 'elevated' ? 'bg-app-elevated' : 'bg-app-card';
@endphp

<div {{ $attributes->merge(['class' => "$bg border border-app-border rounded-lg"]) }}>
    {{ $slot }}
</div>
