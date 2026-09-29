#!/usr/bin/env bash
# Etat des lieux du poste du bureau (reseau ferme).
#
# A lancer DEPUIS LA RACINE du projet cAIman du bureau :
#     bash scripts/etat-des-lieux-bureau.sh > etat-des-lieux.txt 2>&1
#
# Ce script ne modifie RIEN : il ne fait que lire et afficher.
# Aucune cle d'API ni aucun jeton n'est affiche : seulement "rempli" ou "VIDE".
# Renvoyer le fichier etat-des-lieux.txt.

set -u
ligne() { printf '\n===== %s =====\n' "$1"; }
existe() { [ -e "$1" ] && echo "present" || echo "ABSENT"; }

echo "Etat des lieux cAIman - poste du bureau"
echo "Date du releve : $(date '+%Y-%m-%d %H:%M:%S')"
echo "Machine        : $(hostname)"
echo "Dossier        : $(pwd)"
echo "Utilisateur    : $(id -un)"

ligne "1. Versions des outils"
php -v 2>/dev/null | head -1 || echo "php : ABSENT"
composer --version 2>/dev/null | head -1 || echo "composer : ABSENT"
node -v 2>/dev/null || echo "node : ABSENT"
npm -v 2>/dev/null || echo "npm : ABSENT"
python3 --version 2>/dev/null || echo "python3 : ABSENT"
python3.12 --version 2>/dev/null || echo "python3.12 : ABSENT (necessaire pour l'assistant IA)"
git --version 2>/dev/null || echo "git : ABSENT"

ligne "2. Version du code en place"
git log --oneline -5 2>/dev/null || echo "pas un depot git"
echo "--- branche : $(git branch --show-current 2>/dev/null || echo inconnue)"
echo "--- modifications non enregistrees :"
git status --short 2>/dev/null | head -30
echo "--- etiquettes de livraison connues :"
git tag -l 'livraison-*' 2>/dev/null | tail -5 || echo "aucune"

ligne "3. Presence des fonctionnalites"
echo "dispo BMN (bmn/daily_report.py)            : $(existe bmn/daily_report.py)"
echo "espace Secteurs (app/Models/Secteur.php)   : $(existe app/Models/Secteur.php)"
echo "assistant IA - service (assistant/service.py) : $(existe assistant/service.py)"
echo "assistant IA - dossiers (app/Models/AssistantDossier.php) : $(existe app/Models/AssistantDossier.php)"
echo "assistant IA - pieces jointes (app/Models/AssistantPieceJointe.php) : $(existe app/Models/AssistantPieceJointe.php)"
echo "banc navigateur (tests/navigateur/parcours_assistant.py) : $(existe tests/navigateur/parcours_assistant.py)"

ligne "4. Dependances installees"
echo "vendor/ (PHP)       : $(existe vendor)"
echo "node_modules/       : $(existe node_modules)"
echo "assistant/.venv/    : $(existe assistant/.venv)"
[ -d assistant/.venv ] && assistant/.venv/bin/python -m pip list 2>/dev/null | grep -iE 'chromadb|fastapi|uvicorn|requests|pypdf|docx' | head -8

ligne "5. Assets compiles (public/build)"
if [ -d public/build/assets ]; then
  ls -la public/build/assets | head -10
  echo "--- date du manifeste : $(stat -c '%y' public/build/manifest.json 2>/dev/null)"
  echo "--- rappel : si cette date est anterieure a la derniere livraison, lancer 'npm run build'"
else
  echo "public/build ABSENT -> l'interface restera sur l'ancienne version, les clics peuvent ne pas repondre"
fi

ligne "6. Base de donnees : migrations"
php artisan migrate:status 2>&1 | tail -25 || echo "migrate:status indisponible"
echo "--- rappel : toute ligne 'Pending' doit etre jouee avec 'php artisan migrate'"

ligne "7. Fichiers de reglages (valeurs masquees)"
for fichier in .env llm.env assistant/reglages.env bmn/reglages.env; do
  echo "--- $fichier : $(existe "$fichier")"
  if [ -f "$fichier" ]; then
    grep -vE '^\s*#' "$fichier" | grep -E '=' | while IFS='=' read -r cle valeur; do
      cle=$(echo "$cle" | tr -d ' ')
      case "$cle" in
        *CLE*|*KEY*|*JETON*|*TOKEN*|*PASSWORD*|*SECRET*)
          [ -n "$(echo "$valeur" | tr -d ' ')" ] && echo "    $cle = rempli" || echo "    $cle = VIDE" ;;
        *)
          echo "    $cle = ${valeur:-VIDE}" ;;
      esac
    done
  fi
done

ligne "8. Services"
for service in nh-laravel nh-queue nh-assistant nginx postgresql; do
  printf '%-14s : %s\n' "$service" "$(systemctl is-active "$service" 2>/dev/null || echo 'inconnu')"
done
for service in nh-laravel nh-queue nh-assistant; do
  echo "--- journal $service (10 dernieres lignes) :"
  journalctl -u "$service" -n 10 --no-pager 2>/dev/null || echo "journal indisponible"
done

ligne "9. Sante de l'assistant IA (si installe)"
if [ -f assistant/reglages.env ]; then
  HOTE=$(grep '^ASSISTANT_HOTE=' assistant/reglages.env | cut -d= -f2- | tr -d ' ')
  PORT=$(grep '^ASSISTANT_PORT=' assistant/reglages.env | cut -d= -f2- | tr -d ' ')
  JETON=$(grep '^ASSISTANT_JETON=' assistant/reglages.env | cut -d= -f2- | tr -d ' ')
  URL="http://${HOTE:-127.0.0.1}:${PORT:-8765}"
  echo "--- avec jeton (attendu : service ok / embedding ok / llm ok) :"
  curl -s -m 30 -H "X-Assistant-Jeton: $JETON" "$URL/sante" || echo "pas de reponse"
  echo ""
  echo "--- sans jeton (attendu : 401) : $(curl -s -m 10 -o /dev/null -w '%{http_code}' "$URL/formats")"
else
  echo "assistant/reglages.env absent : assistant IA pas encore mis en service"
fi

ligne "10. Journal de l'application (30 dernieres lignes)"
tail -30 storage/logs/laravel.log 2>/dev/null || echo "aucun journal"

ligne "11. Espace disque et memoire"
df -h . | tail -2
free -h 2>/dev/null | head -2

ligne "Fin du releve"
echo "Renvoyer ce fichier entier. Si un ecran pose probleme, joindre aussi une capture"
echo "de l'onglet Console du navigateur (touche F12) sur la page concernee."
