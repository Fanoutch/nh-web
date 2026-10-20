<?php

namespace App\Console\Commands;

use App\Services\EntretienAgents;
use Illuminate\Console\Command;

class EntretienAgentsCommand extends Command
{
    protected $signature = 'agents:entretien';

    protected $description = 'Met en veille les agents non lancés depuis 3 mois et supprime les brouillons vides.';

    public function handle(EntretienAgents $entretien): int
    {
        $this->info($entretien->mettreEnVeille().' agent(s) mis en veille, '.$entretien->nettoyerBrouillons().' brouillon(s) vide(s) supprimé(s).');

        return self::SUCCESS;
    }
}
