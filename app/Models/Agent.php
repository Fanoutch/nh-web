<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Un agent Excel : personnel (visible de son seul propriétaire, y compris pour les admins).
 */
class Agent extends Model
{
    public const BROUILLON = 'brouillon';   // en conception, pas encore enregistré

    public const ACTIF = 'actif';

    public const EN_VEILLE = 'en_veille';

    /** Les outils du service : Excel (assistant/outils/excel.py) et lecture de Word (assistant/outils/word.py). */
    public const OUTILS = [
        'excel_decrire', 'excel_lire', 'excel_filtrer', 'excel_selectionner',
        'excel_colonne', 'excel_rapprocher', 'excel_creer', 'excel_regenerer',
        'word_decrire', 'word_lire', 'word_chercher', 'word_tableau', 'word_creer',
    ];

    /** Outils de gestion : toujours accordés, l'agent ne fait que PROPOSER (l'utilisateur confirme, le site enregistre). */
    public const GESTION = ['agent_proposer', 'agent_historique'];

    protected $fillable = [
        'user_id', 'nom', 'description', 'consignes', 'recette', 'outils', 'format_sortie', 'etat',
        'dernier_lancement_at', 'veille_prevenue_at', 'en_veille_at',
    ];

    protected function casts(): array
    {
        return [
            'recette' => 'array',
            'outils' => 'array',
            'dernier_lancement_at' => 'datetime',
            'veille_prevenue_at' => 'datetime',
            'en_veille_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Fichiers de référence permanents de l'agent. */
    public function fichiersReference(): HasMany
    {
        return $this->hasMany(AgentFichierReference::class)->where('type', AgentFichierReference::REFERENCE);
    }

    /** Documents déposés, en attente du prochain lancement. */
    public function apports(): HasMany
    {
        return $this->hasMany(AgentFichierReference::class)->where('type', AgentFichierReference::APPORT);
    }

    /** Tous les fichiers de l'agent (références et apports) : un nom ne peut pas servir deux fois. */
    public function tousFichiers(): HasMany
    {
        return $this->hasMany(AgentFichierReference::class);
    }

    public function executions(): HasMany
    {
        return $this->hasMany(AgentExecution::class)->latest('id');
    }

    public function rappel(): HasOne
    {
        return $this->hasOne(AgentRappel::class);
    }

    /** Dans « À faire » : le rappel est arrivé à échéance (la veille proche s'ajoutera à l'étape 6). */
    public function aFaire(): bool
    {
        return $this->etat === self::ACTIF
            && $this->rappel !== null
            && $this->rappel->prochaine_echeance->lte(now()->startOfDay());
    }

    public function estEnVeille(): bool
    {
        return $this->etat === self::EN_VEILLE;
    }

    /** Outils réellement autorisés : ceux de la recette, limités aux outils qui existent. */
    public function outilsAutorises(): array
    {
        $choisis = $this->outils ?: self::OUTILS;

        return array_merge(array_values(array_intersect(self::OUTILS, $choisis)), self::GESTION);
    }

    /** Recette figée, en texte, à rejouer : une ligne par étape validée. */
    public function recetteEnTexte(): string
    {
        $lignes = [];
        foreach (($this->recette ?? []) as $i => $etape) {
            $lignes[] = ($i + 1).'. '.($etape['outil'] ?? '?').' '.json_encode($etape['arguments'] ?? [], JSON_UNESCAPED_UNICODE)
                .(($etape['resume'] ?? '') !== '' ? ' — '.$etape['resume'] : '');
        }

        return implode("\n", $lignes);
    }

    /** Dossier privé de l'agent, relatif au disque « local ». */
    public function dossier(): string
    {
        return 'agents/'.$this->id;
    }
}
