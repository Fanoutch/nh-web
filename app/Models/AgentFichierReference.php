<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Un fichier attaché à un agent (rôle : ce que représente le fichier pour la recette).
 */
class AgentFichierReference extends Model
{
    protected $table = 'agent_fichiers_reference';

    protected $fillable = ['agent_id', 'nom', 'chemin', 'taille', 'role'];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function cheminAbsolu(): string
    {
        return Storage::disk('local')->path($this->chemin);
    }
}
