#!/usr/bin/env python3
"""Banc de tests navigateur de cAIman (Playwright + Chrome du système).

Pourquoi : la suite Pest ne lance aucun navigateur. Une régression côté JavaScript (par exemple
une seconde instance d'Alpine qui empêche Livewire de s'initialiser : plus aucun clic ne
fonctionne) passe donc à travers tous les tests. Ce banc pilote un vrai Chrome sur un vrai
serveur PHP et rejoue les parcours de l'Assistant IA.

Garde-fous :
  - la base utilisée est TOUJOURS la base de test (mêmes paramètres que phpunit.xml) ; le script
    refuse de continuer s'il ne peut pas le vérifier ;
  - le serveur PHP est le sien (port 8099), arrêté à la fin quoi qu'il arrive ; le site de
    développement (port 8000) n'est jamais touché ;
  - le service d'assistant (donc le modèle de langage payant) est remplacé par un faux serveur
    local qui laisse les questions sans réponse : aucun appel réseau réel.

Lancement : scripts/tests-navigateur.sh
"""
import os
import re
import signal
import socket
import subprocess
import sys
import threading
import time
import urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

from playwright.sync_api import sync_playwright, expect

RACINE = Path(__file__).resolve().parents[2]
PORT_APP = 8099
PORT_FAUX_ASSISTANT = 8098
URL = f"http://127.0.0.1:{PORT_APP}"
EMAIL = "banc-navigateur@example.test"
MOT_DE_PASSE = "banc-navigateur-mdp-42"
NOM_DOCUMENT = "banc-navigateur.pdf"
NOM_DOSSIER = "Banc navigateur - dossier"

# Même base que phpunit.xml : jamais la base de développement (distante, données réelles).
ENV_TEST = {
    "APP_ENV": "local",
    "APP_URL": URL,
    "DB_CONNECTION": "pgsql",
    "DB_HOST": "127.0.0.1",
    "DB_PORT": "5432",
    "DB_DATABASE": "nh_project_test",
    "DB_USERNAME": "nh_user",
    "DB_PASSWORD": "nh_password",
    "DB_SSLMODE": "prefer",
    "QUEUE_CONNECTION": "sync",
    "ASSISTANT_REGLAGES": "aucun",
    "ASSISTANT_HOTE": "127.0.0.1",
    "ASSISTANT_PORT": str(PORT_FAUX_ASSISTANT),
    "ASSISTANT_JETON": "banc-navigateur",
    # Le serveur intégré de PHP traite une requête à la fois : la question du chat reste en
    # attente de réponse, il faut donc plusieurs processus pour continuer à naviguer.
    "PHP_CLI_SERVER_WORKERS": "4",
}


def env_php():
    env = dict(os.environ)
    env.update(ENV_TEST)
    return env


def artisan(*args, **kw):
    return subprocess.run(["php", "artisan", *args], cwd=RACINE, env=env_php(),
                          capture_output=True, text=True, **kw)


def tinker(code):
    """Exécute du PHP dans l'application (base de test) et renvoie la sortie."""
    r = artisan("tinker", "--execute", code)
    if r.returncode != 0:
        raise RuntimeError(f"tinker a échoué : {r.stdout}\n{r.stderr}")
    return r.stdout.strip()


def verifier_base_de_test():
    sortie = tinker("echo 'BASE='.DB::connection()->getDatabaseName().'@'.config('database.connections.pgsql.host');")
    if "BASE=nh_project_test@127.0.0.1" not in sortie:
        raise RuntimeError(f"REFUS : la base utilisée n'est pas la base de test ({sortie!r}).")


def preparer_donnees():
    r = artisan("migrate", "--force")
    if r.returncode != 0:
        raise RuntimeError(f"migrate a échoué : {r.stdout}\n{r.stderr}")
    nettoyer_donnees()  # repart propre si un lancement précédent a été interrompu
    # Le secteur BMN est créé par la migration create_secteurs_table ; on le recrée au besoin.
    tinker(f"""
        $s = App\\Models\\Secteur::firstOrCreate(['slug' => 'bmn'], ['nom' => 'BMN']);
        $u = App\\Models\\User::factory()->create(['email' => '{EMAIL}', 'password' => '{MOT_DE_PASSE}']);
        $s->users()->attach($u->id, ['role' => 'chef']);
        // Un document « prêt » (fictif, sans fichier) : sans lui l'accueil du chat n'affiche pas de suggestions.
        App\\Models\\AssistantDocument::create(['secteur_id' => $s->id, 'nom' => '{NOM_DOCUMENT}', 'chemin' => 'assistant/documents/bmn/banc-navigateur.pdf',
            'taille' => 1, 'format' => 'pdf', 'statut' => App\\Models\\AssistantDocument::PRET]);
    """)


