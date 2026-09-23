# Mise en service — Secteurs / dispo BMN

En plus du déploiement habituel : **un venv Python, deux fichiers de réglages,
un template Excel**. Tout le reste arrive par git.

## À créer sur le serveur

| Chemin | Contenu |
|---|---|
| `bmn/.venv/` | venv Python 3.10+, `pip install -r bmn/requirements.txt` |
| `llm.env` (racine) | connexion au LLM — modèle : `llm.env.example` |
| `bmn/reglages.env` | template et mode de remplissage — modèle : `bmn/reglages.env.example` |
| `bmn/template/<nom>.xlsx` | le template de dispo réel |

## `llm.env`

Sert à déduire la pièce incriminée des lignes HIL. Format d'API attendu :
OpenAI, `POST {LLM_BASE_URL}/chat/completions`.

```ini
LLM_ACTIF=true                       # false = aucun appel réseau, colonne pièce HIL vide
LLM_BASE_URL=https://service/v1      # sans /chat/completions
LLM_MODELE=nom-exact-du-modele
LLM_CLE_API=...                      # vide si le service n'en demande pas
LLM_TIMEOUT=120                      # secondes, par appel (1 appel par ligne HIL)
LLM_TEMPERATURE=0

# Certificat interne (sinon CERTIFICATE_VERIFY_FAILED) :
# LLM_CA_BUNDLE=/etc/ssl/certs/ca-interne.pem   # vérification conservée
# LLM_VERIF_TLS=false                           # à défaut, coupée (journalisée)
```

Vérification : `bmn/.venv/bin/python bmn/llm_client.py --test` → `[OK]`.
Si l'API interne n'est pas compatible OpenAI, seule `appeler_modele()` de
`bmn/llm_client.py` est à adapter.

## `bmn/reglages.env`

```ini
DISPO_TEMPLATE=template/dispo.xlsx   # relatif à bmn/, ou absolu
DISPO_BLOCS_ACTIF=true               # false = template factice des tests
```

Le template doit garder les noms de machine en colonne A (`NH01`…) et les
intitulés des compteurs (FH, ATT, TREUI, APU OPH, APU OPC) : ce sont les repères
d'écriture. `bmn/template/` est ignoré par git, sauf `template.xlsx` (factice,
utilisé par les tests).

## Commandes spécifiques

```bash
php artisan migrate --force        # 4 migrations, dont la création du secteur BMN
cd bmn && python3 -m venv .venv && .venv/bin/pip install -r requirements.txt
.venv/bin/python -m pytest tests -q   # contrôle
cd ..
```

Une ligne à ajouter au `.env` Laravel :

```ini
EXCEL_PIPELINE_PYTHON=/chemin/absolu/nh-web/bmn/.venv/bin/python
```

Puis `php artisan optimize:clear` et `php artisan queue:restart`.
La génération passe par un **job en file d'attente** : sans worker actif, les
dépôts restent sur « Génération en cours… ».

## Droits

Secteur BMN créé par migration. Rôles attribués par un super admin depuis
`/admin/users`, colonne « Secteurs » : **utilisateur** (consulte, télécharge) ou
**chef** (dépose, supprime). Sans rôle, pas d'accès à la page.

## Divers

- Sorties dans `storage/app/excel-reports/`, dépôts temporaires dans
  `storage/app/staging/` (nettoyés après traitement).
- Purge auto au-delà de `EXCEL_REPORTS_RETENTION_DAYS` (30 j) ; à la main :
  `php artisan bmn:purge [--days=N]`.
- Journal du script : `bmn/logs/daily_report.log`. Les erreurs sont aussi
  affichées dans l'historique de la page.
- Mises à jour : `git pull`, `composer install`, `migrate`, `optimize:clear`,
  `queue:restart`. Les quatre éléments du tableau ne sont jamais écrasés.

Détail du script : `bmn/README.md`. Chaîne complète : `docs/commandes.md` § 13.
