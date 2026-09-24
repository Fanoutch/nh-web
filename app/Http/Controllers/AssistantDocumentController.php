<?php

namespace App\Http\Controllers;

use App\Models\AssistantDocument;
use App\Models\Secteur;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;

class AssistantDocumentController extends Controller
{
    /**
     * Ouvre un document cité en source (accès vérifié sur la route : consulterDocument).
     * Le type servi dépend du format enregistré, jamais du contenu (un .txt contenant du HTML
     * reste du texte) : pdf et txt s'ouvrent dans le navigateur, le reste se télécharge.
     */
    public function consulter(Secteur $secteur, AssistantDocument $document): BinaryFileResponse
    {
        abort_unless($document->secteur()->is($secteur), 404);
        abort_unless($document->chemin !== '' && Storage::disk('local')->exists($document->chemin), 404);

        [$type, $disposition] = match ($document->format) {
            'pdf' => ['application/pdf', HeaderUtils::DISPOSITION_INLINE],
            'txt' => ['text/plain; charset=utf-8', HeaderUtils::DISPOSITION_INLINE],
            default => ['application/octet-stream', HeaderUtils::DISPOSITION_ATTACHMENT],
        };

        return response()->file($document->cheminAbsolu(), [
            'Content-Type' => $type,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => HeaderUtils::makeDisposition($disposition, $document->nom, Str::ascii($document->nom)),
        ]);
    }
}