def nettoyer_donnees():
    """Supprime tout ce que le banc a créé (utilisateur, conversations, dossiers, sessions)."""
    tinker(f"""
        $u = App\\Models\\User::where('email', '{EMAIL}')->first();
        $s = App\\Models\\Secteur::where('slug', 'bmn')->first();
        App\\Models\\AssistantDocument::where('nom', '{NOM_DOCUMENT}')->delete();
        if ($s) {{ App\\Models\\AssistantDossier::where('secteur_id', $s->id)->where('nom', '{NOM_DOSSIER}')->delete(); }}
        if ($u) {{
            App\\Models\\AssistantConversation::where('user_id', $u->id)->get()->each->delete();
            foreach (['sessions', 'audit_logs'] as $t) {{
                if (Illuminate\\Support\\Facades\\Schema::hasTable($t) && Illuminate\\Support\\Facades\\Schema::hasColumn($t, 'user_id')) {{
                    DB::table($t)->where('user_id', $u->id)->delete();
                }}
            }}
            $u->secteurs()->detach();
            $u->delete();
        }}
    """)


class FauxAssistant(BaseHTTPRequestHandler):
    """Remplace le service nh-assistant : /formats répond, /questions n'aboutit jamais."""

    def log_message(self, *a):
        pass

    def _json(self, corps=b'{"formats":["pdf","docx","txt"]}'):
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(corps)))
        self.end_headers()
        self.wfile.write(corps)

    def do_GET(self):
        self._json()

    def do_DELETE(self):
        self._json(b"{}")

    def do_POST(self):
        self.rfile.read(int(self.headers.get("Content-Length") or 0))
        if self.path.startswith("/questions"):
            time.sleep(30)  # la réponse ne vient pas : on reste sur « l'assistant réfléchit »
            return
        self._json(b"{}")


def port_libre(port):
    with socket.socket() as s:
        return s.connect_ex(("127.0.0.1", port)) != 0


def attendre_serveur(proc, delai=40):
    fin = time.time() + delai
    while time.time() < fin:
        if proc.poll() is not None:
            raise RuntimeError("le serveur PHP s'est arrêté au démarrage")
        try:
            urllib.request.urlopen(URL + "/login", timeout=2)
            return
        except Exception:
            time.sleep(0.5)
    raise RuntimeError("le serveur PHP ne répond pas")


class Resultats:
    def __init__(self):
        self.lignes = []

    def etape(self, libelle, fonction):
        try:
            fonction()
            self.lignes.append((True, libelle, ""))
        except Exception as e:  # noqa: BLE001 - on veut tout attraper pour continuer le rapport
            detail = str(e).strip().splitlines()[0] if str(e).strip() else type(e).__name__
            self.lignes.append((False, libelle, detail))
            raise

    def afficher(self):
        print("\n=== Banc de tests navigateur : résumé ===")
        for ok, libelle, detail in self.lignes:
            print(f"  [{'OK' if ok else 'ÉCHEC'}] {libelle}" + (f"\n         -> {detail}" if detail else ""))
        return all(ok for ok, _, _ in self.lignes)


def parcours(page, erreurs_console, res):
    def connexion():
        page.goto(URL + "/login")
        page.fill("#email", EMAIL)
        page.fill("#password", MOT_DE_PASSE)
        page.get_by_role("button", name=re.compile("connexion|connecter|log in", re.I)).click()
        page.wait_for_url(lambda u: "/login" not in u, timeout=15000)

    res.etape("Connexion par le formulaire", connexion)

    # ---- Parcours chat --------------------------------------------------------------------
    def ouvrir_assistant():
        page.goto(f"{URL}/secteurs/bmn/assistant")
        page.get_by_role("link", name="Assistant IA").first.wait_for()
        expect(page.locator("textarea[aria-label='Votre question']")).to_be_visible()
        # Laisse à Livewire et Alpine le temps de démarrer : c'est là que la régression se voit.
        page.wait_for_function("window.Livewire && window.Alpine", timeout=10000)
        page.wait_for_timeout(1000)

    res.etape("Onglet Assistant IA s'ouvre", ouvrir_assistant)

    def console_propre():
        if erreurs_console:
            raise AssertionError("erreurs de console : " + " | ".join(erreurs_console[:3]))

    res.etape("Aucune erreur de console (ni double Alpine)", console_propre)

    zone = page.locator("textarea[aria-label='Votre question']")

    def suggestion():
        bouton = page.locator("button[wire\\:click^=\"$set('question'\"]").first
        bouton.wait_for(timeout=5000)
        bouton.click()
        expect(zone).not_to_have_value("", timeout=8000)

    res.etape("Clic sur une suggestion remplit la zone de saisie", suggestion)

    def envoi():
        zone.fill("Question du banc de test : ne pas répondre.")
        page.locator("form[wire\\:submit='envoyer'] button[type=submit]").click()
        expect(page.get_by_text("L'assistant réfléchit")).to_be_visible(timeout=8000)
        expect(zone).to_have_value("", timeout=8000)

    res.etape("Envoi d'une question : zone vidée et « l'assistant réfléchit » affiché", envoi)

    # ---- Parcours documents ---------------------------------------------------------------
    def ouvrir_documents():
        page.goto(f"{URL}/secteurs/bmn/documents")
        page.wait_for_function("window.Livewire && window.Alpine", timeout=10000)
        expect(page.get_by_text("Documents du secteur").first).to_be_visible()

    res.etape("Onglet Documents s'ouvre", ouvrir_documents)

    def creer_dossier():
        page.get_by_role("button", name=re.compile("Nouveau dossier")).click()
        page.get_by_placeholder("Nom du dossier").fill(NOM_DOSSIER)
        page.get_by_role("button", name="Créer").click()
        expect(page.get_by_placeholder("Nom du dossier")).to_be_hidden(timeout=8000)
        # Arborescence (colonne de gauche, seule carte à défilement vertical avec padding p-2).
        arbre = page.locator("div.p-2.overflow-y-auto")
        expect(arbre.get_by_role("button", name=re.compile(re.escape(NOM_DOSSIER)))).to_be_visible(timeout=8000)

    res.etape("Création d'un dossier : il apparaît dans l'arborescence", creer_dossier)

    def ouvrir_dossier():
        page.locator("tr", has_text=NOM_DOSSIER).get_by_role("button", name=re.compile("📁")).click()
        # Fil d'Ariane : « Documents › nom du dossier », nom en gras (dossier ouvert).
        ariane = page.locator("span:text-is('›') + button", has_text=NOM_DOSSIER)
        expect(ariane).to_be_visible(timeout=8000)

    res.etape("Ouverture du dossier : il apparaît dans le fil d'Ariane", ouvrir_dossier)

    def supprimer_dossier():
        # Retour à la racine, puis suppression depuis la liste (confirmation acceptée plus bas).
        page.get_by_role("button", name="Documents", exact=True).first.click()
        ligne = page.locator("tr", has_text=NOM_DOSSIER)
        expect(ligne).to_be_visible(timeout=8000)
        ligne.get_by_title("Supprimer le dossier").click()
        expect(page.locator("tr", has_text=NOM_DOSSIER)).to_have_count(0, timeout=8000)

    res.etape("Suppression du dossier", supprimer_dossier)

    def console_propre_fin():
        console_propre()

    res.etape("Toujours aucune erreur de console en fin de parcours", console_propre_fin)


