@props(['value'])

<label {{ $attributes->merge(['class' => 'block text-[11px] font-semibold uppercase tracking-[0.06em] text-ink-muted']) }}>
    {{ $value ?? $slot }}
</label>
