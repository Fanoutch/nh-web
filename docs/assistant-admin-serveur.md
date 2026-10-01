# Assistant IA de cAIman — modifications à faire sur le serveur

À transmettre à l'administrateur du serveur qui héberge cAIman, **le jour où la fonctionnalité
« Assistant IA » est mise en service**. Tout ce qui touche au code (tables, fichiers du site) passe
par le dépôt et `php artisan migrate` ; ce document liste **uniquement ce qui se règle sur la machine
elle-même**, pour que rien ne soit oublié à la relance.

Procédure technique détaillée : `docs/commandes.md`, section 15.

---

## Ce qui change sur le serveur, en bref

| # | Quoi | Où | Pourquoi |
|---|---|---|---|
| 1 | Python **3.12** + un environnement Python dédié | `nh-web/assistant/.venv` | le nouveau service de l'assistant est écrit en Python |
| 2 | Un **nouveau service systemd** `nh-assistant` | `/etc/systemd/system/nh-assistant.service` | il fait tourner l'assistant en permanence |
| 3 | **8 workers** pour le site | `nh-laravel.service` | plusieurs utilisateurs peuvent recevoir une réponse en même temps |
| 4 | Limite d'envoi PHP à **64 Mo** | `/etc/php/<version>/cli/conf.d/99-nh-web.ini` | dépôt de documents jusqu'à 50 Mo |
| 5 | Délai des tâches de fond | `.env` de Laravel | l'indexation d'un gros document peut durer jusqu'à 10 minutes |
| 6 | Deux fichiers de réglages (secrets) | `nh-web/llm.env`, `nh-web/assistant/reglages.env` | adresse du modèle bge-m3 et jeton interne du service |
| 7 | (si nginx est devant le site) taille des envois | configuration nginx du site | sinon les documents de plus de 1 Mo sont refusés |
| 8 | Pièces jointes du chat : jusqu'à **20 Mo par fichier, 5 par conversation** | `storage/app/private/assistant/pj/` | les fichiers d'une conversation supprimée sont effacés automatiquement, rien à nettoyer à la main |

**Aucun port à ouvrir** : le service de l'assistant écoute uniquement en local (`127.0.0.1:8765`),
seul le site cAIman l'appelle.

**Cette mise à jour (dossiers, pièces jointes, sources cliquables) n'ajoute aucun nouveau service
système, aucune nouvelle bibliothèque Python et aucun port à ouvrir** : elle passe uniquement par
`php artisan migrate` (4 nouvelles migrations) et un redémarrage de `nh-assistant` après mise à jour
du code Python — voir « Relance » ci-dessous.

**Accès réseau sortant nécessaire** depuis le serveur cAIman vers :
- le serveur **bge-m3** (embedding) du bureau ;
- le serveur **LLM** du bureau (déjà utilisé par la génération de dispo : aucun changement).

---

## Avant de commencer : à vérifier

- [ ] `python3.12 --version` répond (sinon installer Python 3.12 : le service a été testé avec 3.12.3).
- [ ] **RAM disponible** : environ **1 Go** de plus que l'existant (8 workers × 30-50 Mo + le service Python).
- [ ] Le serveur cAIman peut joindre l'adresse du **bge-m3** du bureau (ex. `curl -I <adresse>`).
- [ ] Connaître l'**utilisateur qui fait tourner le site** (souvent `www-data`) : il doit pouvoir lire les
      fichiers de réglages.

---

## Les modifications, dans l'ordre

Toutes les commandes se lancent depuis le dossier du projet (`cd /chemin/vers/nh-web`), en root.

### 1. Environnement Python de l'assistant

