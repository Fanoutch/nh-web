# Agents — objectif et principes

Document de cadrage. Il dit **ce qu'on veut construire et pourquoi**, pas comment c'est codé.
La conception technique viendra dans une spec séparée.

## Le problème

Au bureau, beaucoup de gens passent leur journée dans Excel. Les tâches se ressemblent — relever
des quantités, croiser deux tableaux, produire un récapitulatif — mais **les fichiers, eux, ne se
ressemblent jamais** : chaque service a les siens, avec ses colonnes et ses habitudes.

Coder un traitement par cas ne tient pas : il y en aurait des dizaines, et chaque demande
attendrait qu'on la développe.

## Ce qu'on veut

Un **agent Excel** dans cAIman, à qui l'utilisateur donne ses fichiers **dans le chat** et explique
ce qu'il veut, en français. L'agent dispose d'outils pour travailler les classeurs et s'en sert
pour répondre à la demande.

L'utilisateur doit pouvoir :

- **déposer un ou plusieurs classeurs** dans la conversation — plusieurs est indispensable, beaucoup
  de tâches consistent à croiser deux fichiers (un stock et un bon de commande, par exemple) ;
- **demander en langage courant** ce qu'il veut en faire ;
- **récupérer un fichier produit**, téléchargeable, qu'il peut vérifier avant de s'en servir.

## La règle qui ne se discute pas

**L'agent ne modifie jamais le classeur d'origine. Il en produit un nouveau.**

Le fichier déposé reste intact. Toute modification demandée donne lieu à un fichier **régénéré**,
que l'utilisateur ouvre, vérifie, et garde ou jette. Une erreur de l'agent coûte alors un fichier à
supprimer, jamais des données de travail perdues.

## Ce que l'agent doit savoir faire

Par ordre de mise en service, du plus sûr au plus délicat :

1. **Lire** — ouvrir un classeur, décrire ce qu'il contient (feuilles, colonnes, nombre de lignes),
   extraire un tableau, retrouver une valeur.
2. **Analyser** — filtrer, trier, compter, totaliser, comparer deux fichiers, sortir des
   statistiques sur les données.
3. **Produire** — créer un classeur neuf : un récapitulatif, un export, le résultat d'un croisement.
4. **Régénérer** — repartir d'un classeur existant et en sortir une version modifiée, sans toucher
   à l'original.

Les trois premières familles ne présentent aucun risque : rien n'est écrasé. La quatrième demande
un aperçu avant téléchargement, pour que l'utilisateur voie ce qui a changé.

## Le cas qui sert de référence : stock → commande

Un service tient un fichier **stock** : pour chaque matériel, la quantité en rayon et la quantité
qu'il faudrait avoir. Il doit en tirer un **bon de commande** ne contenant **que ce qui manque**,
avec les quantités pour compléter.

Ce cas a une particularité instructive : **le rapprochement se fait sur le nom du matériel, et les
noms ne sont pas écrits pareil d'un fichier à l'autre**. C'est exactement l'endroit où le modèle de
langage apporte quelque chose qu'un calcul ne sait pas faire.

D'où le partage qu'on applique partout :

> **Le calcul reste du code. Le modèle ne tranche que là où il y a du jugement.**

« Quantité manquante = besoin − stock » est du calcul : c'est du code, juste à tous les coups.
« *JOINT TORIQUE 12X2* est-il le même article que *Joint torique 12x2 mm* ? » est du jugement :
c'est pour le modèle.

## Ce qui ne change pas

**La dispo BMN reste telle quelle.** Son remplissage est déterministe, testé, prévisible, et le
document part à la hiérarchie. Elle ne sera pas réécrite en agent. Elle pourra rejoindre le
catalogue par la surface — y apparaître, avoir un historique, être relançable — et rien de plus.

## Ce qui est vérifié, ce qui ne l'est pas

**Vérifié ici (2026-09-29)** : le modèle de développement (Mistral Nemo) **décide correctement**
d'appeler un outil, avec les bons arguments. Mais le serveur renvoie cette décision **en texte brut**
(`[TOOL_CALLS][{...}]`) au lieu du champ structuré prévu par la norme. C'est décodable, à condition
de l'écrire.

**Pas encore vérifié** : le modèle du bureau (« Générique v2 ») se comporte-t-il pareil ? Le script
`scripts/test-outils-llm.py` répond à cette question en trente secondes, à lancer là-bas. Sa réponse
oriente toute la conception :

- format standard → l'agent choisit ses outils librement ;
- format texte → pareil, avec un décodage de notre côté ;
- aucun appel → les agents ne peuvent pas choisir leurs outils, il faudra une autre approche.

## Questions encore ouvertes

1. **Qui crée les agents ?** Décidé : nous les codons, ils sont mis à disposition par secteur. Reste
   à savoir si un chef peut en activer ou en masquer pour son secteur.
2. **Où vont les fichiers produits ?** Téléchargement direct depuis le chat, ou rangement dans
   l'espace Documents du secteur ?
3. **Combien de temps garde-t-on les fichiers déposés ?** Les pièces jointes de l'assistant vivent
   avec la conversation ; même règle ici ?
4. **Quelle taille maximale ?** Les pièces jointes du chat sont à 20 Mo. Un classeur de plusieurs
   dizaines de milliers de lignes change la nature du problème.

## Hors sujet pour l'instant

- Les agents qui agissent seuls, sans demande explicite (tâches planifiées).
- Les agents qui écrivent dans un système extérieur.
- La fabrication d'agents par les utilisateurs eux-mêmes, en langage naturel.
