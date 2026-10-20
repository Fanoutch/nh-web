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
    'taille_max_mo' => 20,           // par fichier
    'alerte_mo' => 500,              // par agent : prévenir, sans rien supprimer
    'veille_mois' => 3,              // sans lancement : l'agent passe « en veille »
    'prevenir_jours_avant' => 7,     // notification à 2 mois et 3 semaines
    'timeout' => 330,                // une exécution (la boucle s'arrête à 5 minutes), en secondes
];
