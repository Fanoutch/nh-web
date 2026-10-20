<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\AgentExecution;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AgentFichierController extends Controller
{
    /** Téléchargement d'un fichier PRODUIT par une exécution de cet agent : jamais un autre fichier du disque. */
    public function __invoke(Agent $agent, AgentExecution $execution, string $nom): StreamedResponse
    {
        abort_unless(
            $execution->agent_id === $agent->id
                && basename($nom) === $nom
                && in_array($nom, $execution->fichiers_produits ?? [], true),
            404,
        );
        $chemin = $execution->dossierSortie().'/'.$nom;
        abort_unless(Storage::disk('local')->exists($chemin), 404);

        return Storage::disk('local')->download($chemin, $nom);
    }
}
