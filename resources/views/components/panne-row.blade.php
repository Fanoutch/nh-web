@props(['status' => 'pending', 'readonly' => false])

@php
    $borderClass = match ($status) {
        'validated' => 'border-l-ok',
        'rejected'  => 'border-l-danger',
        'readonly'  => 'border-l-app-border',
        default     => 'border-l-accent',
    };
    $opacityClass = $status === 'rejected' ? 'opacity-60' : '';
@endphp

<div class="px-5 py-4 bg-app-card border border-app-border rounded-md border-l-[3px] {{ $borderClass }} {{ $opacityClass }} flex items-start gap-4">
    <div class="flex-1 min-w-0">
        {{ $slot }}
    </div>
    @isset($actions)
        <div class="w-[200px] shrink-0 flex flex-col gap-2 items-end">
            {{ $actions }}
        </div>
    @endisset
</div>
