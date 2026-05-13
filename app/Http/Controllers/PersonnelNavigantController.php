<?php

namespace App\Http\Controllers;

use App\Models\Flight;
use App\Models\Machine;

class PersonnelNavigantController extends Controller
{
    public function index()
    {
        $machines = Machine::orderBy('hc_id')->get(['id', 'hc_id']);
        return view('personnel-navigant.index', compact('machines'));
    }

    public function show(string $hcId)
    {
        $machine = Machine::where('hc_id', $hcId)->firstOrFail();
        $flights = $machine->flights()
            ->where('is_non_vol', false)
            ->orderByDesc('start_datetime')
            ->paginate(25);
        return view('personnel-navigant.show', compact('machine', 'flights'));
    }

    public function pannes(Flight $flight)
    {
        $flight->load('machine');
        return view('personnel-navigant.pannes', compact('flight'));
    }
}
