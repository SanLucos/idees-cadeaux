#!/usr/bin/env bash
#
# Lance tout l'environnement local : stack Docker (Postgres, PHP,
# Nginx, Mailpit, MinIO, worker) + serveur de dev du mobile, puis
# ouvre le navigateur. Voir CLAUDE.md > Commandes pour le détail de
# chaque commande si besoin de les lancer à la main.
#
# Usage : ./start-local.sh
# Arrêt : Ctrl+C (arrête le serveur de dev ; les conteneurs Docker
#         restent lancés en arrière-plan — "docker compose down" pour
#         tout arrêter).

set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")"

blue() { printf '\033[1;34m%s\033[0m\n' "$1"; }
red() { printf '\033[1;31m%s\033[0m\n' "$1" >&2; }

blue "=== Idées Cadeaux — démarrage de l'environnement local ==="

if ! command -v docker &> /dev/null; then
  red "Docker n'est pas installé (ou pas dans le PATH)."
  exit 1
fi
if ! docker info &> /dev/null; then
  red "Docker ne répond pas. Vérifie qu'il est lancé (Docker Desktop, ou le service docker)."
  exit 1
fi

if [ ! -f .env ]; then
  blue "→ Création de .env depuis .env.example"
  cp .env.example .env
fi
if [ ! -f mobile/.env ]; then
  blue "→ Création de mobile/.env depuis mobile/.env.example"
  cp mobile/.env.example mobile/.env
fi

BACKEND_PORT=$(grep -E '^BACKEND_LOCAL_PORT=' .env | cut -d= -f2)
BACKEND_PORT=${BACKEND_PORT:-8000}
MAILPIT_PORT=$(grep -E '^MAILPIT_UI_PORT=' .env | cut -d= -f2)
MAILPIT_PORT=${MAILPIT_PORT:-8026}
MINIO_CONSOLE_PORT=$(grep -E '^MINIO_CONSOLE_PORT=' .env | cut -d= -f2)
MINIO_CONSOLE_PORT=${MINIO_CONSOLE_PORT:-9001}

blue "→ Démarrage des conteneurs Docker (postgres, php, nginx, mailpit, minio, worker)..."
docker compose up -d

blue "→ Attente de la disponibilité de l'API..."
api_ready=false
for _ in $(seq 1 60); do
  if curl -sf "http://localhost:${BACKEND_PORT}/api/health" > /dev/null 2>&1; then
    api_ready=true
    break
  fi
  sleep 1
done
if [ "$api_ready" = true ]; then
  echo "  API prête sur http://localhost:${BACKEND_PORT}/api"
else
  red "  L'API ne répond pas encore après 60s — vérifie 'docker compose logs php nginx'."
fi

if [ ! -d mobile/node_modules ]; then
  blue "→ Installation des dépendances npm (première fois, peut prendre un moment)..."
  (cd mobile && npm install)
fi

# Ouvre le navigateur dès que le serveur de dev répond, en tâche de fond.
(
  for _ in $(seq 1 30); do
    if curl -sf "http://localhost:5173" > /dev/null 2>&1; then
      (xdg-open "http://localhost:5173" 2>/dev/null || open "http://localhost:5173" 2>/dev/null || true) &
      break
    fi
    sleep 1
  done
) &

blue "→ Lancement du serveur de développement (Ctrl+C pour arrêter)..."
echo ""
echo "   App mobile : http://localhost:5173"
echo "   API        : http://localhost:${BACKEND_PORT}/api"
echo "   Mailpit    : http://localhost:${MAILPIT_PORT} (codes de vérification par email)"
echo "   MinIO      : http://localhost:${MINIO_CONSOLE_PORT} (avatars)"
echo ""

cd mobile
exec npm run dev
