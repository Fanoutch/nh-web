<?php

namespace App\Http\Controllers;

use App\Models\Flight;

class NonVolController extends Controller
{
    public function show(Flight $flight)
    {
        abort_unless($flight->is_non_vol, 404);
        $flight->load('machine', 'flaggedBy');
        return view('flights.non-vol', compact('flight'));
    }

    public function flag(Flight $flight)
    {
        abort_unless($flight->is_non_vol && !$flight->flagged_as_error, 403);
        $flight->update([
            'flagged_as_error' => true,
            'flagged_at' => now(),
            'flagged_by' => auth()->id(),
        ]);
        return redirect()->route('machines.show', ['hcId' => $flight->machine->hc_id, 'tab' => 'erreurs']);
    }
}
