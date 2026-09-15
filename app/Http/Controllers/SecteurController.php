<?php

namespace App\Http\Controllers;

use App\Models\Secteur;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Onglet « Secteurs » : choix du secteur puis ses onglets. Droits vérifiés sur les routes (SecteurPolicy).
 */
class SecteurController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();

        $cartes = $user->secteursAccessibles()->map(fn (Secteur $secteur) => [
            'secteur' => $secteur,
            'role' => match ($user->roleDans($secteur)) {
                Secteur::ROLE_CHEF => 'Chef',
                Secteur::ROLE_UTILISATEUR => 'Utilisateur',
                default => 'Admin',
            },
        ]);

        return view('secteurs.index', compact('cartes'));
    }

    public function show(Secteur $secteur): RedirectResponse
    {
        return redirect()->route('secteurs.disponibilites', $secteur);
    }

    public function disponibilites(Secteur $secteur): View
    {
        return view('secteurs.disponibilites', compact('secteur'));
    }

    public function assistant(Secteur $secteur): View
    {
        return view('secteurs.assistant', compact('secteur'));
    }
}
