<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rappel planifié : l'agent remonte dans « À faire » à l'échéance (pas d'exécution automatique).
 */
class AgentRappel extends Model
{
    public const PERIODICITES = ['hebdomadaire', 'mensuel', 'trimestriel'];

    protected $table = 'agent_rappels';

    /** Prochaine échéance à partir d'aujourd'hui, ou null pour une périodicité inconnue. */
    public static function echeanceApres(string $periodicite): ?\Illuminate\Support\Carbon
    {
        return match ($periodicite) {
            'hebdomadaire' => now()->addWeek(),
            'mensuel' => now()->addMonth(),
            'trimestriel' => now()->addMonths(3),
            default => null,
        };
    }

    protected $fillable = ['agent_id', 'periodicite', 'prochaine_echeance', 'notifie_at'];

    protected function casts(): array
    {
        return ['prochaine_echeance' => 'date', 'notifie_at' => 'datetime'];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
