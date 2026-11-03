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

## Anciens formats .doc / .xls (LibreOffice)

Un utilisateur peut déposer un .doc ou un .xls : le service le convertit en .docx / .xlsx **en gardant la mise en
forme** (tableaux, gras, images, en-têtes), grâce à LibreOffice. Il faut donc LibreOffice **sur le serveur où tourne
`nh-assistant`** (pas sur les postes) :

```
sudo dnf install libreoffice-writer libreoffice-calc    # RHEL 8 ; sans interface graphique
which soffice                                            # attendu : un chemin, par exemple /usr/bin/soffice
```

Sans LibreOffice, rien ne casse : le dépôt d'un .doc est refusé avec la marche à suivre pour l'utilisateur
(« Enregistrer sous » .docx). Aucun document « à plat » n'est jamais produit.

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

## Agents du catalogue (assignés par le super admin)

Mode d'emploi complet pour CRÉER un agent : `docs/agents-catalogue.md`. Les agents que le super admin développe se trouvent dans `database/agents-catalogue/<nom>/agent.php` (versionnés, livrés
par la zip). Le super admin les assigne depuis **Admin > Utilisateurs > Catalogue d'agents** (par e-mail, ou avec le menu
« + Ajouter » d'une ligne d'utilisateur) et peut les essayer avec le bouton **Tester** (sa propre copie).

**Fichiers de référence réels : jamais dans git.** Un agent peut exiger un fichier (ex. le formulaire DACSSI, listé dans
`references` de son `agent.php`). Il se dépose UNE FOIS sur le serveur de l'application :

```
storage/app/private/catalogue-agents/<nom-de-l-agent>/references/<fichier>
```

Exemple : `storage/app/private/catalogue-agents/dacssi-certificats/references/202610xx_DACSSI.docx` (au format `.docx`).
Tant qu'un fichier manque, le catalogue affiche « Références manquantes » et l'assignation comme le test sont refusés
avec le nom du fichier à déposer.

**Après une correction d'un agent** (consignes, description, fichier de référence) et son déploiement, le catalogue
indique « N copies à mettre à jour » : le bouton « Mettre à jour les copies » les met toutes à jour. Les documents
déposés par les utilisateurs et leur historique ne sont pas touchés. Les corrections du code de l'assistant (outils)
n'ont pas besoin de ce bouton : elles agissent partout dès le déploiement.

Migration : `2026_11_03_100000_add_catalogue_columns_to_agents_table` (3 colonnes vides sur `agents`).
