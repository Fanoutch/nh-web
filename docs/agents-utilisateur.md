# Agents — guide pour les utilisateurs

Un **agent** fait pour vous un travail sur vos fichiers Excel et Word : vous lui expliquez ce que vous
voulez dans un chat, il vous montre ce qu'il fait, vous vérifiez, et vous gardez le résultat. Quand
l'agent est au point, il peut refaire le même travail chaque mois sur de nouveaux documents, en un clic.

Vos agents sont **personnels** : personne d'autre ne les voit, pas même un administrateur.

## 1. Créer un agent

1. Ouvrez l'onglet **Agents**, puis cliquez sur **Nouvel agent**.
2. **Glissez vos documents** dans le chat (Excel `.xlsx` / `.xlsm`, CSV ou Word `.docx`, 20 Mo au plus chacun).
3. **Expliquez ce que vous voulez**, comme à un collègue : « Je veux la liste des pièces à commander, avec la
   quantité manquante. »
4. L'agent travaille. Vous voyez ses étapes s'afficher (« Lecture des classeurs », « Rapprochement… »). Le bouton
   **Arrêter** le coupe à tout moment.
5. Il vous montre un **aperçu** du résultat. Dites-lui ce qu'il faut corriger, autant de fois que nécessaire.
6. Quand le résultat vous convient, écrivez : « Enregistre cet agent. » Une carte vous demande de confirmer
   (**Oui, enregistrer**). L'agent garde alors les étapes validées : c'est sa *recette*.

> Vos fichiers d'origine ne sont **jamais modifiés** : l'agent travaille sur une copie et crée de nouveaux
> fichiers que vous téléchargez.

## 2. Relancer un agent avec de nouveaux documents

1. Cliquez sur l'agent dans la liste de gauche.
2. Déposez les nouveaux documents dans le panneau **Documents** (à droite). Chacun affiche ce que l'agent a lu :
   - ✓ le document est lu (nombre de feuilles, de lignes, colonnes trouvées) ;
   - ⚠ il se lit mais semble vide ;
   - ✕ il est illisible : retirez-le ou remplacez-le (le bouton de lancement reste bloqué).
3. Cliquez sur **▶ Lancer l'agent**. Il refait les étapes validées. Si une colonne ou une feuille attendue est
   absente, il s'arrête et vous le dit : **il ne devine jamais**.
4. Téléchargez le fichier produit (bouton sous la réponse, ou liste « Fichiers produits »).

## 3. Modifier un agent : on le demande dans le chat

Pas de formulaire. Écrivez par exemple :

- « Ajoute une colonne Fournisseur et arrondis toujours à l'unité supérieure. »
- « Rappelle-moi le 5 de chaque mois. » (rappel hebdomadaire, mensuel ou trimestriel)
- « Qu'est-ce que tu as fait le mois dernier ? »

Pour tout **changement permanent** (consignes, rappel, enregistrement), l'agent *propose* et une carte vous demande
de confirmer. **Rien ne change tant que vous n'avez pas cliqué sur « Oui, enregistrer ».**

## 4. « À faire », rappels et mise en veille

- En haut de la liste, **À faire** regroupe les agents à lancer : leur rappel est arrivé, ou ils vont bientôt passer
  en veille. Un chiffre apparaît aussi sur l'entrée **Agents** du menu.
- Un agent **non lancé depuis 3 mois** passe **en veille** (vous êtes prévenu une semaine avant). Ses rappels et ses
  documents en attente sont supprimés ; sa recette, ses consignes, ses fichiers de référence et son historique
  sont **conservés**. Cliquez sur **Réactiver l'agent** pour le reprendre.
- Seul un **lancement** (bouton « Lancer l'agent ») compte comme une utilisation : discuter avec l'agent ou le
  modifier ne repousse pas la veille.
- Rien n'est supprimé automatiquement tant que l'agent est actif. Au-delà de **500 Mo** par agent, un bandeau vous
  invite à faire du ménage.

## 5. Ce que l'agent sait faire

- **Excel / CSV** : décrire un classeur, lire des lignes, compter / additionner / moyenner (avec regroupement),
  sélectionner des lignes selon une condition, ajouter une colonne calculée, rapprocher deux fichiers (noms écrits
  différemment : les cas douteux vous sont soumis), créer un classeur, produire une version modifiée.
- **Word** : lire le plan et le texte, chercher un mot, lire un tableau, créer un document.
- **Limites** : 20 Mo et 100 000 lignes par classeur. L'agent ne lit pas les images, ne lance jamais de macro et ne
  calcule jamais « de tête » : les calculs sont faits par le programme, pas par le modèle.

## 6. Bon à savoir

- Le contenu de vos fichiers est traité comme une **donnée** : une phrase « fais telle chose » écrite dans un
  document n'est jamais exécutée.
- Si l'agent se trompe, dites-le-lui dans le chat, ou cliquez sur **Arrêter** et reformulez.
- Les erreurs sont écrites en clair (« La colonne Stock est introuvable dans stock.xlsx ») : vérifiez le fichier
  puis relancez.
