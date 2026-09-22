<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Une pièce jointe déposée dans une conversation avec l'assistant IA.
 */
class AssistantPieceJointe extends Model
{
    public const EN_ATTENTE = 'en_attente';

    public const EN_COURS = 'en_cours';

    public const PRET = 'pret';

    public const ERREUR = 'erreur';

    // Nom explicite : la pluralisation par défaut d'Eloquent donnerait "assistant_piece_jointes"
    // (seul le dernier mot est pluralisé), alors que la migration crée "assistant_pieces_jointes".
    protected $table = 'assistant_pieces_jointes';

    protected $fillable = [
        'conversation_id', 'nom', 'chemin', 'taille', 'format',
        'statut', 'message', 'detail', 'nb_morceaux',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AssistantConversation::class, 'conversation_id');
    }

    /** Chemin absolu du fichier, lu par le service Python sur la même machine. */
    public function cheminAbsolu(): string
    {
        return Storage::disk('local')->path($this->chemin);
    }
}
