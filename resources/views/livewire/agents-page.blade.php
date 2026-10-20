{{--
    Onglet « Agents » : trois colonnes à partir de lg (liste personnelle / chat / documents), sur toute la hauteur
    disponible. En dessous de lg : une seule colonne, hauteur naturelle. Tout passe par le chat ; les changements
    de l'agent (enregistrer, consignes, rappel) sont des CARTES DE CONFIRMATION : l'agent propose, l'utilisateur
    répond, le site enregistre. Pendant un tour, les étapes arrivent au fil de l'eau (wire:stream) : « l'agent réfléchit ».
    Le bouton Arrêter part d'une requête séparée (le worker PHP est occupé), comme pour l'assistant.
--}}
<div class="grid grid-cols-1 lg:grid-cols-[250px_1fr_300px] gap-4 lg:h-[calc(100dvh-120px)] lg:min-h-[560px]"
     x-data="{ collePresDuBas: true, arretEnCours: false, profondeur: 0, survole: false }">

    {{-- Liste personnelle --}}
    <x-card class="p-3 flex flex-col min-h-0">
        <x-section-label class="mb-2">Mes agents</x-section-label>
        <button type="button" wire:click="nouvelAgent"
                class="mb-3 flex items-center gap-2 rounded-lg border border-app-border bg-app-elevated px-3 py-2 text-[13px] font-medium text-ink-primary hover:border-accent transition">
            <span class="w-5 h-5 shrink-0 rounded bg-accent text-sidebar-bg grid place-items-center text-xs font-semibold leading-none">＋</span>
            Nouvel agent
        </button>

        <div class="flex-1 flex flex-col gap-0.5 overflow-y-auto">
            @if ($aFaire->isNotEmpty())
                <div class="px-2 pt-1 pb-1 text-[10px] font-semibold uppercase tracking-[0.08em] text-ink-muted">À faire</div>
                @foreach ($aFaire as $a)
                    <button type="button" wire:key="afaire-{{ $a->id }}" wire:click="selectionner({{ $a->id }})"
                            class="flex items-center justify-between gap-2 rounded px-2 py-1.5 text-left text-[13px] bg-accent-soft border border-accent-soft-border hover:border-accent transition">
                        <span class="truncate text-ink-primary">{{ $a->nom }}</span>
                        <span class="shrink-0 rounded-full bg-accent px-2 py-0.5 text-[10.5px] font-semibold text-sidebar-bg">Rappel</span>
                    </button>
                @endforeach
            @endif

            <div class="px-2 pt-3 pb-1 text-[10px] font-semibold uppercase tracking-[0.08em] text-ink-muted first:pt-0">Agents</div>
            @forelse ($agents as $a)
                <div wire:key="agent-{{ $a->id }}" @class([
                        'group/ag flex items-center gap-1 rounded px-1',
                        'bg-accent-soft-strong' => $a->id === $agentId,
                        'hover:bg-app-bg' => $a->id !== $agentId,
                    ])>
                    <button type="button" wire:click="selectionner({{ $a->id }})" title="{{ $a->nom }}"
                            @class([
                                'flex-1 min-w-0 flex items-center justify-between gap-2 text-left px-1.5 py-1.5 text-[13px]',
                                'font-medium text-ink-primary' => $a->id === $agentId,
                                'text-ink-secondary' => $a->id !== $agentId,
                                'opacity-65' => $a->estEnVeille(),
                            ])>
                        <span class="truncate">{{ $a->nom }}</span>
                        <small class="shrink-0 text-[11px] text-ink-muted">
                            @if ($a->etat === 'brouillon') brouillon
                            @elseif ($a->estEnVeille()) en veille
                            @else {{ $a->dernier_lancement_at?->format('d/m') }} @endif
                        </small>
                    </button>
                    <button type="button" wire:click="supprimerAgent({{ $a->id }})"
                            wire:confirm="Supprimer l'agent « {{ $a->nom }} », ses fichiers et son historique ? Cette action est définitive."
                            aria-label="Supprimer l'agent {{ $a->nom }}"
                            class="shrink-0 px-1.5 py-1 rounded text-ink-muted opacity-0 group-hover/ag:opacity-100 hover:text-danger hover:bg-danger-soft transition">✕</button>
                </div>
            @empty
                <p class="text-[12px] text-ink-muted italic px-2 py-1">Aucun agent pour l'instant. Cliquez sur « Nouvel agent ».</p>
            @endforelse
        </div>
    </x-card>

    {{-- Chat --}}
    <x-card class="flex flex-col min-h-0 relative overflow-hidden"
            x-on:dragenter.prevent="profondeur++; survole = true"
            x-on:dragover.prevent
            x-on:dragleave.prevent="profondeur = Math.max(0, profondeur - 1); if (profondeur === 0) survole = false"
            x-on:drop.prevent="profondeur = 0; survole = false; $refs.depot.files = $event.dataTransfer.files; $refs.depot.dispatchEvent(new Event('change'))">
        @if ($agent === null)
            <div class="flex-1 grid place-items-center px-6 text-center">
                <div class="max-w-[420px]">
                    <p class="text-base font-semibold text-ink-primary">Bonjour {{ $prenom }}</p>
                    <p class="text-sm text-ink-secondary mt-2">Un agent fait pour vous un travail sur vos fichiers Excel et Word : vous lui expliquez ce que vous voulez dans le chat, il vous montre ce qu'il fait, et vous validez.</p>
                    <button type="button" wire:click="nouvelAgent" class="mt-4 rounded-lg bg-accent px-4 py-2 text-sm font-medium text-sidebar-bg hover:bg-accent-hover transition">Créer mon premier agent</button>
                </div>
            </div>
        @else
            <div class="flex items-center justify-between gap-2 border-b border-app-border-soft px-4 py-2.5">
                <div class="min-w-0">
                    <h2 class="text-[15px] font-semibold text-ink-primary truncate">{{ $agent->nom }}</h2>
                    <p class="text-[11.5px] text-ink-secondary">
                        @if ($agent->etat === 'brouillon') En conception : pas encore enregistré
                        @elseif ($agent->estEnVeille()) En veille
                        @else Dernier lancement : {{ $agent->dernier_lancement_at?->format('d/m/Y') ?? 'jamais' }}@if ($agent->rappel) · Rappel {{ $agent->rappel->periodicite }}@endif @endif
                    </p>
                </div>
                @if ($agent->estEnVeille())
                    <button type="button" wire:click="reactiver" class="shrink-0 rounded-lg bg-accent px-3 py-1.5 text-[13px] font-medium text-sidebar-bg hover:bg-accent-hover transition">Réactiver l'agent</button>
                @endif
            </div>

            <div class="flex-1 overflow-y-auto px-4 py-5" x-ref="fil"
                 x-on:scroll="collePresDuBas = ($el.scrollHeight - $el.scrollTop - $el.clientHeight) <= 80"
                 x-init="
                     $el.scrollTop = $el.scrollHeight;
                     new MutationObserver(() => { if (collePresDuBas) $el.scrollTop = $el.scrollHeight; }).observe($el, { childList: true, subtree: true, characterData: true });
                 ">
                <div class="max-w-[760px] mx-auto min-h-full flex flex-col justify-end gap-4">
                    @if ($executions->isEmpty())
                        <div class="self-start max-w-[85%] rounded-2xl rounded-bl-sm px-3.5 py-2.5 border border-app-border bg-app-bg text-sm text-ink-primary leading-relaxed">
                            @if ($agent->etat === 'brouillon')
                                Bonjour {{ $prenom }}. Glissez vos classeurs Excel ou documents Word dans cette fenêtre, puis expliquez-moi ce que vous voulez obtenir. Je vous montrerai ce que je fais et un aperçu avant de créer quoi que ce soit.
                            @else
                                Déposez les documents du jour dans le panneau de droite, puis cliquez sur « Lancer l'agent ». Pour modifier cet agent, écrivez-moi simplement ce que vous voulez changer.
                            @endif
                        </div>
                    @endif

                    @foreach ($executions as $e)
                        @include('livewire.agents-page.tour', ['e' => $e])
                    @endforeach

                    {{-- Pendant un tour : message et étapes diffusés au fil de l'eau --}}
                    <div wire:loading.flex wire:target="envoyer,lancerAgent" class="flex-col gap-3">
                        <div class="self-end max-w-[80%] bg-app-bg border border-app-border-soft text-ink-primary rounded-2xl rounded-br-sm px-3.5 py-2.5 text-sm whitespace-pre-wrap"
                             wire:stream="question-en-cours"></div>
                        <div class="self-start max-w-[85%] rounded-2xl rounded-bl-sm px-3.5 py-2.5 border border-app-border bg-app-bg text-[12.5px] text-ink-secondary">
                            <p class="mb-1 font-medium text-ink-primary animate-pulse">L'agent travaille…</p>
                            <div wire:stream="etapes-en-cours"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="border-t border-app-border-soft px-4 py-3">
                <form wire:submit="envoyer" x-on:submit="$refs.message.value = ''; $refs.message.style.height = 'auto'; arretEnCours = false" class="max-w-[760px] mx-auto">
                    <input type="file" x-ref="depot" wire:model="depots" multiple hidden accept=".xlsx,.xlsm,.csv,.docx" aria-label="Déposer des documents">
                    @error('message') <p class="mb-1 text-xs text-danger">{{ $message }}</p> @enderror
                    <div class="flex items-end gap-2 bg-app-bg border border-app-border rounded-xl px-3 py-2 focus-within:border-accent transition">
                        <button type="button" x-on:click="$refs.depot.click()"
                                class="w-9 h-9 shrink-0 rounded-lg text-ink-secondary hover:bg-app-elevated hover:text-ink-primary transition flex items-center justify-center text-base"
                                aria-label="Déposer des documents" title="Déposer des documents (Excel, CSV, Word — 20 Mo)">📎</button>
                        <textarea x-ref="message" wire:model="message" rows="1" aria-label="Votre message à l'agent"
                                  placeholder="{{ $agent->etat === 'brouillon' ? 'Expliquez ce que vous voulez que l\'agent fasse…' : 'Écrivez à l\'agent…' }}"
                                  x-on:input="$el.style.height = 'auto'; $el.style.height = Math.min($el.scrollHeight, 200) + 'px'"
                                  x-on:keydown.enter="if (! $event.shiftKey) { $event.preventDefault(); $el.form.requestSubmit() }"
                                  class="flex-1 resize-none bg-transparent border-0 focus:outline-none focus:ring-0 text-sm text-ink-primary py-1 max-h-[200px]"></textarea>
                        <x-primary-button type="submit" wire:loading.remove wire:target="envoyer,lancerAgent" class="!rounded-lg !px-3 !py-2 shrink-0">➤</x-primary-button>
                        <button type="button" wire:loading.flex wire:target="envoyer,lancerAgent" x-bind:disabled="arretEnCours"
                                x-on:click="
                                    if (arretEnCours) return;
                                    arretEnCours = true;
                                    fetch('{{ route('agents.arreter', $agent) }}', {
                                        method: 'POST',
                                        headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' },
                                    }).then((r) => { if (! r.ok) arretEnCours = false; }).catch(() => { arretEnCours = false; });
                                "
                                class="items-center gap-1.5 !rounded-lg !px-3 !py-2 shrink-0 border border-app-border bg-app-elevated text-ink-primary text-sm font-medium hover:border-accent transition disabled:opacity-60 disabled:cursor-not-allowed">
                            <span aria-hidden="true">■</span>
                            <span x-text="arretEnCours ? 'Arrêt en cours…' : 'Arrêter'"></span>
                        </button>
                    </div>
                    @error('depots') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                    @error('depots.*') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                    <p class="mt-1.5 text-[11px] text-ink-muted text-center">Vos fichiers d'origine ne sont jamais modifiés : l'agent travaille sur une copie.</p>
                </form>
            </div>

            <div x-show="survole" x-cloak
                 class="absolute inset-2 z-20 rounded-xl border-2 border-dashed border-accent bg-accent-soft/95 grid place-items-center text-center pointer-events-none">
                <div>
                    <p class="text-base font-semibold text-ink-primary">Déposez vos documents ici</p>
                    <p class="text-sm text-ink-secondary mt-1">Excel (.xlsx, .xlsm), CSV ou Word (.docx) — 20 Mo maximum</p>
                </div>
            </div>
        @endif
    </x-card>

    {{-- Documents --}}
    <x-card class="p-3 flex flex-col min-h-0 overflow-y-auto">
        @if ($agent !== null)
            <x-section-label class="mb-2">Documents</x-section-label>
            @if ($agent->estEnVeille())
                <p class="text-[12.5px] text-ink-muted">Réactivez l'agent pour déposer des documents.</p>
            @else
                <button type="button" x-on:click="$refs.depot.click()"
                        class="w-full rounded-lg border-2 border-dashed border-app-border bg-app-elevated px-3 py-3 text-center text-[12.5px] text-ink-secondary hover:border-accent transition">
                    Glissez ou ajoutez les documents<br><span class="text-ink-muted">.xlsx, .xlsm, .csv, .docx — 20 Mo</span>
                </button>
                <div wire:loading.flex wire:target="depots" class="mt-2 text-[12px] text-ink-muted animate-pulse">Lecture des documents…</div>

                <div class="mt-3 flex flex-col gap-2">
                    @foreach ($apports as $a)
                        @php($l = $a->lecture)
                        <div wire:key="apport-{{ $a->id }}" @class([
                                'rounded-lg px-2.5 py-2 text-[12.5px] flex gap-2 justify-between',
                                'bg-ok-soft' => ($l['statut'] ?? null) === 'ok',
                                'bg-accent-soft-strong' => ($l['statut'] ?? null) === 'avertissement' || $l === null,
                                'bg-danger-soft' => ($l['statut'] ?? null) === 'erreur',
                            ])>
                            <div class="min-w-0">
                                <p class="font-medium text-ink-primary break-words">
                                    @if (($l['statut'] ?? null) === 'ok') ✓ @elseif (($l['statut'] ?? null) === 'erreur') ✕ @else ⚠ @endif
                                    {{ $a->nom }}
                                </p>
                                <p class="text-ink-secondary break-words">
                                    @if ($l === null)
                                        Lecture non vérifiée.
                                    @elseif ($l['statut'] === 'erreur')
                                        {{ $l['message'] }}
                                    @elseif ($l['type'] === 'word')
                                        Lu : {{ $l['blocs'] }} blocs, {{ $l['nb_tableaux'] }} tableau(x).@if (! empty($l['message'])) {{ $l['message'] }}@endif
                                    @else
                                        @foreach ($l['feuilles'] as $f)
                                            Feuille « {{ $f['nom'] }} » : {{ $f['lignes'] }} lignes. Colonnes : {{ implode(', ', array_slice($f['colonnes'], 0, 8)) }}{{ count($f['colonnes']) > 8 ? '…' : '' }}.<br>
                                        @endforeach
                                        @if (! empty($l['message'])) {{ $l['message'] }}@endif
                                    @endif
                                </p>
                            </div>
                            <button type="button" wire:click="retirerApport({{ $a->id }})" aria-label="Retirer {{ $a->nom }}"
                                    class="shrink-0 self-start text-ink-muted hover:text-danger">✕</button>
                        </div>
                    @endforeach
                </div>

                @if ($agent->etat === 'actif')
                    <button type="button" wire:click="lancerAgent" wire:loading.attr="disabled" wire:target="envoyer,lancerAgent"
                            @disabled(! $peutLancer)
                            class="mt-3 w-full rounded-lg bg-accent px-3 py-2 text-sm font-medium text-sidebar-bg hover:bg-accent-hover transition disabled:opacity-50 disabled:cursor-not-allowed">▶ Lancer l'agent</button>
                    <p class="mt-1.5 text-[11.5px] text-ink-secondary">
                        @if ($apports->isEmpty()) Déposez d'abord les documents du jour.
                        @elseif (! $peutLancer) Un document est illisible : retirez-le ou remplacez-le.
                        @else Rejoue la recette validée sur ces documents. @endif
                    </p>
                @elseif ($agent->etat === 'brouillon')
                    <p class="mt-3 text-[11.5px] text-ink-secondary">Le bouton « Lancer l'agent » apparaît une fois l'agent enregistré : demandez-le dans le chat quand le résultat vous convient.</p>
                @endif
            @endif

            @if ($references->isNotEmpty())
                <x-section-label class="mt-5 mb-1">Références de l'agent</x-section-label>
                @foreach ($references as $r)
                    <div wire:key="ref-{{ $r->id }}" class="flex justify-between gap-2 border-b border-app-border-soft py-1 text-[12.5px]">
                        <span class="break-words min-w-0">{{ $r->nom }}</span>
                        <span class="shrink-0 text-ink-muted">{{ number_format($r->taille / 1024 / 1024, 1, ',', ' ') }} Mo</span>
                    </div>
                @endforeach
            @endif

            @if ($produits->isNotEmpty())
                <x-section-label class="mt-5 mb-1">Fichiers produits</x-section-label>
                @foreach ($produits as $p)
                    <a wire:key="prod-{{ $p['execution']->id }}-{{ $p['nom'] }}" href="{{ route('agents.fichier', [$agent, $p['execution'], $p['nom']]) }}"
                       class="flex justify-between gap-2 border-b border-app-border-soft py-1 text-[12.5px] hover:text-accent-pressed">
                        <span class="break-words min-w-0">{{ $p['nom'] }}</span><span class="shrink-0">⬇</span>
                    </a>
                @endforeach
            @endif
        @else
            <p class="text-[12.5px] text-ink-muted">Choisissez un agent pour voir ses documents.</p>
        @endif
    </x-card>
</div>
