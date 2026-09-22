<?php

namespace App\Http\Controllers;

use App\Models\AssistantConversation;
use App\Models\Secteur;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

class AssistantArretController extends Controller
{
    /**
     * Pose le drapeau d'arrêt lu par AssistantChat::envoyer() pendant la lecture du flux.
     * La conversation doit appartenir à l'utilisateur connecté ET au secteur de l'URL :
     * sinon 403, sans rien révéler d'une conversation d'autrui.
     */
    public function __invoke(Request $request, Secteur $secteur): Response
    {
        $conversationId = (int) $request->validate([
            'conversation_id' => 'required|integer',
        ])['conversation_id'];

        $conversation = AssistantConversation::find($conversationId);
        abort_unless(
            $conversation !== null
                && $conversation->user_id === $request->user()->id
                && $conversation->secteur_id === $secteur->id,
            403,
        );

        Cache::put("assistant.arret.{$conversation->id}", true, 600);

        return response()->noContent();
    }
}
