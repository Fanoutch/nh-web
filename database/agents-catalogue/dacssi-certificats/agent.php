<?php

/*
 * Agent du catalogue : un certificat Word par personne d'un Excel, d'après le formulaire DACSSI.
 * Le formulaire réel (202610xx_DACSSI.docx) n'est PAS dans git : il se dépose sur le serveur dans
 * storage/app/private/catalogue-agents/dacssi-certificats/references/ (voir docs/agents-admin-serveur.md).
 */
return [
    'nom' => 'DACSSI — certificats',
    'description' => 'Génère un certificat Word par personne à partir de votre fichier Excel.',
    'consignes' => "Vous remplissez le formulaire de référence 202610xx_DACSSI.docx pour CHAQUE ligne du classeur Excel déposé par l'utilisateur : un document Word par personne, avec word_publipostage.\n"
        ."Correspondances (le classeur a en-tête sur la première ligne) : la colonne « NOM PRENOM » remplace ce qui suit « Nom et Prénoms : » ; "
        ."la colonne « DATE DE VALIDITE » remplace « JJ/MM/AAAA » ; la colonne « GRADE » remplace ce qui suit « Grade ou emploi : » ; "
        ."la colonne « REFERENCE » remplace « xxxxx » dans « référence DACSSI xxxxx ».\n"
        .'Ne supprimez aucun titre ni aucun texte du formulaire, gardez toute la mise en forme (logo, surlignages). '
        .'Si une colonne attendue est absente du classeur, arrêtez-vous et dites laquelle manque, sans produire de fichier.',
    'outils' => ['excel_decrire', 'excel_lire', 'word_decrire', 'word_lire', 'word_publipostage'],
    'recette' => null,
    'format_sortie' => 'Un fichier Word par personne',
    'references' => ['202610xx_DACSSI.docx'],
];
