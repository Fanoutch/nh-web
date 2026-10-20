# Tests navigateur

## À quoi sert ce banc

La suite Pest (`php artisan test`) ne lance aucun navigateur : elle ne peut donc pas voir une
panne du JavaScript. Ce banc pilote un vrai Chrome sur un vrai serveur PHP et rejoue les
parcours de l'Assistant IA. Il a été conçu après un bug que tous les tests Pest avaient laissé
passer : une seconde instance d'Alpine empêchait Livewire de s'initialiser, et plus aucun clic
ne fonctionnait sur les pages Livewire.

## Lancer le banc

```bash
scripts/tests-navigateur.sh
```

Cette commande suffit : elle crée au besoin l'environnement Python `.venv-tests` (Playwright),
puis lance le parcours. Elle se termine par le code 0 si tout passe, 1 sinon, avec un résumé en
français. En cas d'échec, une capture d'écran est déposée dans `storage/logs/`.

Prérequis : `google-chrome`, `python3` et `php`, ainsi que les ressources front compilées
(`npm run build`), car le banc teste ce que le navigateur charge réellement.

## Ce qu'il vérifie

1. La connexion par le vrai formulaire.
2. Parcours chat : l'onglet Assistant IA s'ouvre sans aucune erreur de console (en particulier
   « Detected multiple instances of Alpine running »), un clic sur une suggestion remplit la zone
   de saisie, l'envoi d'une question vide la zone et affiche « L'assistant réfléchit… ».
3. Parcours agents : « Nouvel agent » ouvre le chat, un message affiche les étapes, la réponse et la carte de
   confirmation, « Oui, enregistrer » affiche « Enregistré », un document déposé affiche sa confirmation de lecture,
   la suppression de l'agent (avec confirmation) vide la liste.
4. Parcours documents : l'onglet Documents permet de créer un dossier, qui apparaît dans
   l'arborescence puis dans le fil d'Ariane à l'ouverture, avant d'être supprimé.

## Ce qu'il faut savoir

- Le banc n'écrit que dans la base de test `nh_project_test` (mêmes paramètres que
  `phpunit.xml`) ; il refuse de démarrer s'il ne peut pas le vérifier. La base de développement
  n'est jamais touchée. Il crée un utilisateur, un document fictif et un dossier, et les
  supprime à la fin.
- Cette base est partagée avec Pest : ne lancez pas les deux en même temps.
- Le banc démarre son propre serveur sur le port 8099 (et un faux service d'assistant sur le
  port 8098) et les arrête à la fin. Le site de développement n'est pas concerné. Aucun appel
  n'est fait au modèle de langage : le banc s'arrête à « L'assistant réfléchit… ».
- Ce banc est un outil de développement : il n'est pas nécessaire au bureau. Ni
  `package.json`, ni `composer.json`, ni les `requirements` ne le mentionnent, et `.venv-tests`
  n'est pas versionné.
