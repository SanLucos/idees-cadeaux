#!/usr/bin/env bash
#
# Déploie (ou met à jour) la stack de production sur le serveur : contrôle
# de .env.prod, build des images, clés JWT, migrations, puis démarrage.
# Voir docs/DEPLOIEMENT.md.
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

value() { grep -E "^$1=" .env.prod | tail -1 | cut -d= -f2- | sed -E 's/^"(.*)"$/\1/'; }

# Un secret vide ne fait pas échouer Symfony, mais rendrait les liens
# signés (images, annulation de suppression, désinscription) fabricables.
missing=()
for name in APP_HOST TRAEFIK_NETWORK DATA_DIR POSTGRES_PASSWORD APP_SECRET JWT_PASSPHRASE MAILER_DSN \
            STORAGE_ENDPOINT STORAGE_REGION STORAGE_BUCKET STORAGE_KEY STORAGE_SECRET; do
  [ -n "$(value "$name")" ] || missing+=("$name")
done
if [ ${#missing[@]} -gt 0 ]; then
  red "À remplir dans .env.prod : ${missing[*]}"
  exit 1
fi
if [ "$(value APP_SECRET | wc -c)" -lt 33 ]; then
  red "APP_SECRET doit faire au moins 32 caractères (openssl rand -hex 32)."
  exit 1
fi

DATA_DIR=$(value DATA_DIR)
TRAEFIK_NETWORK=$(value TRAEFIK_NETWORK)
PROJECT=${COMPOSE_PROJECT_NAME:-ideescadeaux}

if ! docker network inspect "$TRAEFIK_NETWORK" > /dev/null 2>&1; then
  red "Le réseau Docker '$TRAEFIK_NETWORK' n'existe pas : docker network create $TRAEFIK_NETWORK"
  exit 1
fi

mkdir -p "$DATA_DIR/postgres" "$DATA_DIR/jwt" "$DATA_DIR/backups"

compose() {
  docker compose -p "$PROJECT" -f docker-compose.prod.yaml --env-file .env.prod "$@"
}

blue "→ Build des images"
compose build

blue "→ Base de données"
compose up -d postgres
for _ in $(seq 1 60); do
  if compose exec -T postgres pg_isready -q; then break; fi
  sleep 1
done

blue "→ Clés JWT (générées une seule fois)"
compose run --rm -T --no-deps --user root php sh -c '
  chown www-data:www-data config/jwt
  su www-data -s /bin/sh -c "php bin/console lexik:jwt:generate-keypair --skip-if-exists"
'

# Avant de démarrer le nouveau code : il ne tourne jamais sur un schéma ancien.
blue "→ Migrations"
compose run --rm -T --no-deps php php bin/console doctrine:migrations:migrate --no-interaction --all-or-nothing

blue "→ Démarrage des conteneurs"
compose up -d --remove-orphans

blue "→ Vérification"
for _ in $(seq 1 30); do
  if compose exec -T nginx wget -qO- http://127.0.0.1/api/health 2> /dev/null; then echo; exit 0; fi
  sleep 1
done
red "L'API ne répond pas : docker compose -p $PROJECT -f docker-compose.prod.yaml logs php nginx"
exit 1
