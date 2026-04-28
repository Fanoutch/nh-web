<?php

namespace App\Http\Controllers;

use App\Models\Flight;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FlightController extends Controller
{
    public function show(Flight $flight)
    {
        // Les non-vols non reclassifies vont sur la page non-vol
        if ($flight->is_non_vol && !$flight->flagged_as_error) {
            return redirect()->route('flights.non-vol', $flight);
        }

        $flight->load('machine');
        $counts = [
            'conservees' => $flight->technicalEvents()->where('status', 'conservee')->count(),
            'isolees'    => $flight->technicalEvents()->where('status', 'isolee')->count(),
        ];
        return view('flights.show', compact('flight', 'counts'));
    }

    public function pannesConservees(Flight $flight)
    {
        return view('flights.pannes-conservees', compact('flight'));
    }

    public function pannesIsolees(Flight $flight)
    {
        $pannes = $flight->technicalEvents()->where('status', 'isolee')->get();
        return view('flights.pannes-isolees', compact('flight', 'pannes'));
    }

    public function downloadXml(Flight $flight): BinaryFileResponse
    {
        abort_unless($flight->xml_path && file_exists($flight->xml_path), 404);
        return response()->download(
            $flight->xml_path,
            "{$flight->machine->hc_id}_{$flight->num}.xml"
        );
    }
}