```bash
cd assistant && python3.12 -m venv .venv && .venv/bin/python -m pip install -r requirements.lock && cd ..
```
Attendu : la dernière ligne commence par `Successfully installed`.
**Si `pip` échoue** (pas d'accès à Internet) : ne pas insister, envoyer le message d'erreur complet,
la sortie de `cat /etc/os-release` et `python3.12 --version` → les bibliothèques seront fournies
pré-téléchargées.

### 2. Fichiers de réglages

```bash
cp assistant/reglages.env.example assistant/reglages.env
php -r 'echo bin2hex(random_bytes(24)), PHP_EOL;'      # copier le résultat dans ASSISTANT_JETON=
chown root:www-data assistant/reglages.env && chmod 640 assistant/reglages.env
```
Dans `llm.env` (déjà présent pour la dispo), ajouter les 4 lignes `EMBED_*` avec l'adresse du bge-m3
du bureau (modèle et explications : `llm.env.example`).
Mêmes droits que `reglages.env` si ce n'est pas déjà le cas : lisible par l'utilisateur du site, pas par tous.

### 3. Service `nh-assistant`

```bash
sed "s#/CHEMIN/nh-web#$PWD#g" assistant/nh-assistant.service.example > /etc/systemd/system/nh-assistant.service
systemctl daemon-reload && systemctl enable --now nh-assistant
systemctl is-active nh-assistant                         # attendu : active
```
Utilisateur du service : par défaut root. Pour le faire tourner sous l'utilisateur du site, décommenter
`User=www-data` dans le fichier ; cet utilisateur doit alors pouvoir lire `llm.env`,
`assistant/reglages.env`, `storage/app/private` et écrire dans `storage/app/assistant/chroma`.
Journal du service : `/var/log/nh-assistant.log`.

### 4. Plusieurs utilisateurs en même temps : 8 workers

Dans `/etc/systemd/system/nh-laravel.service`, section `[Service]`, ajouter :
```
Environment=PHP_CLI_SERVER_WORKERS=8
```
Chaque réponse de l'assistant en cours d'écriture occupe un worker pendant toute sa durée
(10 à 60 secondes) : 8 workers = jusqu'à 7 réponses simultanées. Au-delà, les suivantes attendent leur
tour (pas de plantage). À augmenter (12, 16…) si beaucoup d'utilisateurs, selon la RAM (30-50 Mo par worker).

### 5. Documents jusqu'à 50 Mo

Créer `/etc/php/<version>/cli/conf.d/99-nh-web.ini` (version : `php -v`) :
```
upload_max_filesize = 64M
post_max_size = 64M
```
Si **nginx** est devant le site, dans le bloc `server` : `client_max_body_size 64m;`
puis `nginx -t && systemctl reload nginx`.

### 6. Tâches de fond longues

Dans le `.env` de Laravel : `DB_QUEUE_RETRY_AFTER=660`.

### 7. Relance

```bash
php artisan migrate
php artisan config:clear && php artisan route:clear && php artisan view:clear
#   (si le site utilise les caches : php artisan config:cache && php artisan route:cache, en tant qu'utilisateur du site)
systemctl daemon-reload
systemctl restart nh-laravel nh-queue nh-assistant
php artisan queue:restart
```
Les caches doivent être reconstruits **après** la création de `assistant/reglages.env` (Laravel y lit
le jeton du service).

### Découpage des documents

Six réglages de `assistant/reglages.env` fixent la façon dont un document est découpé en morceaux.
Valeurs retenues (mesurées avec le banc de `assistant/banc/`) :

```
ASSISTANT_FUSION_SECTIONS=oui
ASSISTANT_PAR_PAGE=non
ASSISTANT_CHEVAUCHEMENT=150
ASSISTANT_VOISINS=1
ASSISTANT_TAILLE_MORCEAU=600
ASSISTANT_TAILLE_MIN=150
```
Ce sont aussi les valeurs par défaut : un `reglages.env` sans ces lignes donne le même résultat.
Sur un serveur déjà installé, ajouter ces six lignes au `reglages.env` existant (le fichier ne se remplace
pas), puis `systemctl restart nh-assistant`.

- **Passer à cette version** change le découpage par défaut, même sans toucher à `reglages.env` : les
  documents déjà indexés gardent l'ancien découpage (page par page, sans chevauchement). Il faut donc
  **redéposer tous les documents déjà indexés** pour profiter du nouveau. Avec d'anciens morceaux, les
  voisins fonctionnent mais agrandissent les extraits envoyés au LLM. Il n'existe pas d'outil de
  réindexation en masse (besoin à prévoir plus tard) : le redépôt se fait document par document.
- `FUSION_SECTIONS`, `PAR_PAGE`, `CHEVAUCHEMENT`, `TAILLE_MORCEAU`, `TAILLE_MIN` ne s'appliquent qu'aux
  documents déposés **ensuite** : après un changement, **redéposer** les documents déjà indexés.
- `VOISINS` s'applique **tout de suite**, sans redépôt (simple redémarrage du service).
- Depuis cette version, les tableaux des documents Word et texte sont lus à leur place avec leurs en-têtes ; les Word et textes déjà indexés sont à redéposer pour en profiter (les PDF ne changent pas).
- Mode d'emploi du banc de vérification : `assistant/banc/A-LIRE.md`.

---

## Vérification finale

```bash
systemctl is-active nh-assistant nh-laravel nh-queue                           # active ×3
J=$(grep '^ASSISTANT_JETON=' assistant/reglages.env | cut -d= -f2-)
curl -s -H "X-Assistant-Jeton: $J" http://127.0.0.1:8765/sante
#   attendu : {"service":"ok","embedding":"ok","llm":"ok"}
curl -s -o /dev/null -w "%{http_code}\n" http://127.0.0.1:8765/formats        # attendu : 401 (sans jeton)
curl -s http://127.0.0.1/login | grep -o '<title>[^<]*</title>'                # attendu : <title>cAIman</title>
```
Si `embedding` ou `llm` n'est pas `ok`, le message indique la cause (adresse, clé, réseau).

---

## À renvoyer une fois terminé

- [ ] la sortie de la vérification finale (sans afficher le jeton ni les clés) ;
- [ ] la RAM totale et libre du serveur (`free -h`) ;
- [ ] **côté serveur LLM du bureau** : combien de questions il traite en parallèle (c'est la vraie limite
      du nombre d'utilisateurs simultanés, indépendamment des workers du site).

---

## Retour en arrière (si besoin)

```bash
systemctl disable --now nh-assistant && rm /etc/systemd/system/nh-assistant.service && systemctl daemon-reload
```
Retirer `Environment=PHP_CLI_SERVER_WORKERS=8` de `nh-laravel.service` et le fichier `99-nh-web.ini`
n'est pas nécessaire (sans effet négatif). Les 3 tables se retirent avec `php artisan migrate:rollback`
(uniquement si la fonctionnalité est abandonnée : cela efface documents et conversations).
