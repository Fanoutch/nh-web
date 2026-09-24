{{--
    Bulle d'une réponse de l'assistant : Markdown rendu en HTML sûr pour les réponses enregistrées
    (le service AssistantChat échappe le HTML source et désactive les liens dangereux).

    Sources : une pastille par source citée.
    - Sans document/pièce jointe retrouvable (document supprimé) : texte simple, non cliquable.
    - Avec un extrait (réponse enregistrée depuis cette version) : bouton qui ouvre l'encart
      « Passage utilisé » ci-dessous (extrait surligné + lien vers le document à la bonne page).
    - Sans extrait (réponse enregistrée AVANT cette version) : simple lien vers le document, comme
      auparavant — pas d'encart, l'extrait n'a jamais été enregistré pour ce message.
    $questionsPrecedentes et $piecesParNom viennent de AssistantChat::render() (scope hérité du
    partiel parent) : la question qui a amené cette réponse (pour le surlignage), et l'identifiant
    de pièce jointe correspondant à un nom de fichier (le service Python ne renvoie pas cet
    identifiant dans les sources « pj »).
--}}
<div class="self-start max-w-[85%] flex flex-col gap-1.5" wire:key="msg-{{ $m->id }}" x-data="{ sourceOuverte: null }">
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
            {{-- aria-label : seul repère annonçant ce groupe de pastilles pour les lecteurs d'écran
                 (le préfixe « Sources : » visible a été retiré au profit du style pastille). --}}
            <div class="flex flex-wrap items-center gap-1.5" role="group" aria-label="Sources">
                @foreach ($m->sources as $j => $s)
                    @php
                        $estPj = ! empty($s['pj']);
                        $idPiece = $estPj ? ($piecesParNom[$s['nom']] ?? null) : null;
                        $documentExiste = $estPj ? ($idPiece !== null) : in_array($s['document_id'] ?? null, $documentsExistants, true);
                        $aEncart = $documentExiste && array_key_exists('extrait', $s);
                        $chemin = $s['chemin'] ?? '';
                        $segmentsChemin = $chemin !== '' ? explode(' › ', $chemin) : [];
                        $dernierDossier = $segmentsChemin !== [] ? end($segmentsChemin).' › ' : '';
                        $nomSansExtension = preg_replace('/\.(pdf|docx|txt)$/i', '', $s['nom'] ?? '');
                        $pageLabel = ! empty($s['page']) ? ' p.'.$s['page'] : '';
                    @endphp

                    @if (! $documentExiste)
                        <span class="text-[11.5px] font-mono text-ink-muted border border-dashed border-app-border rounded-full px-2.5 py-1">{{ $s['nom'] }} (document supprimé)</span>
                    @elseif ($aEncart)
                        <button type="button"
                                x-on:click="sourceOuverte = sourceOuverte === {{ $j }} ? null : {{ $j }}"
                                x-bind:class="sourceOuverte === {{ $j }} ? 'border-accent text-accent-pressed bg-accent-soft' : 'border-app-border bg-app-card text-ink-secondary'"
                                class="text-[11.5px] font-mono rounded-full border px-2.5 py-1 hover:border-accent transition"
                                x-bind:aria-expanded="(sourceOuverte === {{ $j }}).toString()"
                                title="Voir le passage utilisé">{{ $estPj ? '📎 ' : '' }}{{ $dernierDossier.$nomSansExtension.$pageLabel }}</button>
                    @else
                        {{-- Source sans extrait (message enregistré avant cette version) : simple lien, comme auparavant. --}}
                        <a href="{{ route('secteurs.documents.consulter', [$secteur, $s['document_id']]) }}{{ $pageLabel !== '' ? '#page='.$s['page'] : '' }}"
                           target="_blank" class="text-accent hover:text-accent-pressed text-[11.5px]">{{ $s['nom'].$pageLabel }}</a>
                    @endif
                @endforeach
            </div>
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

    {{-- Encart « Passage utilisé », un par source avec extrait (repliés, ouverts par leur pastille ci-dessus). --}}
    @if (! empty($m->sources))
        @foreach ($m->sources as $j => $s)
            @continue(empty($s['extrait']))
            @php
                $estPj = ! empty($s['pj']);
                $idPiece = $estPj ? ($piecesParNom[$s['nom']] ?? null) : null;
                $documentExiste = $estPj ? ($idPiece !== null) : in_array($s['document_id'] ?? null, $documentsExistants, true);
            @endphp
            @continue(! $documentExiste)
            @php
                $chemin = $s['chemin'] ?? '';
                $extension = strtolower(pathinfo($s['nom'] ?? '', PATHINFO_EXTENSION));
                $surligne = $this->surligner($s['extrait'], $questionsPrecedentes[$m->id] ?? '');
                $titre = 'Passage utilisé — '
                    .($estPj ? 'votre pièce jointe' : ($chemin !== '' ? $chemin.' › ' : '').$s['nom'])
                    .(! empty($s['page']) ? ', page '.$s['page'] : '');
                $url = $estPj
                    ? route('secteurs.assistant.piece', [$secteur, $idPiece])
                    : route('secteurs.documents.consulter', [$secteur, $s['document_id']]);
                if ($extension === 'pdf' && ! empty($s['page'])) {
                    $url .= '#page='.$s['page'];
                }
                // La mention de la page n'apparaît que pour un PDF (seul format où #page=N est honoré) :
                // un .txt peut aussi porter un numéro de page (le lecteur Python découpe sur les sauts
                // de page), mais l'ouvrir ne saute pas dessus — le libellé ne doit pas le promettre.
                $libelleBouton = match (true) {
                    $extension === 'docx' => 'Télécharger le document',
                    $extension === 'pdf' && ! empty($s['page']) => 'Ouvrir le document à la page '.$s['page'],
                    default => 'Ouvrir le document',
                };
            @endphp
            <div x-show="sourceOuverte === {{ $j }}" x-cloak
                 class="mt-0.5 rounded-xl border border-app-border border-l-[3px] border-l-accent bg-app-card px-3 py-2.5 text-[13px]">
                <div class="text-[11px] font-semibold uppercase tracking-[0.06em] text-ink-muted mb-1">{{ $titre }}</div>
                <blockquote class="text-ink-primary leading-relaxed mb-2">« {!! $surligne !!} »</blockquote>
                <a href="{{ $url }}" target="_blank"
                   class="inline-flex items-center gap-1.5 rounded-lg border border-accent-soft-border bg-accent-soft px-2.5 py-1 text-[12.5px] font-medium text-accent-pressed hover:bg-accent-soft-strong">{{ $libelleBouton }} ↗</a>
                @if ($extension === 'docx')
                    <span class="text-[11.5px] text-ink-muted ml-2">Word ne permet pas d'ouvrir directement à une page précise</span>
                @endif
            </div>
        @endforeach
    @endif
</div>
