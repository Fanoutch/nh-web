# AGENTS.md — consignes pour opencode (et tout autre assistant de code) sur ce dépôt

Ce fichier reprend, pour un assistant qui travaille sur le **poste du bureau** (réseau fermé), les règles de
conduite du projet cAIman (nh-web). Quand le user dit « lis MISE-A-JOUR.md et exécute-le », ces règles s'appliquent
à chaque étape.

## Règles

1. **Langue** : tout ce qui s'affiche à l'écran, les commentaires, les messages de commit et les comptes rendus sont
   en **français**, au vouvoiement, sans jargon (une erreur dit ce qui s'est passé et quoi faire).
2. **Secrets** : ne jamais afficher, recopier ni commiter le contenu de `llm.env`, `.env`, `assistant/reglages.env`,
   `bmn/reglages.env` (clés, jetons, adresses internes). Pour savoir si une variable existe : `grep -c '^NOM=' fichier`.
3. **Git** : jamais de `git push`, jamais de `git merge` (le user pousse lui-même). Un commit par fonctionnalité,
   message en français (`feat:` / `fix:` / `test:` / `docs:`). **Ne jamais ajouter à git** : `llm.env`, `.env`,
   `assistant/reglages.env`, `bmn/reglages.env`, `CLAUDE.md`, `gitlabupdate.md`, `docs/superpowers/`,
   `assistant/.venv/`, `.venv-tests/`, `dispo vide.xlsx`, les documents d'essai `assistant/banc/corpus/` et
   `assistant/banc/sources/`, ni aucun vrai fichier du bureau (PDF, Excel, Word).
4. **Arrêt net** : à la première étape dont le résultat diffère de l'« attendu » du fichier d'instructions, écrire
   l'écart dans le compte rendu et **s'arrêter**. Ne pas essayer de « faire passer » l'étape.
5. **Ne rien modifier pour faire monter un chiffre** (code, réglages, seuils). Si une vraie correction est
   indispensable (ex. un chemin propre au poste), la faire dans **un commit séparé** et la noter dans le compte
   rendu (fichier, raison, `git diff`) : elle sera reportée au développement.
6. **Données** : le contenu des vrais fichiers du bureau ne sort jamais dans un compte rendu. Pour décrire un
   classeur réel, utiliser `scripts/verifier-classeur.py` (structure seulement, jamais les valeurs).
7. **Poste du bureau** : RHEL 8, Python 3.12, **ni php, ni node, ni composer**. Tout ce qui concerne le site
   (migrations, `npm run build`, services) est fait par l'équipe serveur : le signaler dans le compte rendu comme
   « non vérifiable ici ».
8. **Base de données** : ne jamais écrire dans une base depuis un test ou un script autre que ceux prévus.
9. **Une seule suite de tests à la fois** (la base de test est partagée) ; relancer une fois avant de chercher un
   bug sur une erreur étrange.

## Commandes de test

| Quoi | Commande (depuis la racine) |
|---|---|
| Service de l'assistant et des agents | `cd assistant && .venv/bin/python -m pytest tests -q` |
| Dispo BMN | `cd bmn && .venv/bin/python -m pytest tests -q` |
| Le LLM sait-il appeler des outils ? (A, B ou C) | `python3 scripts/test-outils-llm.py` |
| Le LLM choisit-il les bons outils ? | `cd assistant && .venv/bin/python -m banc_agents.banc` |
| Recherche de l'assistant (RAG) | `cd assistant && .venv/bin/python -m banc.banc --reglages retenu-motscles-k8 --formats pdf,docx,txt` |
| Structure d'un vrai classeur (sans valeurs) | `assistant/.venv/bin/python scripts/verifier-classeur.py <fichier>` |

## Compte rendu

Toujours le format demandé par le fichier d'instructions (`rapport-maj.md` : un tableau étape / résultat / mesure /
remarque). Jamais de valeur de clé, jamais de contenu de fichier réel.
