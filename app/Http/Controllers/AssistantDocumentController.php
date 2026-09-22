<?php

namespace App\Http\Controllers;

use App\Models\AssistantDocument;
use App\Models\Secteur;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AssistantDocumentController extends Controller
{
    /** Ouvre un document cité en source (accès vérifié sur la route : consulterDocument). */
    public function consulter(Secteur $secteur, AssistantDocument $document): BinaryFileResponse
    {
        abort_unless($document->secteur()->is($secteur), 404);
        abort_unless($document->chemin !== '' && Storage::disk('local')->exists($document->chemin), 404);

        return response()->file($document->cheminAbsolu(), [
            'Content-Disposition' => 'inline; filename="'.addslashes(Str::ascii($document->nom)).'"',
        ]);
    }
}
