<div class="space-y-4">
    {{-- Flash messages --}}
    @if (session('success'))
        <div class="px-4 py-3 bg-ok-soft border border-ok-border text-ok rounded text-sm">
            {{ session('success') }}
        </div>
    @endif
    @if (session('error'))
        <div class="px-4 py-3 bg-danger-soft border border-danger-border text-danger rounded text-sm">
            {{ session('error') }}
        </div>
    @endif


    {{-- Onglets : utilisateurs / catalogue d'agents --}}
    <nav class="flex gap-1 border-b border-app-border">
        <button type="button" wire:click="ouvrirUtilisateurs"
                @class(['px-4 py-2 -mb-px text-[13px] font-medium border-b-2 transition', 'border-accent text-accent' => $onglet === 'utilisateurs', 'border-transparent text-ink-muted hover:text-ink-primary' => $onglet !== 'utilisateurs'])>
            Utilisateurs
        </button>
        <button type="button" wire:click="ouvrirCatalogue"
                @class(['px-4 py-2 -mb-px text-[13px] font-medium border-b-2 transition', 'border-accent text-accent' => $onglet === 'catalogue', 'border-transparent text-ink-muted hover:text-ink-primary' => $onglet !== 'catalogue'])>
            Catalogue d'agents
            <span class="font-mono text-[10px] bg-accent-soft-strong text-warn px-1.5 py-0.5 rounded">{{ count($modeles) }}</span>
        </button>
    </nav>

    @if ($onglet === 'catalogue')
        <p class="text-[13px] text-ink-secondary">Les agents que vous avez développés. Une personne qui en reçoit un le retrouve dans son onglet Agents, prêt à lancer.</p>
        <x-card class="overflow-hidden">
            <table class="w-full text-[13px]">
                <thead>
                    <tr class="border-b border-app-border">
                        <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">Agent</th>
                        <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">Ce qu'il fait</th>
                        <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">Assigné à</th>
                        <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">Version</th>
                        <th class="px-4 py-2.5"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($modeles as $slug => $m)
                        @php
                            $gens = $copies->get($slug, collect());
                            $manquantes = $catalogue->referencesManquantes($slug);
                            $aMettreAJour = $catalogue->nombreAMettreAJour($slug);
                        @endphp
                        <tr class="border-b border-app-border-soft hover:bg-app-bg transition-colors" wire:key="modele-{{ $slug }}">
                            <td class="px-4 py-3 font-medium text-ink-primary">{{ $m['nom'] }}</td>
                            <td class="px-4 py-3 text-ink-secondary">{{ $m['description'] }}</td>
                            <td class="px-4 py-3 font-mono text-[12px]">{{ $gens->count() }} personne{{ $gens->count() > 1 ? 's' : '' }}</td>
                            <td class="px-4 py-3">
                                @if ($manquantes)
                                    <span class="inline-flex items-center bg-danger-soft text-danger border border-danger-border text-[11px] px-2 py-0.5 rounded font-mono font-medium" title="Fichier(s) à déposer sur le serveur : {{ implode(', ', $manquantes) }}">Références manquantes</span>
                                @elseif ($aMettreAJour > 0)
                                    <span class="inline-flex items-center bg-accent-soft-strong text-warn text-[11px] px-2 py-0.5 rounded font-mono font-medium">{{ $aMettreAJour }} copie{{ $aMettreAJour > 1 ? 's' : '' }} à mettre à jour</span>
                                @else
                                    <span class="inline-flex items-center bg-ok-soft text-ok border border-ok-border text-[11px] px-2 py-0.5 rounded font-mono font-medium">À jour</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                @if ($currentIsSuperAdmin)
                                    <div class="flex gap-1.5 justify-end">
                                        @if ($m['references'])
                                            <button type="button" wire:click="basculerReferences('{{ $slug }}')"
                                                    class="px-3 py-1 rounded bg-app-elevated border border-app-border text-ink-secondary text-[11px] font-medium hover:border-accent transition">{{ $refsOuvert === $slug ? 'Fermer' : 'Références' }}</button>
                                        @endif
                                        <button wire:click="tester('{{ $slug }}')" @disabled($manquantes)
                                                title="{{ $manquantes ? 'Déposez d\'abord le fichier de référence (bouton Références)' : 'Crée votre copie de test et l\'ouvre dans votre onglet Agents, comme la verrait un utilisateur' }}"
                                                @class(['px-3 py-1 rounded bg-ok-soft border border-ok-border text-ok text-[11px] font-semibold transition', 'hover:bg-ok-border' => ! $manquantes, 'opacity-50 cursor-not-allowed' => $manquantes])>Tester</button>
                                        <button wire:click="basculerAssignation('{{ $slug }}')" @disabled($manquantes)
                                                title="{{ $manquantes ? 'Déposez d\'abord le fichier de référence (bouton Références)' : '' }}"
                                                @class(['px-3 py-1 rounded bg-accent text-sidebar-bg text-[11px] font-semibold', 'opacity-50 cursor-not-allowed' => $manquantes])>{{ $ouvert === $slug ? 'Fermer' : 'Assigner' }}</button>
                                        <button wire:click="mettreAJourCopies('{{ $slug }}')" wire:confirm="Appliquer la dernière version de « {{ $m['nom'] }} » aux {{ $gens->count() }} copie(s) déjà assignée(s) ?"
                                                title="Applique vos dernières modifications aux copies déjà assignées"
                                                class="px-3 py-1 rounded bg-app-elevated border border-app-border text-ink-secondary text-[11px] font-medium hover:border-accent transition">Mettre à jour les copies</button>
                                    </div>
                                @endif
                            </td>
                        </tr>
                        @if ($refsOuvert === $slug && $currentIsSuperAdmin && $m['references'])
                            <tr class="border-b border-app-border-soft" wire:key="refs-{{ $slug }}">
                                <td colspan="5" class="px-4 py-4 bg-accent-soft">
                                    <div class="text-[10px] font-semibold uppercase tracking-[0.1em] text-ink-muted mb-2">Fichiers de référence de « {{ $m['nom'] }} »</div>
                                    <div class="space-y-2">
                                        @foreach ($catalogue->etatReferences($slug) as $rang => $ref)
                                            <div class="bg-app-card border border-app-border rounded-lg px-4 py-3 flex items-center justify-between gap-3" wire:key="ref-{{ $slug }}-{{ $rang }}">
                                                <div>
                                                    <div class="font-medium text-ink-primary">📄 {{ $ref['nom'] }}</div>
                                                    <div class="text-[11px] text-ink-muted">
                                                        @if ($ref['present'])
                                                            Déposé le {{ $ref['date']->format('d/m \à H:i') }} · {{ number_format($ref['taille'] / 1024, 0, ',', ' ') }} Ko
                                                        @else
                                                            Aucun fichier déposé
                                                        @endif
                                                    </div>
                                                </div>
                                                <div class="flex items-center gap-3">
                                                    @if ($ref['present'])
                                                        <span class="inline-flex items-center bg-ok-soft text-ok border border-ok-border text-[11px] px-2 py-0.5 rounded font-mono font-medium">Présent</span>
                                                    @else
                                                        <span class="inline-flex items-center bg-accent-soft-strong text-warn text-[11px] px-2 py-0.5 rounded font-mono font-medium">Manquant</span>
                                                    @endif
                                                    <label @class(['px-3 py-1.5 rounded-md text-[12px] cursor-pointer', 'bg-app-elevated border border-app-border text-ink-secondary font-medium hover:border-accent' => $ref['present'], 'bg-accent text-sidebar-bg font-semibold' => ! $ref['present']])>
                                                        <span wire:loading.remove wire:target="depots.{{ $slug }}.{{ $rang }}">{{ $ref['present'] ? 'Remplacer' : 'Déposer le fichier' }}</span>
                                                        <span wire:loading wire:target="depots.{{ $slug }}.{{ $rang }}">Envoi…</span>
                                                        <input type="file" class="hidden" accept=".docx,.xlsx" wire:model="depots.{{ $slug }}.{{ $rang }}">
                                                    </label>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                    <div class="text-[11px] text-ink-muted mt-2">Le nom est imposé par l'agent. Seuls les fichiers .docx et .xlsx sont acceptés (20 Mo au plus). Le fichier reste sur le serveur : il n'est jamais envoyé dans le dépôt du code.</div>
                                    @if ($aMettreAJour > 0)
                                        <div class="mt-3 text-[12px] text-warn">Les {{ $aMettreAJour }} copie(s) déjà assignée(s) utilisent encore l'ancienne version. Cliquez sur « Mettre à jour les copies ».</div>
                                    @endif
                                </td>
                            </tr>
                        @endif
                        @if ($ouvert === $slug && $currentIsSuperAdmin)
                            <tr class="border-b border-app-border-soft" wire:key="assigner-{{ $slug }}">
                                <td colspan="5" class="px-4 py-4 bg-accent-soft">
                                    <div class="text-[10px] font-semibold uppercase tracking-[0.1em] text-ink-muted mb-1.5">Assigner « {{ $m['nom'] }} » par e-mail</div>
                                    <form wire:submit="assignerParMail('{{ $slug }}')" class="flex gap-2 mb-1">
                                        <input type="text" wire:model="saisieMails" list="adresses-agents"
                                               placeholder="prenom.nom@exemple.fr (plusieurs adresses possibles, séparées par une virgule)"
                                               class="flex-1 bg-app-elevated border border-app-border rounded-md px-3 py-2 text-sm placeholder:text-ink-muted focus:outline-none focus:border-accent">
                                        <button type="submit" class="px-4 py-2 rounded-md bg-accent text-sidebar-bg text-[13px] font-semibold">Assigner</button>
                                    </form>
                                    <div class="text-[11px] text-ink-muted mb-3">Une adresse inconnue du site est refusée : la personne doit déjà avoir un compte.</div>
                                    <div class="text-[10px] font-semibold uppercase tracking-[0.1em] text-ink-muted mb-1">Déjà assigné à</div>
                                    <div class="flex flex-wrap gap-1.5">
                                        @forelse ($gens as $copie)
                                            <span class="inline-flex items-center gap-1 border border-app-border bg-app-card rounded-full pl-2.5 pr-1 py-0.5 text-[12px]" wire:key="copie-{{ $copie->id }}">
                                                {{ $copie->user->name }}
                                                <button type="button" wire:click="retirerAgent({{ $copie->id }})" wire:confirm="Retirer « {{ $m['nom'] }} » à {{ $copie->user->name }} ? Son historique est conservé."
                                                        class="px-1.5 text-ink-muted hover:text-danger rounded-full" aria-label="Retirer">✕</button>
                                            </span>
                                        @empty
                                            <span class="text-xs text-ink-muted italic">Personne pour l'instant.</span>
                                        @endforelse
                                    </div>
                                    <datalist id="adresses-agents">
                                        @foreach (\App\Models\User::orderBy('email')->pluck('email') as $adresse)
                                            <option value="{{ $adresse }}">
                                        @endforeach
                                    </datalist>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr><td colspan="5" class="px-4 py-12 text-center text-ink-muted text-sm">Aucun agent dans le catalogue pour l'instant.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-card>
    @else
    {{-- Search + role info --}}
    <div class="flex justify-between items-center gap-3">
        <input type="text" wire:model.live.debounce.300ms="search"
               placeholder="Rechercher (nom, email)"
               class="w-96 bg-app-elevated border border-app-border text-ink-primary rounded-md px-3 py-2 text-sm placeholder:text-ink-muted focus:outline-none focus:border-accent focus:ring-2 focus:ring-accent/20 transition" />

        <div class="flex items-center gap-3 text-xs">
            @if ($currentIsSuperAdmin)
                <span class="inline-flex items-center gap-1 bg-[#ede9fe] text-[#6d28d9] border border-[#c4b5fd] px-2 py-1 rounded font-mono font-medium">
                    Vous êtes super admin
                </span>
            @else
                <span class="inline-flex items-center gap-1 bg-neutral-soft text-neutral border border-neutral-border px-2 py-1 rounded font-mono">
                    Vous êtes admin (lecture seule)
                </span>
            @endif
            <span class="text-ink-muted">
                {{ $users->total() }} utilisateur{{ $users->total() > 1 ? 's' : '' }}
            </span>
        </div>
    </div>

    <div class="flex items-center gap-2 text-[12px]">
        <span class="text-ink-muted">Filtre :</span>
        @foreach (['all' => 'Tous', 'technicien' => 'Techniciens', 'pn' => 'Personnel Navigant', 'admin' => 'Admins'] + $secteurs->mapWithKeys(fn ($s) => ['secteur-' . $s->id => $s->nom])->all() as $key => $label)
            <button wire:click="$set('roleFilter', '{{ $key }}')"
                    class="px-2.5 py-1 rounded border {{ $roleFilter === $key ? 'bg-accent text-white border-accent' : 'bg-app-elevated border-app-border text-ink-secondary hover:border-accent' }} transition">
                {{ $label }}
            </button>
        @endforeach
    </div>

    <x-card class="overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-[13px]">
                <thead>
                    <tr class="border-b border-app-border">
                        <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted w-12">ID</th>
                        <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">Nom</th>
                        <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">Email</th>
                        <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted w-40">Statut</th>
                        <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted w-48">Secteurs</th>
                        <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted w-64">Agents</th>
                        <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted w-72">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($users as $u)
                        <tr class="border-b border-app-border-soft hover:bg-app-bg transition-colors" wire:key="user-{{ $u->id }}">
                            <td class="px-4 py-2.5 font-mono text-xs text-ink-muted">{{ $u->id }}</td>
                            <td class="px-4 py-2.5 font-medium text-ink-primary">{{ $u->name }}</td>
                            <td class="px-4 py-2.5 text-ink-secondary">{{ $u->email }}</td>
                            <td class="px-4 py-2.5">
                                <div class="flex flex-wrap gap-1">
                                    @if ($u->is_super_admin)
                                        <span class="inline-flex items-center gap-1 bg-[#ede9fe] text-[#6d28d9] border border-[#c4b5fd] text-[11px] px-2 py-0.5 rounded font-mono font-medium">
                                            Super Admin
                                        </span>
                                    @elseif ($u->is_admin)
                                        <span class="inline-flex items-center gap-1 bg-accent-soft-strong text-warn text-[11px] px-2 py-0.5 rounded font-mono font-medium">
                                            Admin
                                        </span>
                                    @endif
                                    @if ($u->is_personnel_navigant)
                                        <span class="inline-flex items-center gap-1 bg-ok-soft text-ok border border-ok-border text-[11px] px-2 py-0.5 rounded font-mono font-medium">
                                            Personnel Navigant
                                        </span>
                                    @elseif (! $u->is_admin && ! $u->is_super_admin)
                                        <span class="inline-flex items-center gap-1 bg-neutral-soft text-neutral border border-neutral-border text-[11px] px-2 py-0.5 rounded font-mono">
                                            Technicien
                                        </span>
                                    @endif
                                </div>
                            </td>
                            <td class="px-4 py-2.5">
                                <div class="flex flex-col gap-1">
                                    @foreach ($secteurs as $s)
                                        @php $roleSecteur = $u->secteurs->firstWhere('id', $s->id)?->pivot->role ?? 'aucun'; @endphp
                                        <label class="flex items-center gap-2 text-[11px]" wire:key="user-{{ $u->id }}-secteur-{{ $s->id }}">
                                            <span class="font-mono text-ink-secondary min-w-12">{{ $s->nom }}</span>
                                            <select wire:change="setRoleSecteur({{ $u->id }}, {{ $s->id }}, $event.target.value)"
                                                    @disabled(!$currentIsSuperAdmin || $u->id === auth()->id())
                                                    class="bg-app-elevated border border-app-border text-ink-primary rounded px-2 py-0.5 text-[11px] disabled:opacity-60">
                                                <option value="aucun" @selected($roleSecteur === 'aucun')>Aucun</option>
                                                <option value="utilisateur" @selected($roleSecteur === 'utilisateur')>Utilisateur</option>
                                                <option value="chef" @selected($roleSecteur === 'chef')>Chef</option>
                                            </select>
                                        </label>
                                    @endforeach
                                </div>
                            </td>
                            <td class="px-4 py-2.5">
                                <div class="flex flex-wrap items-center gap-1">
                                    @foreach ($u->agents as $copie)
                                        <span class="inline-flex items-center gap-1 border border-app-border bg-app-card rounded-full pl-2.5 pr-1 py-0.5 text-[11px]" wire:key="ua-{{ $copie->id }}">
                                            {{ $copie->nom }}
                                            @if ($currentIsSuperAdmin)
                                                <button type="button" wire:click="retirerAgent({{ $copie->id }})" wire:confirm="Retirer « {{ $copie->nom }} » à {{ $u->name }} ? Son historique est conservé."
                                                        class="px-1.5 text-ink-muted hover:text-danger rounded-full" aria-label="Retirer">✕</button>
                                            @endif
                                        </span>
                                    @endforeach
                                    @if ($currentIsSuperAdmin && count($modeles))
                                        <select wire:change="ajouterAgent({{ $u->id }}, $event.target.value)"
                                                class="bg-app-elevated border border-app-border text-ink-primary rounded px-2 py-0.5 text-[11px]">
                                            <option value="">+ Ajouter…</option>
                                            @foreach ($modeles as $slug => $m)
                                                @unless ($u->agents->contains('modele_slug', $slug))
                                                    <option value="{{ $slug }}">{{ $m['nom'] }}</option>
                                                @endunless
                                            @endforeach
                                        </select>
                                    @elseif ($u->agents->isEmpty())
                                        <span class="text-xs text-ink-muted italic">—</span>
                                    @endif
                                </div>
                            </td>
                            <td class="px-4 py-2.5">
                                @if ($u->id === auth()->id())
                                    <span class="text-xs text-ink-muted italic">vous-même</span>
                                @elseif (!$currentIsSuperAdmin)
                                    <span class="text-xs text-ink-muted italic">—</span>
                                @else
                                    <div class="flex gap-1.5 flex-wrap">
                                        @if ($u->is_super_admin)
                                            <button wire:click="toggleSuperAdmin({{ $u->id }})"
                                                    wire:confirm="Retirer le statut super admin de {{ $u->name }} ?"
                                                    class="inline-flex items-center gap-1.5 px-3 py-1 rounded bg-danger-soft border border-danger-border text-danger text-[11px] font-medium hover:bg-danger-border transition">
                                                Retirer super admin
                                            </button>
                                        @else
                                            <button wire:click="toggleSuperAdmin({{ $u->id }})"
                                                    wire:confirm="Promouvoir {{ $u->name }} super admin ?"
                                                    class="inline-flex items-center gap-1.5 px-3 py-1 rounded bg-[#ede9fe] border border-[#c4b5fd] text-[#6d28d9] text-[11px] font-medium hover:bg-[#ddd6fe] transition">
                                                Promouvoir super admin
                                            </button>
                                        @endif

                                        @if (!$u->is_super_admin)
                                            @if ($u->is_admin)
                                                <button wire:click="toggleAdmin({{ $u->id }})"
                                                        wire:confirm="Retirer le statut admin de {{ $u->name }} ?"
                                                        class="inline-flex items-center gap-1.5 px-3 py-1 rounded bg-danger-soft border border-danger-border text-danger text-[11px] font-medium hover:bg-danger-border transition">
                                                    Retirer admin
                                                </button>
                                            @else
                                                <button wire:click="toggleAdmin({{ $u->id }})"
                                                        wire:confirm="Rendre {{ $u->name }} admin ?"
                                                        class="inline-flex items-center gap-1.5 px-3 py-1 rounded bg-ok-soft border border-ok-border text-ok text-[11px] font-medium hover:bg-ok-border transition">
                                                    Promouvoir admin
                                                </button>
                                            @endif
                                        @endif

                                        <button wire:click="togglePersonnelNavigant({{ $u->id }})"
                                                wire:confirm="{{ $u->is_personnel_navigant ? 'Retirer le statut Personnel Navigant de '.$u->name.' ?' : 'Marquer '.$u->name.' comme Personnel Navigant ?' }}"
                                                class="inline-flex items-center gap-1.5 px-3 py-1 rounded {{ $u->is_personnel_navigant ? 'bg-danger-soft border-danger-border text-danger hover:bg-danger-border' : 'bg-ok-soft border-ok-border text-ok hover:bg-ok-border' }} border text-[11px] font-medium transition">
                                            {{ $u->is_personnel_navigant ? 'Retirer PN' : 'Marquer PN' }}
                                        </button>
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-12 text-center text-ink-muted text-sm">
                                Aucun utilisateur trouvé.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($users->hasPages())
            <div class="px-4 py-3 border-t border-app-border-soft flex items-center justify-between text-xs text-ink-muted">
                <span>Affichage {{ $users->firstItem() }}–{{ $users->lastItem() }} de {{ $users->total() }}</span>
                <div class="flex gap-1.5">
                    @if ($users->onFirstPage())
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded border border-app-border text-ink-muted text-xs font-medium opacity-50 cursor-not-allowed">← Préc.</span>
                    @else
                        <button wire:click="previousPage"
                                class="inline-flex items-center gap-1.5 px-3 py-1 rounded border border-app-border text-ink-secondary bg-transparent text-xs font-medium hover:bg-app-card hover:text-ink-primary hover:border-neutral-border transition">← Préc.</button>
                    @endif
                    @if ($users->hasMorePages())
                        <button wire:click="nextPage"
                                class="inline-flex items-center gap-1.5 px-3 py-1 rounded border border-app-border text-ink-secondary bg-transparent text-xs font-medium hover:bg-app-card hover:text-ink-primary hover:border-neutral-border transition">Suiv. →</button>
                    @else
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded border border-app-border text-ink-muted text-xs font-medium opacity-50 cursor-not-allowed">Suiv. →</span>
                    @endif
                </div>
            </div>
        @endif
    </x-card>
    @endif
</div>
