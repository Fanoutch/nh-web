# Créer un agent du catalogue

Un agent du catalogue est développé par le super admin, dans le code, puis assigné à des utilisateurs depuis
**Admin > Utilisateurs > Catalogue d'agents**. L'utilisateur le lance sans pouvoir le modifier. Il n'existe pas de page
« Nouvel agent » côté admin : un agent = un dossier du code.

## 1. Où le créer

```
database/agents-catalogue/<nom-de-l-agent>/
    agent.php        définition de l'agent (obligatoire)
    consignes.txt    les consignes, en texte simple (recommandé : plus facile à relire et à corriger)
```

`<nom-de-l-agent>` : minuscules, chiffres et tirets seulement (ex. `dacssi-certificats`). C'est l'identifiant de l'agent :
ne plus le changer une fois l'agent assigné. Dès que le dossier est déployé, l'agent apparaît **tout seul** dans le catalogue.

## 2. Le fichier `agent.php` (à copier, puis à adapter)

```php
<?php
return [
    'nom' => 'Mon agent',                                   // obligatoire : nom vu par l'utilisateur
    'description' => 'Ce que fait l\'agent, en une phrase.',  // vue par l'utilisateur, affichée à l'accueil de l'agent
    'consignes' => trim((string) file_get_contents(__DIR__.'/consignes.txt')),
    'outils' => ['excel_decrire', 'excel_lire', 'word_lire', 'word_publipostage'],   // null = tous les outils
    'recette' => null,                                      // null = l'agent suit les consignes ; voir § 4
    'format_sortie' => 'Un fichier Word par personne',      // facultatif, indicatif
    'references' => ['modele.docx'],                        // fichiers attendus (voir § 3), [] s'il n'y en a pas
];
```

**Outils disponibles** (liste exacte, orthographe comprise) : `excel_decrire`, `excel_lire`, `excel_filtrer`, `excel_selectionner`,
`excel_colonne`, `excel_rapprocher`, `excel_creer`, `excel_extraire`, `excel_depuis_word`, `excel_regenerer`, `word_decrire`,
`word_lire`, `word_chercher`, `word_tableau`, `word_creer`, `word_regenerer`, `word_publipostage`. Donner **peu d'outils** rend l'agent
plus fiable : le modèle choisit mieux entre 4 outils qu'entre 17.

## 3. Les fichiers de référence (un formulaire, un modèle…) : jamais dans git

Ils sont listés dans `references` (par leur nom exact) et déposés **une fois** sur le serveur du site :

```
storage/app/private/catalogue-agents/<nom-de-l-agent>/references/<fichier>
```

Tant qu'un fichier manque, le catalogue affiche « Références manquantes » et refuse d'assigner ou de tester, en disant lequel déposer.
Pour changer un fichier de référence, remplacez-le sur le serveur puis cliquez sur « Mettre à jour les copies ».

## 4. Les consignes (le plus important)

Écrivez-les comme à un collègue : pour chaque colonne de l'Excel, **quel texte du Word elle remplace** ; ce qu'il ne faut jamais supprimer
(titres, logo, surlignages) ; que faire si une colonne manque. Les consignes de `dacssi-certificats` servent d'exemple.
L'utilisateur n'écrit rien : sa demande est toujours « Exécutez la recette validée sur les documents disponibles. », et ce sont les consignes qui font foi.

`recette` : laissez `null`. Une recette est une liste d'étapes figées (celles qu'un agent personnel enregistre) rejouée **sans LLM** ;
on la reprend d'un agent personnel déjà au point, ce qui est rarement nécessaire pour un agent du catalogue.

## 5. Tester avant d'assigner

1. Sans le site, avec le LLM du poste et de VRAIS fichiers copiés dans un dossier hors du dépôt :
   `assistant/.venv/bin/python scripts/essai-agent.py <dossier> "" --consignes database/agents-catalogue/<nom>/consignes.txt --forme`
   (`--forme` montre les outils et la forme des appels, jamais une valeur).
2. Sur le site : bouton **Tester** du catalogue (votre propre copie, vue comme un utilisateur).
3. Puis **Assigner** par e-mail.

## 6. Corriger un agent déjà assigné

Modifiez `consignes.txt` ou `agent.php`, commit, déploiement. Le catalogue affiche « N copies à mettre à jour » :
cliquez sur **Mettre à jour les copies** (les documents et l'historique des utilisateurs ne sont pas touchés).
Une correction des outils de l'assistant (code Python) n'a pas besoin de ce bouton.

## 7. Retirer un agent

Retirez-le d'abord aux personnes (croix sur leur pastille, ou dans le volet « Déjà assigné à »), puis seulement supprimez le dossier.
Un dossier supprimé alors que des copies existent laisse ces copies chez leurs utilisateurs, mais le catalogue ne peut plus ni les mettre à jour ni les assigner.
