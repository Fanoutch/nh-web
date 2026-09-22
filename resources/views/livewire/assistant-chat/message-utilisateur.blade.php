{{-- Bulle d'une question posée par l'utilisateur --}}
<div class="self-end max-w-[80%] bg-app-bg border border-app-border-soft text-ink-primary rounded-2xl rounded-br-sm px-3.5 py-2.5 text-sm whitespace-pre-wrap"
     wire:key="msg-{{ $m->id }}">{{ $m->contenu }}</div>
