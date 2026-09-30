# Banc de mesure de la recherche de l'assistant

## À quoi ça sert

Le banc pose une serie de questions a l'assistant sur des documents d'essai (PDF, Word, texte) et
mesure deux choses :

- la **recherche** : le bon passage est-il retrouve parmi les premiers resultats ? (colonne « Trouvé ») ;
- les **réponses** : la réponse donnee est-elle juste, fidele aux documents, et l'assistant refuse-t-il
  bien quand l'information n'existe pas ? (colonnes « Justes », « Fidèles », « Refus corrects »).

Il ne touche jamais aux vrais documents du site ni à sa base : il travaille dans un espace temporaire.
Au bureau, il sert à **vérifier** que les réglages retenus donnent les mêmes résultats qu'ici.
Ne modifiez jamais le code sur place.

## Ce qu'il faut avoir

- Python uniquement (pas de php ni de node). On travaille dans le dossier `assistant/`.
- Le fichier `llm.env` (à la racine de nh-web) rempli : adresse du LLM et du modele d'embedding.
- Facultatif : vos vrais PDF copiés dans `banc/corpus/pdf/` sous le nom `reel-<nom>.pdf`, pour que
  leurs questions soient posées aussi. Ces fichiers ne sont jamais enregistrés dans git.

## Lancer la vérification

```
cd assistant
.venv/bin/python -m banc.banc --reglages retenu --formats pdf
.venv/bin/python -m banc.banc --reglages retenu --formats pdf --réponses
```

La première commande teste la recherche seule (rapide). La seconde fait aussi répondre et juger
chaque question (plus longue, elle utilise le LLM). Pour comparer avec l'ancien comportement,
ajoutez-le : `--reglages actuel,retenu`.

## Lire le résultat

Chaque lancement écrit un dossier `banc/resultats/<date>_<heure>/` avec `rapport.md` (les chiffres,
à lire en premier) et `detail.csv` (question par question). Dans `rapport.md` :

- **Trouvé** : pourcentage de questions dont le bon passage remonte. Plus c'est haut, mieux c'est.
- **Justes** : pourcentage de réponses correctes.
- **Fidèles** : la réponse ne contient rien d'inventé (doit rester proche de 100 %).
- **Refus corrects** : pour les questions dont la réponse n'est pas dans les documents,
  l'assistant dit bien qu'il ne sait pas (doit rester 100 %).

## Chiffres de référence (mesurés ici, réglages `retenu`)

| | pdf | docx | txt |
|---|---|---|---|
| Recherche, « Trouvé » | 96 % | 94 % | 97 % |
| Réponses, « Justes » | 92 % | 90 % | 97 % |

Fidèles et refus corrects : 100 % dans les trois formats. (Avec l'ancien comportement, les réponses
justes en pdf n'étaient que de 63 %.) Quelques points d'écart d'un lancement à l'autre sont normaux :
le corpus est petit.

## Si les chiffres diffèrent

**Si les chiffres de recherche s'écartent de plus de 10 points de ceux d'ici, envoyer `rapport.md`**
(il ne contient que des chiffres, aucun texte de document). Ne changez aucun reglage de votre côté.
