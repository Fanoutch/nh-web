<?php

/*
 * Agent du catalogue : un certificat Word par personne d'un Excel, d'après le formulaire DACSSI.
 * Le formulaire réel (202610xx_DACSSI.docx) n'est PAS dans git : il se dépose sur le serveur dans
 * storage/app/private/catalogue-agents/dacssi-certificats/references/ (voir docs/agents-admin-serveur.md).
 * Les consignes sont dans consignes.txt (texte simple) : le script scripts/essai-agent.py peut les rejouer sans php.
 */
return [
    'nom' => 'DACSSI — certificats',
    'description' => 'Génère un certificat Word par personne à partir de votre fichier Excel.',
    'consignes' => trim((string) file_get_contents(__DIR__.'/consignes.txt')),
    'outils' => ['excel_decrire', 'excel_lire', 'word_decrire', 'word_lire', 'word_publipostage'],
    'recette' => null,
    'format_sortie' => 'Un fichier Word par personne',
    'references' => ['202610xx_DACSSI.docx'],
];
