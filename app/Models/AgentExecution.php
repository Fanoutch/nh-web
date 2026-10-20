<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Un lancement d'agent : conception ou exécution de la recette.
 */
class AgentExecution extends Model
{
    public const CONCEPTION = 'conception';

    public const EXECUTION = 'execution';

    public const EN_COURS = 'en_cours';

    public const TERMINE = 'termine';

    public const ARRET = 'arret';

    public const EN_ATTENTE = 'en_attente';

    public const ACCEPTEE = 'acceptee';

    public const REFUSEE = 'refusee';

    protected $fillable = [
        'agent_id', 'user_id', 'mode', 'etat', 'demande', 'fichiers_apportes', 'fichiers_produits',
        'etapes', 'message', 'detail', 'proposition', 'proposition_etat', 'arret_demande', 'debut_at', 'fin_at',
    ];

    protected function casts(): array
    {
        return [
            'fichiers_apportes' => 'array',
            'fichiers_produits' => 'array',
            'etapes' => 'array',
            'proposition' => 'array',
            'arret_demande' => 'boolean',
            'debut_at' => 'datetime',
            'fin_at' => 'datetime',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Dossier relatif des fichiers lus par l'agent (références + apportés), puis des fichiers produits. */
    public function dossierEntree(): string
    {
        return $this->agent->dossier().'/executions/'.$this->id.'/entree';
    }

    public function dossierSortie(): string
    {
        return $this->agent->dossier().'/executions/'.$this->id.'/sortie';
    }

    public function cheminAbsoluSortie(string $nom): string
    {
        return Storage::disk('local')->path($this->dossierSortie().'/'.$nom);
    }
}
