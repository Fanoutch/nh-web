<?php

/*
|--------------------------------------------------------------------------
| Agents Excel — limites et durées
|--------------------------------------------------------------------------
|
| Les verrous de taille sont aussi codés côté service Python (assistant/outils) ;
| ici ils servent à refuser un dépôt avant même d'appeler le service.
|
*/

return [
    'extensions' => ['xlsx', 'xlsm', 'csv', 'docx'],
    'anciens_formats' => ['doc' => 'docx', 'xls' => 'xlsx'],   // convertis au dépôt par le service (LibreOffice), mise en forme comprise
    'taille_max_mo' => 20,           // par fichier
    'alerte_mo' => 500,              // par agent : prévenir, sans rien supprimer
    'veille_mois' => 3,              // sans lancement : l'agent passe « en veille »
    'prevenir_jours_avant' => 7,     // notification à 2 mois et 3 semaines
    // Catalogue du super admin : définitions versionnées (code) ; fichiers de référence réels hors git, déposés sur le serveur.
    'catalogue_definitions' => database_path('agents-catalogue'),
    'catalogue_references' => 'catalogue-agents',                 // disque « local » : <slug>/references/
    'timeout' => (int) env('AGENTS_TIMEOUT', 330),                // une exécution (la boucle s'arrête à 5 minutes), en secondes
];
