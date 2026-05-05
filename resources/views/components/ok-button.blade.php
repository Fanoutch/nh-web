<button {{ $attributes->merge(['type' => 'button', 'class' => 'inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded bg-ok-soft border border-ok-border text-ok text-xs font-medium hover:bg-ok-border focus:outline-none focus:ring-2 focus:ring-ok focus:ring-offset-2 transition']) }}>
    {{ $slot }}
</button>
