<?php

use App\Services\DomaineAssistant;
use App\Services\SourcesAssistant;
use Illuminate\Support\Facades\Storage;

/**
 * DomaineAssistant reconnaît les dossiers nommés dans la question, pour PRIORISER leurs documents
 * dans la recherche (jamais pour filtrer : la recherche porte toujours sur tout le secteur).
 * SourcesAssistant::surligner prépare l'extrait affiché dans l'encart « Passage utilisé ».
 * Les helpers dossier() et document() viennent de AssistantExplorateurTest.php / AssistantDossiersTest.php
 * (chargés par Pest dans le même processus : toute fonction globale déclarée dans un fichier de test est
 * disponible dans les autres).
 */
beforeEach(function () {
    config(['assistant.jeton' => 'secret']);
    Storage::fake('local');
    $this->secteur = secteurBmn();
});

it('reconnaît un dossier nommé dans la question', function () {
    $secteur = $this->secteur;
    $entretien = dossier($secteur, 'Entretien');
    $sous = dossier($secteur, 'Moteurs', $entretien);
    $a = document($secteur, dossier: $entretien, statut: 'pret');
    $b = document($secteur, dossier: $sous, statut: 'pret');
    document($secteur, statut: 'pret');                                  // racine : pas prioritaire

    $ids = app(DomaineAssistant::class)
        ->documentsPrioritaires($secteur, "Dans les procédures d'entretien, quelle tolérance ?");

    expect($ids)->toEqualCanonicalizing([$a->id, $b->id]);
});

it('ne renvoie rien quand aucun dossier n’est nommé', function () {
    $secteur = $this->secteur;
    dossier($secteur, 'Entretien');

    expect(app(DomaineAssistant::class)->documentsPrioritaires($secteur, 'Qui ordonne un vol ?'))->toBe([]);
});

it('ignore les documents qui ne sont pas encore prêts', function () {
    $secteur = $this->secteur;
    $entretien = dossier($secteur, 'Entretien');
    document($secteur, dossier: $entretien, statut: 'en_cours');

    expect(app(DomaineAssistant::class)->documentsPrioritaires($secteur, 'Question sur l’entretien ?'))->toBe([]);
});

it('ne renvoie rien au-delà de 200 documents (une liste aussi large ne cible plus rien)', function () {
    $secteur = $this->secteur;
    $entretien = dossier($secteur, 'Entretien');
    for ($i = 0; $i < 201; $i++) {
        document($secteur, dossier: $entretien, nom: "Doc{$i}.pdf", statut: 'pret');
    }

    expect(app(DomaineAssistant::class)->documentsPrioritaires($secteur, 'Question sur l’entretien ?'))->toBe([]);
});

it('surligne les mots de la question dans l’extrait', function () {
    $html = app(SourcesAssistant::class)->surligner("L'usure des plaquettes est tolérée", 'usure des plaquettes ?');

    expect($html)->toContain('<mark>usure</mark>')->toContain('<mark>plaquettes</mark>')
        ->and($html)->not->toContain('<mark>des</mark>');     // mots de 3 caractères ou moins ignorés
});

it('échappe le HTML de l’extrait avant de surligner', function () {
    $html = app(SourcesAssistant::class)->surligner('<script>usure</script>', 'usure ?');

    expect($html)->toBe('&lt;script&gt;<mark>usure</mark>&lt;/script&gt;');
});

it('ignore la casse et les accents pour surligner', function () {
    $html = app(SourcesAssistant::class)->surligner('La TOLÉRANCE applicable', 'tolerance ?');

    expect($html)->toContain('<mark>TOLÉRANCE</mark>');
});
