# Mise en service — Secteurs et dispo BMN

Notice destinée à **l'équipe qui déploie nh-web sur le serveur**. Elle décrit ce
qu'il faut faire **en plus** du déploiement habituel, après la récupération du
code depuis GitLab.

La fonctionnalité ajoute un espace « Secteurs », un secteur **BMN** avec un
onglet **Disponibilités**, et un script Python (`bmn/`) qui remplit un template
Excel à partir d'une extraction CSV ou JSON déposée sur la page.

---

## 1. Ce qui arrive par git, et ce qui n'y est pas

**Fourni par le dépôt :** tout le code, le script `bmn/`, les migrations, et les
**modèles** de fichiers de réglages (`llm.env.example`, `bmn/reglages.env.example`).

**À créer sur le serveur, jamais dans git :**

| Élément | Pourquoi il n'est pas dans le dépôt |
|---|---|
| `bmn/.venv/` | environnement Python, propre à la machine |
| `llm.env` (racine) | contient une **clé d'API** |
| `bmn/reglages.env` | désigne le template réel |
| `bmn/template/<template>.xlsx` | document de travail, données réelles |

⚠️ **La clé d'API ne doit pas transiter par GitLab.** Elle se transmet par le
canal sécurisé habituel, ou l'équipe utilise une clé de service dédiée.

---

## 2. Prérequis

- PHP et Composer : déjà en place pour nh-web.
- **Python 3.10 ou plus**, avec le module `venv`.
- Accès réseau depuis le serveur vers le **service LLM interne** (voir §5).
  Sans lui, la dispo se génère quand même, mais la colonne « pièce » des HIL
  reste vide.

---

## 3. Déploiement du code

Depuis la racine du projet, après récupération de la branche :

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force        # 4 migrations, dont la création du secteur BMN
php artisan optimize:clear
```

Les 4 migrations créent `excel_reports`, `secteurs`, `secteur_user`, et ajoutent
`secteur_id` à `excel_reports`.

---

## 4. Environnement Python du script

```bash
cd bmn
python3 -m venv .venv
.venv/bin/pip install -r requirements.txt
.venv/bin/python -m pytest tests -q      # doit finir sur « passed »
cd ..
```

Puis **une ligne à ajouter au `.env` de Laravel** (chemin absolu) :

```
EXCEL_PIPELINE_PYTHON=/chemin/absolu/vers/nh-web/bmn/.venv/bin/python
```

Sans cette ligne, le dépôt d'une extraction se solde par une erreur visible dans
l'historique de la page.

---

## 5. Fichier `llm.env` (racine du projet)

Copier le modèle, puis renseigner les valeurs :

```bash
cp llm.env.example llm.env
chmod 600 llm.env
```

| Variable | Rôle | Exemple |
|---|---|---|
| `LLM_ACTIF` | `true` interroge le modèle pour les lignes HIL ; `false` : aucun appel réseau, colonne pièce laissée vide | `true` |
| `LLM_BASE_URL` | adresse de l'API, **sans** `/chat/completions` | `https://service.interne/v1` |
| `LLM_MODELE` | identifiant exact du modèle, tel que le service l'expose | `mistral-small` |
| `LLM_CLE_API` | clé d'API ; vide si le service n'en demande pas | — |
| `LLM_TIMEOUT` | délai maximum par appel, en secondes | `120` |
| `LLM_TEMPERATURE` | `0` donne la réponse la plus stable | `0` |

**Certificat interne.** Si le service présente un certificat auto-signé, l'appel
échoue en `CERTIFICATE_VERIFY_FAILED`. Deux réponses, au choix :

```
LLM_CA_BUNDLE=/etc/ssl/certs/ca-interne.pem   # recommandé : la vérification reste active
LLM_VERIF_TLS=false                            # à défaut : vérification coupée, journalisée
```

**Vérification :**

```bash
bmn/.venv/bin/python bmn/llm_client.py --test
```

- `[OK] le modèle répond au format attendu.` → c'est bon.
- `[KO] Service injoignable` → adresse, port ou pare-feu.
- Erreur `401` / `404` → clé ou nom de modèle.
- Erreur de certificat → voir ci-dessus.

Le format attendu est celui de l'API OpenAI (`POST /v1/chat/completions`). Si le
service interne parle autrement, **une seule fonction est à adapter** :
`appeler_modele`, dans `bmn/llm_client.py`.

---

## 6. Fichier `bmn/reglages.env` et template

```bash
cp bmn/reglages.env.example bmn/reglages.env
```

| Variable | Rôle | Valeur attendue |
|---|---|---|
| `DISPO_TEMPLATE` | template Excel à remplir, chemin relatif à `bmn/` ou absolu | `template/dispo.xlsx` |
| `DISPO_BLOCS_ACTIF` | `true` pour le vrai template de dispo (un bloc par machine) ; `false` pour le template factice des tests | `true` |

Déposer ensuite le **vrai template** dans `bmn/template/`, sous le nom indiqué.
Ce dossier est ignoré par git, sauf le template factice `template.xlsx`, qui sert
aux tests et ne doit pas être supprimé.

Le template doit conserver **les noms de machine en colonne A** (`NH01`, `NH02`…)
et **les intitulés** (FH, ATT, TREUI, APU OPH, APU OPC) : ce sont les repères qui
permettent au script de trouver où écrire.

---

## 7. Droits d'accès

Le secteur BMN est créé par la migration. Les droits se donnent depuis le site,
page **Utilisateurs**, colonne « Secteurs », par un super administrateur :

| Rôle | Peut consulter et télécharger | Peut déposer une extraction et supprimer |
|---|---|---|
| Utilisateur | oui | non |
| Chef | oui | oui |
| Administrateur | oui, tous secteurs | oui |

**Sans rôle, un compte n'a pas accès à la page.** Il faut donc en attribuer au
moins un, sinon personne ne pourra générer de dispo.

---

## 8. File d'attente

La génération passe par un **job en file d'attente** (`queue:work`), comme les
imports XML. Après déploiement :

```bash
php artisan queue:restart
```

Un worker doit tourner en permanence (service systemd, superviseur, ou
équivalent). Sans lui, les dépôts restent indéfiniment sur « Génération en
cours… ».

---

## 9. Vérification finale

1. Se connecter, ouvrir **Secteurs → BMN → Disponibilités**.
2. Déposer une extraction CSV ou JSON.
3. La ligne passe de « Génération en cours… » à **« Télécharger »** en quelques
   secondes, un peu plus si le LLM est actif (environ 2 secondes par ligne HIL).
4. Ouvrir le fichier produit : les machines de l'extraction doivent être
   remplies, les formules recalculées à l'ouverture.

En cas d'erreur, le message est affiché en rouge dans l'historique de la page, et
le détail se trouve dans `bmn/logs/daily_report.log`.

---

## 10. Entretien

- **Rétention** : les dispos de plus de `EXCEL_REPORTS_RETENTION_DAYS` jours
  (30 par défaut) sont supprimées, fichier compris, après chaque génération
  réussie. À la main : `php artisan bmn:purge` ou `php artisan bmn:purge --days=7`.
- **Stockage** : les fichiers produits sont dans `storage/app/excel-reports/`,
  les dépôts temporaires dans `storage/app/staging/` (nettoyés après traitement).
- **Mise à jour du code** : `git pull`, `composer install`, `php artisan migrate`,
  `php artisan optimize:clear`, `php artisan queue:restart`. Les quatre éléments
  du §1 ne sont jamais écrasés par une mise à jour.

Voir aussi `docs/commandes.md`, section 13, et `bmn/README.md` pour le détail du
script.
