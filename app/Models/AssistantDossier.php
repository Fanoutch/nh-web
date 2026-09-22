<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Un dossier de l'arborescence documentaire d'un secteur (base de l'assistant IA).
 */
class AssistantDossier extends Model
{
    protected $fillable = ['secteur_id', 'parent_id', 'nom'];

    public function secteur(): BelongsTo
    {
        return $this->belongsTo(Secteur::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function enfants(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(AssistantDocument::class, 'dossier_id');
    }

    /** Chemin affichable depuis la racine (ex. « Procédures › Vols »), vide à la racine. */
    public function chemin(): string
    {
        $noms = [];
        for ($d = $this; $d !== null; $d = $d->parent) {
            array_unshift($noms, $d->nom);
        }

        return implode(' › ', $noms);
    }

    /** Profondeur dans l'arborescence, racine = 1. */
    public function profondeur(): int
    {
        return substr_count($this->chemin(), '›') + 1;
    }

    /** Tous les sous-dossiers, à tous les niveaux (lui exclu). */
    public function descendants(): Collection
    {
        $this->loadMissing('enfants');

        return $this->enfants->flatMap(fn (self $e) => collect([$e])->merge($e->descendants()));
    }
}
