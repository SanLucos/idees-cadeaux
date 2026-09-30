#!/usr/bin/env bash
#
# Déploie (ou met à jour) la stack de production sur le serveur : build
# des images, démarrage, clés JWT, migrations. Voir docs/DEPLOIEMENT.md.
#
# Usage : ./deploy-prod.sh   (depuis un clone du dépôt, avec un .env.prod)

set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")"

red() { printf '\033[1;31m%s\033[0m\n' "$1" >&2; }
blue() { printf '\033[1;34m%s\033[0m\n' "$1"; }

if [ ! -f .env.prod ]; then
  red ".env.prod introuvable : copier .env.prod.example et remplir les valeurs."
  exit 1
fi

DATA_DIR=$(grep -E '^DATA_DIR=' .env.prod | cut -d= -f2)
TRAEFIK_NETWORK=$(grep -E '^TRAEFIK_NETWORK=' .env.prod | cut -d= -f2)
PROJECT=${COMPOSE_PROJECT_NAME:-ideescadeaux}

if ! docker network inspect "$TRAEFIK_NETWORK" > /dev/null 2>&1; then
  red "Le réseau Docker '$TRAEFIK_NETWORK' n'existe pas : docker network create $TRAEFIK_NETWORK"
  exit 1
fi

mkdir -p "$DATA_DIR/postgres" "$DATA_DIR/minio" "$DATA_DIR/jwt"

compose() {
  docker compose -p "$PROJECT" -f docker-compose.prod.yaml --env-file .env.prod "$@"
}

blue "→ Build des images"
compose build

blue "→ Démarrage des conteneurs"
compose up -d --remove-orphans

blue "→ Clés JWT (générées une seule fois)"
compose exec -T --user root php sh -c '
  chown www-data:www-data config/jwt
  su www-data -s /bin/sh -c "php bin/console lexik:jwt:generate-keypair --skip-if-exists"
'

blue "→ Migrations"
# Postgres peut mettre quelques secondes à accepter les connexions.
for _ in $(seq 1 30); do
  if compose exec -T postgres pg_isready -q; then break; fi
  sleep 1
done
compose exec -T php php bin/console doctrine:migrations:migrate --no-interaction --all-or-nothing

blue "→ Vérification"
compose exec -T nginx wget -qO- http://127.0.0.1/api/health && echo
