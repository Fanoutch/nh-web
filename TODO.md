# TODO

## Pour demain (2026-05-12)

### 1. Finir l'espace Personnel Navigant

Brainstorming entamé le 2026-05-11, à reprendre. Décisions déjà validées :

- **Rôle existant** "user" classique → renommer **"Technicien"** (relabel UI partout, pas de migration data)
- **Nouveau rôle** "Personnel Navigant"
  - Migration : ajouter colonne `is_personnel_navigant` boolean default false sur `users`
  - Sémantique : `false` = Technicien, `true` = Personnel Navigant (exclusif)
  - Indépendant de `is_admin` / `is_super_admin` (un admin peut être PN aussi)
  - User model : `$fillable`, cast boolean, helper `isPersonnelNavigant()`
- **Accès** : PN a les mêmes accès qu'un Technicien + une page dédiée en plus
- **Landing au login** : si `is_personnel_navigant` → `/personnel-navigant`, sinon `/machines`
- **Flow PN** (toutes les pages sous `/personnel-navigant/*`) :
  - `/personnel-navigant` : liste compacte des n° machines (même design que `/machines` mais juste les HC IDs)
  - `/personnel-navigant/{hcId}` : liste des vols de la machine
  - `/personnel-navigant/{flight}/pannes` : pannes occurrentes du vol uniquement

À finir dans le brainstorming :
- Section 2 : valider portée du middleware (`/personnel-navigant/*` strict PN ou ouvert aux techniciens ?)
- Section 3 : vues (design des 3 pages PN + relabel Technicien)
- Section 4 : admin UI pour gérer le rôle (`is_personnel_navigant` dans `AdminUsersTable`)
- Section 5 : tests
- Écrire le spec dans `docs/superpowers/specs/2026-05-12-personnel-navigant-design.md`

### 2. ~~Renommer le projet `NH90-cAIman` → `cAIman`~~ ✅ fait 2026-05-18

6 fichiers modifiés : `.env`, `.env.example`, `resources/views/auth/login.blade.php`, `resources/views/layouts/sidebar.blade.php`, `resources/views/layouts/guest.blade.php`, `resources/views/layouts/app.blade.php`. `composer.json` laissé en `laravel/laravel` (nom interne du framework, non visible utilisateur). `config/app.php` non modifié — il lit `APP_NAME` du `.env`.
