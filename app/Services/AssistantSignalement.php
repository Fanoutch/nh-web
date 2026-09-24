<?php

namespace App\Services;

use App\Models\AssistantDocument;
use App\Models\AssistantMessage;
use App\Models\User;

/** « Signaler » : trace l'erreur dans le journal d'audit (admins + super admins) pour le dépannage. */
class AssistantSignalement
{
    public function signaler(AssistantDocument|AssistantMessage $cible, User $par): void
    {
        if ($cible->signale_at !== null) {
            return;
        }

        if ($cible instanceof AssistantDocument) {
            $proprietes = [
                'secteur' => $cible->secteur->slug,
                'document' => $cible->nom,
                'message' => $cible->message,
                'detail' => $cible->detail,
            ];
            $libelle = "Signalement — document « {$cible->nom} » : {$cible->message}";
        } else {
            $conversation = $cible->conversation;
            $question = $conversation->messages()
                ->where('role', AssistantMessage::UTILISATEUR)->where('id', '<', $cible->id)
                ->latest('id')->value('contenu');
            $proprietes = [
                'secteur' => $conversation->secteur->slug,
                'conversation_id' => $conversation->id,
                'question' => $question,
                'statut' => $cible->statut,
                'message' => $cible->contenu,
                'detail' => $cible->detail,
            ];
            $libelle = "Signalement — réponse de l'assistant ({$cible->statut})";
        }

        activity('assistant')
            ->event('signalement')
            ->performedOn($cible)
            ->causedBy($par)
            ->withProperties($proprietes)
            ->log($libelle);

        $cible->forceFill(['signale_at' => now()])->save();
    }
}
