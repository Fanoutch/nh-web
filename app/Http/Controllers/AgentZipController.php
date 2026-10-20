<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\AgentExecution;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

class AgentZipController extends Controller
{
    /** Tous les fichiers PRODUITS par un tour de cet agent, dans une archive (droits : AgentPolicy, sur la route). */
    public function __invoke(Agent $agent, AgentExecution $execution): BinaryFileResponse
    {
        abort_unless($execution->agent_id === $agent->id, 404);
        $disque = Storage::disk('local');
        $fichiers = array_values(array_filter($execution->fichiers_produits ?? [],
            fn ($nom) => basename($nom) === $nom && $disque->exists($execution->dossierSortie().'/'.$nom)));
        abort_if($fichiers === [], 404);

        $chemin = tempnam(sys_get_temp_dir(), 'agent-zip-');
        $zip = new ZipArchive;
        abort_unless($zip->open($chemin, ZipArchive::OVERWRITE) === true, 500);
        foreach ($fichiers as $nom) {
            $zip->addFile($disque->path($execution->dossierSortie().'/'.$nom), $nom);
        }
        $zip->close();

        return response()->download($chemin, 'agent-'.$agent->id.'-tour-'.$execution->id.'.zip')->deleteFileAfterSend(true);
    }
}
