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
- **Word** : lire le plan et le texte, chercher un mot, lire un tableau, créer un document neuf, **remplir un
  modèle** existant et produire **un document par ligne d'un Excel** (voir le paragraphe 5 bis).
- **Limites** : 20 Mo et 100 000 lignes par classeur. L'agent ne lit pas les images, ne lance jamais de macro et ne
  calcule jamais « de tête » : les calculs sont faits par le programme, pas par le modèle.

## 5 bis. Remplir un modèle Word avec des données Excel (fini le copier-coller)

1. Préparez votre modèle Word une fois pour toutes. À la place de ce qui change, écrivez un **marqueur entre doubles
   accolades** : `{{DATE}}`, `{{TOTAL}}`, `{{NOM}}`… Pour une liste de pièces, gardez un tableau avec sa ligne
   d'en-tête (les lignes sont ajoutées en reprenant la mise en forme de la dernière).
2. Glissez le modèle **et** le classeur Excel dans le chat, puis dites ce que vous voulez : « Dans mon courrier,
   mets le total du stock à la place de `{{TOTAL}}`, la date du jour à la place de `{{DATE}}`, et remplis le tableau
   avec les pièces dont le stock est inférieur à 5. »
3. **Les chiffres et les lignes sont copiés par le programme**, depuis le classeur : l'agent ne les retape jamais,
   il ne peut donc pas se tromper en les recopiant. Vous récupérez un **nouveau** document ; votre modèle reste intact.
4. Si un marqueur est introuvable ou si une donnée manque, l'agent le dit et **ne produit rien** (tout ou rien) :
   il vous liste les marqueurs trouvés dans le modèle.

Vous pouvez aussi lui demander de remplacer ou compléter une **section entière** sous un titre du modèle.

### Un document par personne (publipostage)

Vous avez un Excel avec une ligne par personne (ou par client, par pièce…) et un modèle Word ? Écrivez :
« J'ai besoin d'un fichier par personne de ma liste, à partir de mon modèle. »

- Dans le modèle, chaque marqueur porte **le nom d'une colonne** de l'Excel : `{{Nom}}`, `{{Prénom}}`, `{{Service}}`…
  (les majuscules et les accents n'ont pas d'importance). Si les noms diffèrent, dites à l'agent quel marqueur
  correspond à quelle colonne.
- L'agent crée **un document par ligne** (50 au plus par demande ; au-delà, ajoutez une condition, par exemple « les
  personnes du service BMN »). Chaque document contient les données de **sa** personne, copiées par le programme.
- Les fichiers sont nommés d'après la première colonne (« fiche-Martin.docx »), ou comme vous le demandez.
- Tous les documents sont dans « Fichiers produits », avec un bouton **Tout télécharger (.zip)**.
- Si une cellule est vide, le champ reste vide dans le document **et l'agent vous le signale**. Si un marqueur n'a pas
  de colonne, il ne produit rien et vous liste les colonnes disponibles.
- **Pas de marqueurs ? Votre Word peut être un exemple déjà rempli** pour une personne (un certificat de « Jean Bon
  Chevalier », par exemple). Le programme repère les valeurs de cette personne qui se trouvent aussi dans l'Excel (nom,
  prénom, date, fonction…) et les remplace, pour chaque ligne, par les valeurs de la colonne correspondante. Le compte
  rendu dit **ce qui a été remplacé et ce qui ne l'a pas été** : un numéro de certificat ou une date de naissance qui
  n'existe pas dans l'Excel (ou écrit autrement) reste tel quel dans le modèle. Dites alors à l'agent quel texte
  remplacer par quelle colonne.
- **Formulaire vierge** (des « ........ » ou des cases vides à côté de libellés comme « Nom », « Grade ») : dites
  « remplis ce formulaire pour chaque personne de l'Excel ». Chaque champ est repéré par son libellé et rempli avec la
  colonne correspondante ; la mise en page du formulaire est conservée. Le texte est lu partout : zones « Cliquez ici pour entrer du texte », **encadrés**, **cases grises** des
  formulaires Word, en-têtes, pieds de page et notes. Si le document contient des **cases à cocher**, des **listes
  déroulantes** ou des **équations**, le panneau l'indique (⚠) dès le dépôt : ces éléments ne seront pas remplis.
- **Ancien format .doc ou .xls ?** Déposez-le tel quel : il est converti automatiquement en .docx / .xlsx, mise en forme
  comprise (le nom du fichier change d'extension dans le panneau). Si le serveur ne sait pas le convertir, un message vous
  dit de l'enregistrer vous-même au format .docx depuis Word.

### Relancer un agent : même résultat à chaque fois

Le bouton **Lancer l'agent** rejoue la recette **par le programme, sans passer par le modèle**, quand les données viennent
des documents déposés : c'est plus rapide et le résultat est identique d'un lancement à l'autre. Si un document porte un
autre nom que la dernière fois, le programme retrouve celui qui lui ressemble et l'indique ; dans le doute, il vous demande
de préciser au lieu de deviner. Si la recette contient des valeurs écrites à la main (un texte fixe dans un Word par
exemple), le modèle la reprend pour les recalculer.

### Dates et copies de données

Les dates sont comprises comme en France : 15/01/2027, 15 janvier 2027, 1er mars 2027. Une ligne qui ne contient pas une
date (« Néant ») est écartée **et signalée** : le résultat ne cache rien. Pour créer un fichier à partir d'un classeur ou
d'un tableau Word, c'est le programme qui copie les valeurs, jamais le modèle.

## 6. Bon à savoir

- Le contenu de vos fichiers est traité comme une **donnée** : une phrase « fais telle chose » écrite dans un
  document n'est jamais exécutée.
- Si l'agent se trompe, dites-le-lui dans le chat, ou cliquez sur **Arrêter** et reformulez.
- Les erreurs sont écrites en clair (« La colonne Stock est introuvable dans stock.xlsx ») : vérifiez le fichier
  puis relancez.
