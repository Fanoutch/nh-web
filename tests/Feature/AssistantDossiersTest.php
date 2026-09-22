<?php

use App\Models\AssistantDossier;

it('construit le chemin affichable et la profondeur', function () {
    $secteur = secteurBmn();
    $procedures = AssistantDossier::create(['secteur_id' => $secteur->id, 'parent_id' => null, 'nom' => 'Procédures']);
    $vols = AssistantDossier::create(['secteur_id' => $secteur->id, 'parent_id' => $procedures->id, 'nom' => 'Vols']);

    expect($procedures->chemin())->toBe('Procédures')
        ->and($vols->chemin())->toBe('Procédures › Vols')
        ->and($vols->profondeur())->toBe(2)
        ->and($procedures->descendants()->pluck('id')->all())->toBe([$vols->id]);
});
