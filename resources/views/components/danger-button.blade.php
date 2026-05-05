<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded bg-danger-soft border border-danger-border text-danger text-xs font-medium hover:bg-danger-border focus:outline-none focus:ring-2 focus:ring-danger focus:ring-offset-2 transition']) }}>
    {{ $slot }}
</button>
