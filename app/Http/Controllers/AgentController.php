<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use Illuminate\Contracts\View\View;

class AgentController extends Controller
{
    public function index(): View
    {
        return view('agents.index', ['agent' => null]);
    }

    public function show(Agent $agent): View
    {
        return view('agents.index', compact('agent'));
    }
}
