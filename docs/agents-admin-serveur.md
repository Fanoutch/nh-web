# Agents Excel / Word — modifications à faire sur le serveur

À transmettre à l'administrateur **le jour où l'onglet « Agents » est mis en service**. Les agents réutilisent le
service Python de l'assistant (`nh-assistant`) : **aucun nouveau service, aucun nouveau port, aucun nouveau
modèle**. Tout le reste passe par le dépôt.

## En bref

| # | Quoi | Où | Pourquoi |
|---|---|---|---|
| 1 | `php artisan migrate` (**5 migrations** : `agents`, `agent_fichiers_reference`, `agent_executions`, `agent_rappels`, colonnes du chat) | site | tables des agents |
| 2 | `npm run build` | poste de build | nouvelles classes de style de l'onglet |
| 3 | Environnement Python mis à jour (`openpyxl`, `et-xmlfile` ajoutés à `requirements.lock`) puis `systemctl restart nh-assistant` | `assistant/.venv` | lecture des classeurs Excel |
| 4 | `cron` : `* * * * * php artisan schedule:run` (déjà requis par la purge des dispos) | serveur | tâche quotidienne `agents:entretien` (03:15) : mise en veille |
| 5 | Délai du site : une exécution d'agent dure jusqu'à 5 minutes | `nh-laravel` (workers) | le résultat arrive en flux, comme pour l'assistant |

Le modèle de langage est celui de `llm.env` (le même que l'assistant). **Il doit savoir appeler des outils** :
voir « Vérifier le modèle » ci-dessous.

## Fichiers des agents

- Stockés dans `storage/app/private/agents/<identifiant>/` : `references/`, `apports/` (documents en attente) et
  `executions/<n>/entree` + `sortie`. Rien n'est public ; le téléchargement passe par le site (droits du seul
  propriétaire).
- **Aucune suppression automatique** tant qu'un agent est actif. À la mise en veille (3 mois sans lancement), les
  documents en attente et les dossiers d'exécution sont supprimés ; références et historique restent.
- Limites : 20 Mo et 100 000 lignes par fichier ; alerte à 500 Mo par agent (réglages dans `config/agents.php`).

## Vérifier le modèle (avant d'ouvrir aux utilisateurs)

```bash
python3 scripts/test-outils-llm.py                       # résultat A ou B : on continue ; C : les agents ne marcheront pas
cd assistant && .venv/bin/python -m banc_agents.banc     # 20 demandes types sur des documents fabriqués (~5 min)
```

Référence de développement (modèle Mistral Nemo) : **12 / 20**, et **100 %** sur les familles de sécurité (pièges,
consigne cachée dans un document, outil absent). Au bureau, exiger au moins ce score et 100 % sur ces familles.

## Dépannage

- « L'assistant est indisponible » sur un agent : `systemctl status nh-assistant`, puis `/sante` (voir
  `docs/assistant-admin-serveur.md`).
- Un agent s'arrête avec « a échoué deux fois » ou « demandé deux fois la même opération » : garde-fous voulus
  (12 étapes, 5 minutes, même appel deux fois, deux échecs d'un même outil). Le détail technique est conservé dans
  l'exécution (`agent_executions.detail`).
- Forcer l'entretien : `php artisan agents:entretien`.
