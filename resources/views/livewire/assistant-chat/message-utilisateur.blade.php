{{-- Bulle d'une question posée par l'utilisateur, avec les éventuelles pièces jointes envoyées avec elle --}}
<div class="self-end flex flex-col items-end gap-1.5 max-w-[80%]" wire:key="msg-{{ $m->id }}">
    @if (! empty($m->pieces))
        <div class="flex flex-wrap justify-end gap-2">
            @foreach ($m->pieces as $p)
                <div wire:key="msg-{{ $m->id }}-pj-{{ $p['id'] }}">
                    @include('livewire.assistant-chat.piece-jointe', ['piece' => $p, 'retirable' => false])
                </div>
            @endforeach
        </div>
    @endif
    <div class="bg-app-bg border border-app-border-soft text-ink-primary rounded-2xl rounded-br-sm px-3.5 py-2.5 text-sm whitespace-pre-wrap">{{ $m->contenu }}</div>
</div>
