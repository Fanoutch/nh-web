#!/usr/bin/env bash
# Verification du miroir pip pour l'assistant IA (poste du bureau, reseau ferme).
#
# A lancer DEPUIS LA RACINE du projet du bureau (/home/coder/nh-web1) :
#     bash verifier-miroir-assistant.sh > verif-miroir.txt 2>&1
#
# Ne modifie RIEN dans le projet et n'installe RIEN : pip travaille en mode
# "--dry-run" dans un environnement temporaire supprime a la fin (quelques Mo).
# Renvoyer le FICHIER verif-miroir.txt (pas un copier-coller).

set -u
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
ligne() { printf '\n===== %s =====\n' "$1"; }

echo "Verification miroir - $(date '+%Y-%m-%d %H:%M:%S') - $(hostname)"

ligne "1. Place disponible"
df -h /home/coder 2>/dev/null | tail -1 || df -h . | tail -1
echo "(l'environnement Python de l'assistant pese environ 430 Mo)"

ligne "2. Miroir"
python3.12 -m pip config list 2>/dev/null | grep -i index || echo "aucun index configure"
python3.12 -m venv "$TMP/venv" || { echo "!!! creation d'un environnement Python impossible"; exit 1; }
PIP="$TMP/venv/bin/python -m pip"

cat > "$TMP/requirements.lock" <<'LOCK'
annotated-doc==0.0.4
annotated-types==0.8.0
anyio==4.15.1
asgiref==3.11.1
backoff==2.2.1
bcrypt==5.0.0
build==1.4.0
certifi==2026.7.22
charset-normalizer==3.5.1
chroma-hnswlib==0.7.6
chromadb==0.5.13
click==8.5.0
distro==1.9.0
durationpy==0.11
fastapi==0.136.3
filelock==4.0.0
flatbuffers==25.12.19
fsspec==2026.7.0
googleapis-common-protos==1.75.0
grpcio==1.81.1
h11==0.16.0
hf-xet==1.6.0
httpcore==1.0.9
httptools==0.8.0
httpx==0.28.1
huggingface_hub==1.32.0
idna==3.20
importlib_resources==6.5.2
iniconfig==2.3.0
kubernetes==35.0.0
lxml==6.1.1
markdown-it-py==4.2.0
mdurl==0.1.2
mmh3==5.2.0
numpy==1.26.4
oauthlib==3.3.1
onnxruntime==1.30.0
opentelemetry-api==1.42.1
opentelemetry-exporter-otlp-proto-common==1.42.1
opentelemetry-exporter-otlp-proto-grpc==1.42.1
opentelemetry-instrumentation==0.63b1
opentelemetry-instrumentation-asgi==0.63b1
opentelemetry-instrumentation-fastapi==0.63b1
opentelemetry-proto==1.42.1
opentelemetry-sdk==1.42.1
opentelemetry-semantic-conventions==0.63b1
opentelemetry-util-http==0.63b1
orjson==3.11.9
overrides==7.7.0
packaging==26.3
pluggy==1.6.0
posthog==5.4.0
protobuf==6.33.6
pydantic==2.13.5
pydantic_core==2.46.5
Pygments==2.21.0
pypdf==6.1.3
PyPika==0.50.0
pyproject_hooks==1.2.0
pytest==8.4.2
python-dateutil==2.9.0.post0
python-docx==1.2.0
python-dotenv==1.2.2
PyYAML==6.0.3
requests==2.32.3
requests-oauthlib==2.0.0
rich==15.0.0
shellingham==1.5.4
six==1.17.0
starlette==1.6.0
tenacity==9.1.4
tokenizers==0.23.2
tqdm==4.70.1
typer==0.26.8
typing-inspection==0.4.4
typing_extensions==4.16.0
urllib3==2.8.0
uvicorn==0.42.0
uvloop==0.22.1
watchfiles==1.2.0
websocket-client==1.9.0
websockets==17.0.1
wrapt==2.3.0
LOCK
cat > "$TMP/requirements.txt" <<'TXT'
fastapi==0.136.3
uvicorn==0.42.0
httpx==0.28.1
chromadb==0.5.13
numpy==1.26.4
pypdf==6.1.3
python-docx==1.2.0
requests==2.32.3
pytest==8.4.2
TXT

ligne "3. Les 3 versions jamais vues sur le miroir"
for p in distro durationpy protobuf; do
    echo "$p : $($PIP index versions --pre "$p" 2>/dev/null | grep '^Available' | cut -d: -f2 | cut -c1-120)"
done

ligne "4. Installation simulee de requirements.lock (versions exactes)"
if $PIP install --dry-run --ignore-installed --quiet -r "$TMP/requirements.lock" > "$TMP/a.log" 2>&1; then
    echo "RESULTAT : OK - requirements.lock s'installe tel quel depuis le miroir."
else
    echo "RESULTAT : ECHEC - details :"
    tail -8 "$TMP/a.log"

    ligne "5. Ce que le miroir propose a la place (resolution libre des dependances)"
    if $PIP install --dry-run --ignore-installed --quiet --report "$TMP/r.json" -r "$TMP/requirements.txt" > "$TMP/b.log" 2>&1; then
        "$TMP/venv/bin/python" - "$TMP/r.json" <<'PY'
import json, sys
paquets = json.load(open(sys.argv[1]))["install"]
for p in sorted(paquets, key=lambda p: p["metadata"]["name"].lower()):
    print(f'{p["metadata"]["name"]}=={p["metadata"]["version"]}')
print(f"=> {len(paquets)} paquets")
PY
    else
        echo "ECHEC de la resolution :"; tail -10 "$TMP/b.log"
    fi
fi

ligne "FIN"
