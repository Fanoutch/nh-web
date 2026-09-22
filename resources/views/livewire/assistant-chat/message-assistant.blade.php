{{--
    Bulle d'une réponse de l'assistant : Markdown rendu en HTML sûr pour les réponses enregistrées
    (le service AssistantChat échappe le HTML source et désactive les liens dangereux). Les sources
    citées restent affichées telles qu'aujourd'hui (tâche 10 les enrichira).
--}}
<div class="self-start max-w-[85%] flex flex-col gap-1.5" wire:key="msg-{{ $m->id }}">
    <div @class([
            'rounded-2xl rounded-bl-sm px-3.5 py-2.5 text-sm leading-relaxed',
            '[&_ul]:list-disc [&_ol]:list-decimal [&_ul]:pl-5 [&_ol]:pl-5 [&_li]:my-0.5 [&_p]:mb-2 [&_p:last-child]:mb-0 [&_h3]:font-semibold [&_h3]:mt-2 [&_h3]:mb-1 [&_code]:font-mono [&_code]:text-[12px] [&_code]:bg-app-bg [&_code]:border [&_code]:border-app-border-soft [&_code]:rounded [&_code]:px-1' => $m->statut !== 'erreur',
            'border border-app-border bg-app-bg text-ink-primary' => $m->statut !== 'erreur',
            'border border-danger-border bg-danger-soft text-danger' => $m->statut === 'erreur',
        ])>
        @if ($m->statut === 'erreur')
            {{ $m->contenu }}
        @else
            {!! $this->rendu($m->contenu) !!}
        @endif
    </div>

    @if ($m->statut === 'interrompu')
        <p class="text-[11px] text-danger px-1">Réponse interrompue.</p>
    @endif

    <div class="flex flex-wrap items-center gap-2 px-1">
        @if (! empty($m->sources))
            <p class="text-[11px] text-ink-muted">
                Sources :
                @foreach ($m->sources as $s)
                    @php $libelle = $s['nom'].($s['page'] ? ' p.'.$s['page'] : ''); @endphp
                    @if (in_array($s['document_id'], $documentsExistants, true))
                        <a href="{{ route('secteurs.documents.consulter', [$secteur, $s['document_id']]) }}{{ $s['page'] ? '#page='.$s['page'] : '' }}"
                           target="_blank" class="text-accent hover:text-accent-pressed">{{ $libelle }}</a>
                    @else
                        <span>{{ $s['nom'] }} (document supprimé)</span>
                    @endif
                    @if (! $loop->last) · @endif
                @endforeach
            </p>
        @endif

        @if ($m->statut !== 'complet')
            @if ($m->signale_at)
                <span class="text-[11px] text-ink-muted">Signalé ✓</span>
            @else
                <button type="button" wire:click="signaler({{ $m->id }})" class="text-[11px] text-accent hover:text-accent-pressed">Signaler</button>
            @endif
        @endif

        @if ($m->statut !== 'erreur')
            <button type="button" x-data="{ id: {{ $m->id }} }" x-on:click="copier(@js($m->contenu), id)"
                    x-bind:class="copie === id ? 'text-ok' : 'text-ink-muted'"
                    class="ml-auto rounded px-1.5 py-1 text-[12px] leading-none hover:bg-app-bg hover:text-ink-primary transition"
                    title="Copier la réponse" aria-label="Copier la réponse">
                <span x-text="copie === id ? '✓' : '⧉'"></span>
            </button>
        @endif
    </div>
</div>
