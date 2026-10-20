<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

class AgentArretController extends Controller
{
    /** Pose le drapeau d'arrêt lu par le lanceur pendant la lecture du flux (droits : AgentPolicy, sur la route). */
    public function __invoke(Agent $agent): Response
    {
        Cache::put("agent.arret.{$agent->id}", true, 600);

        return response()->noContent();
    }
}
