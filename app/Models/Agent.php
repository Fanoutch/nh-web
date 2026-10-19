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

    /** Les huit outils Excel du service (assistant/outils/excel.py). */
    public const OUTILS = [
        'excel_decrire', 'excel_lire', 'excel_filtrer', 'excel_selectionner',
        'excel_colonne', 'excel_rapprocher', 'excel_creer', 'excel_regenerer',
    ];

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

    public function fichiersReference(): HasMany
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

    public function estEnVeille(): bool
    {
        return $this->etat === self::EN_VEILLE;
    }

    /** Outils réellement autorisés : ceux de la recette, limités aux outils qui existent. */
    public function outilsAutorises(): array
    {
        $choisis = $this->outils ?: self::OUTILS;

        return array_values(array_intersect(self::OUTILS, $choisis));
    }

    /** Dossier privé de l'agent, relatif au disque « local ». */
    public function dossier(): string
    {
        return 'agents/'.$this->id;
    }
}
