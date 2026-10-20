{{--
    Un tour du chat : la demande de l'utilisateur, ce que l'agent a fait (étapes repliables), sa réponse, les
    fichiers produits et, s'il y en a une, la carte de confirmation d'un changement proposé.
--}}
<div wire:key="tour-{{ $e->id }}" class="flex flex-col gap-3">
    <div class="self-end max-w-[80%] flex flex-col gap-1.5 items-end">
        @if (! empty($e->fichiers_apportes))
            <div class="flex flex-wrap justify-end gap-1.5">
                @foreach ($e->fichiers_apportes as $nom)
                    <span class="text-[11.5px] font-mono rounded-full border border-app-border bg-app-card px-2.5 py-1 text-ink-secondary">📎 {{ $nom }}</span>
                @endforeach
            </div>
        @endif
        <div class="bg-app-bg border border-app-border-soft text-ink-primary rounded-2xl rounded-br-sm px-3.5 py-2.5 text-sm whitespace-pre-wrap">{{ $e->demande === \App\Services\LanceurAgent::DEMANDE_RECETTE ? 'Lancement de l\'agent' : $e->demande }}</div>
    </div>

    @if (! empty($e->etapes))
        <details class="self-start max-w-[88%] rounded-lg border-l-[3px] border-accent bg-app-card px-3 py-1.5 text-[12.5px] text-ink-secondary">
            <summary class="cursor-pointer font-medium text-ink-primary">L'agent a travaillé ({{ count($e->etapes) }} étape{{ count($e->etapes) > 1 ? 's' : '' }})</summary>
            <ul class="mt-1 space-y-0.5">
                @foreach ($e->etapes as $etape)
                    <li>
                        <span class="{{ ($etape['statut'] ?? '') === 'ok' ? 'text-ok' : 'text-danger' }}">{{ ($etape['statut'] ?? '') === 'ok' ? '✓' : '✕' }}</span>
                        {{ \App\Livewire\AgentsPage::phrase($etape['outil'] ?? '?') }}
                        <span class="text-ink-muted">— {{ $etape['resume'] ?? '' }}</span>
                        <span class="font-mono text-[11px] text-ink-muted">({{ $etape['outil'] ?? '' }})</span>
                    </li>
                @endforeach
            </ul>
        </details>
    @endif

    @if ($e->etat === 'en_cours')
        <div class="self-start text-[12.5px] text-ink-muted italic">Ce tour n'a pas été terminé.</div>
    @else
        <div class="self-start max-w-[88%] flex flex-col gap-2">
            <div @class([
                    'rounded-2xl rounded-bl-sm px-3.5 py-2.5 text-sm leading-relaxed',
                    'border border-app-border bg-app-bg text-ink-primary [&_ul]:list-disc [&_ol]:list-decimal [&_ul]:pl-5 [&_ol]:pl-5 [&_li]:my-0.5 [&_p]:mb-2 [&_p:last-child]:mb-0 [&_table]:text-[12px] [&_th]:border [&_td]:border [&_th]:px-2 [&_td]:px-2 [&_th]:bg-app-card [&_code]:font-mono [&_code]:text-[12px]' => $e->etat === 'termine',
                    'border border-danger-border bg-danger-soft text-danger' => $e->etat === 'arret',
                ])>
                @if ($e->etat === 'termine') {!! $this->rendu((string) $e->message) !!}
                @else {{ $e->message }} @endif
            </div>

            @foreach ($e->fichiers_produits ?? [] as $nom)
                <a href="{{ route('agents.fichier', [$e->agent_id, $e->id, $nom]) }}"
                   class="self-start inline-flex items-center gap-2 rounded-lg border border-app-border bg-app-elevated px-3 py-1.5 text-[13px] font-medium text-ink-primary hover:border-accent transition">⬇ Télécharger {{ $nom }}</a>
            @endforeach

            @if ($e->proposition_etat !== null)
                <div class="rounded-xl border border-accent-soft-border bg-accent-soft px-3.5 py-3 text-sm">
                    @php($p = $e->proposition)
                    <p class="font-medium text-ink-primary">
                        @switch($p['type'] ?? '')
                            @case('enregistrer') Enregistrer cet agent sous le nom « {{ $p['nom'] ?? '' }} » @break
                            @case('consignes') Remplacer les consignes permanentes de l'agent @break
                            @case('rappel') {{ ($p['periodicite'] ?? '') === 'aucun' ? 'Supprimer le rappel' : 'Régler un rappel '.($p['periodicite'] ?? '') }} @break
                        @endswitch
                    </p>
                    @if (($p['type'] ?? '') === 'enregistrer' && ! empty($p['description']))
                        <p class="mt-1 text-ink-secondary">{{ $p['description'] }}</p>
                    @endif
                    @if (in_array($p['type'] ?? '', ['enregistrer', 'consignes'], true) && ! empty($p['consignes']))
                        <p class="mt-1 text-[12.5px] text-ink-secondary whitespace-pre-wrap"><span class="font-medium">Consignes :</span> {{ $p['consignes'] }}</p>
                    @endif
                    @if ($e->proposition_etat === 'en_attente')
                        <p class="mt-1 text-[12.5px] text-ink-secondary">Rien ne change tant que vous n'avez pas répondu.</p>
                        @error('proposition-'.$e->id) <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                        <div class="mt-2 flex gap-2">
                            <button type="button" wire:click="accepter({{ $e->id }})" class="rounded-lg bg-accent px-3 py-1.5 text-[13px] font-medium text-sidebar-bg hover:bg-accent-hover transition">Oui, enregistrer</button>
                            <button type="button" wire:click="refuser({{ $e->id }})" class="rounded-lg border border-app-border bg-app-elevated px-3 py-1.5 text-[13px] font-medium text-ink-primary hover:border-accent transition">Non</button>
                        </div>
                    @elseif ($e->proposition_etat === 'acceptee')
                        <p class="mt-1 text-[12.5px] text-ok">✓ Enregistré.</p>
                    @else
                        <p class="mt-1 text-[12.5px] text-ink-muted">Refusé : rien n'a changé.</p>
                    @endif
                </div>
            @endif
        </div>
    @endif
</div>
