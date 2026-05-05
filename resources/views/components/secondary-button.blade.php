<button {{ $attributes->merge(['type' => 'button', 'class' => 'inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded border border-app-border text-ink-secondary bg-transparent text-xs font-medium hover:bg-app-card hover:text-ink-primary hover:border-neutral-border transition']) }}>
    {{ $slot }}
</button>
