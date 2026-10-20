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
    public const REFERENCE = 'reference';

    public const APPORT = 'apport';

    protected $table = 'agent_fichiers_reference';

    protected $fillable = ['agent_id', 'type', 'nom', 'chemin', 'taille', 'role', 'lecture'];

    protected function casts(): array
    {
        return ['lecture' => 'array'];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function cheminAbsolu(): string
    {
        return Storage::disk('local')->path($this->chemin);
    }
}
