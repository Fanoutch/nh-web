<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Un document déposé dans l'onglet « Documents » d'un secteur (base de l'assistant IA).
 */
class AssistantDocument extends Model
{
    public const EN_ATTENTE = 'en_attente';
    public const EN_COURS = 'en_cours';
    public const PRET = 'pret';
    public const ERREUR = 'erreur';

    protected $fillable = [
        'secteur_id', 'user_id', 'nom', 'chemin', 'taille', 'format',
        'statut', 'message', 'detail', 'nb_morceaux', 'signale_at',
    ];

    protected function casts(): array
    {
        return ['signale_at' => 'datetime'];
    }

    public function secteur(): BelongsTo
    {
        return $this->belongsTo(Secteur::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Chemin absolu du fichier, lu par le service Python sur la même machine. */
    public function cheminAbsolu(): string
    {
        return Storage::disk('local')->path($this->chemin);
    }
}