def main():
    if not port_libre(PORT_APP) or not port_libre(PORT_FAUX_ASSISTANT):
        print(f"Les ports {PORT_APP} et {PORT_FAUX_ASSISTANT} doivent être libres.")
        return 1

    res = Resultats()
    serveur = None
    faux = None
    donnees_creees = False
    try:
        verifier_base_de_test()
        print("Base de test vérifiée (nh_project_test). Préparation des données…")
        preparer_donnees()
        donnees_creees = True

        faux = ThreadingHTTPServer(("127.0.0.1", PORT_FAUX_ASSISTANT), FauxAssistant)
        faux.daemon_threads = True
        threading.Thread(target=faux.serve_forever, daemon=True).start()

        serveur = subprocess.Popen(
            ["php", "artisan", "serve", "--no-reload", "--host=127.0.0.1", f"--port={PORT_APP}"],
            cwd=RACINE, env=env_php(), stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
            start_new_session=True)  # groupe de processus à part : on l'arrête en bloc
        attendre_serveur(serveur)
        print(f"Serveur de test prêt sur {URL}. Lancement de Chrome…")

        with sync_playwright() as p:
            navigateur = p.chromium.launch(channel="chrome", headless=True, args=["--no-sandbox"])
            try:
                page = navigateur.new_context(locale="fr-FR").new_page()
                page.set_default_timeout(10000)
                page.on("dialog", lambda d: d.accept())  # wire:confirm
                erreurs = []
                page.on("console", lambda m: erreurs.append(m.text) if m.type in ("error", "warning") else None)
                page.on("pageerror", lambda e: erreurs.append(f"exception JS : {e}"))
                try:
                    parcours(page, erreurs, res)
                finally:
                    if res.lignes and not res.lignes[-1][0]:
                        capture = RACINE / "storage" / "logs" / "banc-navigateur-echec.png"
                        try:
                            page.screenshot(path=str(capture))
                            print(f"Capture de l'échec : {capture}")
                        except Exception:
                            pass
            finally:
                navigateur.close()
    except Exception as e:  # noqa: BLE001
        if not res.lignes or res.lignes[-1][0]:
            res.lignes.append((False, "Exécution du banc", str(e).strip().splitlines()[0] if str(e).strip() else repr(e)))
    finally:
        if serveur is not None:
            try:
                os.killpg(serveur.pid, signal.SIGTERM)
                serveur.wait(timeout=10)
            except Exception:
                try:
                    os.killpg(serveur.pid, signal.SIGKILL)
                except Exception:
                    pass
        if faux is not None:
            faux.shutdown()
        if donnees_creees:
            try:
                nettoyer_donnees()
                print("Données du banc supprimées de la base de test.")
            except Exception as e:  # noqa: BLE001
                print(f"ATTENTION : nettoyage incomplet ({e})")

    ok = res.afficher()
    print("\nRésultat : " + ("TOUT PASSE" if ok else "ÉCHEC"))
    return 0 if ok else 1


if __name__ == "__main__":
    sys.exit(main())
