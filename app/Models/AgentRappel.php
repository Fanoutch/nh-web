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
