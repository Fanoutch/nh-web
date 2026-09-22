<?php

namespace App\Http\Controllers;

use App\Models\AssistantPieceJointe;
use App\Models\Secteur;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;

class AssistantPieceJointeController extends Controller
{
    /**
     * Ouvre une pièce jointe déposée dans une conversation (compte rendu de panne, etc.) : privée à
     * cette conversation, réservée à son propriétaire (même un admin reçoit 403 — contrairement aux
     * documents du secteur, aucune règle de SecteurPolicy ne peut trancher ici, la propriété se
     * vérifie donc directement sur la conversation, dans ce contrôleur).
     * Mêmes en-têtes que AssistantDocumentController::consulter (type selon le format enregistré,
     * jamais selon le contenu).
     */
    public function consulter(Secteur $secteur, AssistantPieceJointe $piece): BinaryFileResponse
    {
        abort_unless($piece->conversation->secteur_id === $secteur->id, 404);
        abort_unless($piece->conversation->user_id === auth()->id(), 403);
        abort_unless($piece->chemin !== '' && Storage::disk('local')->exists($piece->chemin), 404);

        [$type, $disposition] = match ($piece->format) {
            'pdf' => ['application/pdf', HeaderUtils::DISPOSITION_INLINE],
            'txt' => ['text/plain; charset=utf-8', HeaderUtils::DISPOSITION_INLINE],
            default => ['application/octet-stream', HeaderUtils::DISPOSITION_ATTACHMENT],
        };

        return response()->file($piece->cheminAbsolu(), [
            'Content-Type' => $type,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => HeaderUtils::makeDisposition($disposition, $piece->nom, Str::ascii($piece->nom)),
        ]);
    }
}
