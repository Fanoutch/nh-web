<?php

namespace App\Http\Controllers;

use App\Models\Machine;
use Illuminate\Http\Request;

class MachineController extends Controller
{
    public function index()
    {
        $machines = Machine::withCount([
            'flights as vols_count' => fn ($q) => $q->where('is_non_vol', false),
            'flights as non_vols_count' => fn ($q) => $q->where('is_non_vol', true)->where('flagged_as_error', false),
            'flights as erreurs_count' => fn ($q) => $q->where('flagged_as_error', true),
            'recurrentFailures as active_count' => fn ($q) => $q->where('status', 'active'),
        ])
        ->with([
            'recurrentFailures' => fn ($q) => $q->where('status', 'active')->orderByDesc('score'),
            'latestFlight' => fn ($q) => $q->with([
                'technicalEvents' => fn ($q2) => $q2->where('status', 'conservee')->orderByDesc('nombre_occurrences'),
            ]),
        ])
        ->orderBy('hc_id')
        ->get();

        return view('machines.index', compact('machines'));
    }

    public function show(string $hcId, Request $request)
    {
        $machine = Machine::where('hc_id', $hcId)->firstOrFail();
        $tab = $request->get('tab', 'vols');

        $counts = [
            'vols'     => $machine->flights()->where('is_non_vol', false)->count(),
            'non-vols' => $machine->flights()->where('is_non_vol', true)->where('flagged_as_error', false)->count(),
            'erreurs'  => $machine->flights()->where('flagged_as_error', true)->count(),
        ];
        $totalCount = array_sum($counts);

        $query = $machine->flights()->orderByDesc('start_datetime');
        $query->when($tab === 'vols', fn ($q) => $q->where('is_non_vol', false));
        $query->when($tab === 'non-vols', fn ($q) => $q->where('is_non_vol', true)->where('flagged_as_error', false));
        $query->when($tab === 'erreurs', fn ($q) => $q->where('flagged_as_error', true));

        $flights = $query->withCount([
            'technicalEvents as conservees_count' => fn ($q) => $q->where('status', 'conservee'),
        ])->paginate(25);

        return view('machines.show', compact('machine', 'tab', 'counts', 'totalCount', 'flights'));
    }
}
