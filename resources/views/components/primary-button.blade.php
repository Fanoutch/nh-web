<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded bg-accent text-ink-primary text-xs font-medium hover:bg-accent-hover focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-2 transition']) }}>
    {{ $slot }}
</button>
