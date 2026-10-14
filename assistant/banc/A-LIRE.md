# Banc de mesure de la recherche de l'assistant

## À quoi ça sert

Le banc pose une série de questions à l'assistant sur des documents d'essai (PDF, Word, texte) et
mesure deux choses :

- la **recherche** : le bon passage est-il retrouvé parmi les premiers résultats ? (colonne « Trouvé ») ;
- les **réponses** : la réponse donnée est-elle juste, fidèle aux documents, et l'assistant refuse-t-il
  bien quand l'information n'existe pas ? (colonnes « Justes », « Fidèles », « Refus corrects »).

Il ne touche jamais aux vrais documents du site ni à sa base : il travaille dans un espace temporaire.
Au bureau, il sert à **vérifier** que les réglages retenus donnent les mêmes résultats qu'ici.
Ne modifiez jamais le code sur place.

## Ce qu'il faut avoir

- Python uniquement (pas de php ni de node). On travaille dans le dossier `assistant/`.
- Le fichier `llm.env` (à la racine de nh-web) rempli : adresse du LLM et du modèle d'embedding.
- Les 3 vrais PDF, copiés dans `banc/corpus/pdf/` **sous ces noms exacts** (ils ne sont jamais enregistrés dans git) :
  - `reel-4007-modalites-de-lancement-des-vols-de-controle-et-des-vols-a-caractere-de-maintenance.pdf`
  - `reel-4105-3-regles-demploi-du-bordereau-de-visite-bdv.pdf`
  - `reel-4105-5-utilisation-des-tolerances-impactant-une-echeance-de-tache-dentretien.pdf`

  Sans eux, ou avec un autre nom, les 17 questions qui les concernent sont « non applicables » et les
  pourcentages ne sont plus comparables. Regardez la colonne « Questions » de `rapport.md` : elle doit
  indiquer **67** en pdf avec les 3 vrais PDF (50 sans eux). Si vous lisez un autre nombre, vérifiez les noms.

## Lancer la vérification

```
cd /home/coder/nh-web1/assistant
.venv/bin/python -m banc.banc --reglages retenu-motscles-k8 --formats pdf
.venv/bin/python -m banc.banc --reglages retenu-motscles-k8 --formats pdf --reponses
```

La première commande teste la recherche seule (rapide). La seconde fait aussi répondre et juger
chaque question (plus longue, elle utilise le LLM). Pour comparer avec l'ancien comportement,
ajoutez-le : `--reglages retenu,retenu-motscles-k8` (`retenu` = sans mots-clés).

## Lire le résultat

Chaque lancement écrit un dossier `banc/resultats/<date>_<heure>/` avec `rapport.md` (les chiffres,
à lire en premier) et `detail.csv` (question par question). Dans `rapport.md` :

- **Trouvé** : pourcentage de questions dont le bon passage remonte. Plus c'est haut, mieux c'est.
- **Justes** : pourcentage de réponses correctes.
- **Fidèles** : la réponse ne contient rien d'inventé (doit rester proche de 100 %).
- **Erreurs** : nombre de questions pour lesquelles la réponse n'a pas pu être obtenue (par exemple un
  texte trop long pour le LLM). Doit rester à 0 ; si Erreurs > 0, renvoyer `rapport.md`.
- **Illisibles** : nombre de réponses que le juge automatique n'a pas su noter (à 0 en principe).
- **Refus corrects** : pour les questions dont la réponse n'est pas dans les documents,
  l'assistant dit bien qu'il ne sait pas (doit rester 100 %, **y compris sur les questions pièges
  P01 à P10**, voir plus bas).
- **Passages envoyés (moy / max)** : nombre d'extraits transmis au LLM par question, en moyenne et au
  plus (voisins compris).
- **Caractères envoyés (moy / max)** : volume de texte transmis au LLM par question. Le maximum ne
  dépasse pas `ASSISTANT_PLAFOND_CARACTERES` (10 000 par défaut), sauf si le premier extrait à lui seul
  est plus long. Un volume qui grimpe = réponses plus lentes et plus chères.

### Les questions pièges (P01 à P10)

Ce sont des questions **sans réponse dans les documents** mais très proches de ce qu'ils contiennent :
un code voisin d'un code existant (« HYD-113 » alors que seul HYD-112 existe, « PC-HYD-08 » au lieu de
PC-HYD-07), une visite ou un circuit qui n'existe pas (V5, circuit 3), un document électrique alors que
seuls les documents hydrauliques sont décrits, etc. Elles vérifient que la recherche par mots-clés ne
fait pas répondre l'assistant à côté : pour chacune, la bonne réponse est le refus. Le détail par
question est dans `detail.csv` (colonne `refus_correct`).

## Chiffres de référence (mesurés ici, réglages `retenu-motscles-k8`)

| | pdf | docx | txt |
|---|---|---|---|
| Recherche, « Trouvé » | 100 % | 97 % | 97 % |
| Réponses, « Justes » (chiffres bruts de `rapport.md`) | 90 % | 94 % | 97 % |

Fidèles et refus corrects : 100 % dans les trois formats. Sans la recherche par mots-clés (`retenu`),
le « Trouvé » était de 96 / 94 / 97 %.

**Au bureau, regardez surtout la colonne Refus corrects : la recherche par mots-clés laisse passer des passages même quand l'information n'existe pas, c'est le LLM qui doit refuser. Si Refus corrects < 100 %, renvoyez rapport.md : on pourra remettre ASSISTANT_MOTS_CLES=non dans reglages.env.**

Le juge automatique compte parfois à tort une bonne réponse comme fausse : quelques points de moins sur
« Justes » ne sont donc pas alarmants. La règle des 10 points ci-dessous s'applique à « Trouvé ».
Quelques points d'écart d'un lancement à l'autre sont normaux : le corpus est petit.

## Si les chiffres diffèrent

**Si les chiffres de recherche s'écartent de plus de 10 points de ceux d'ici, envoyer `rapport.md`**
(il ne contient que des chiffres, aucun texte de document). Ne changez aucun réglage de votre côté.
