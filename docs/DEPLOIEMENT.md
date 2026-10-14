# Déploiement en production

L'appli web (point d'entrée principal, spec §11 décision 56) et le back (API `/api`, liens de partage `/u/…`, `/privacy`, images `/media/…`, liens des emails) tournent sur le serveur de Frigologie, sous `https://ideescadeaux.frigologie.fr`, derrière le Traefik de la stack Frigologie. L'image `nginx` contient l'appli web construite (étape `web` de `docker/php/Dockerfile.prod`, configuration `docker/nginx/prod.vhost.conf`) : elle est servie à la racine, et nginx passe à Symfony ses propres chemins.

Les deux stacks sont indépendantes. Leur seul point commun est le réseau Docker externe `proxy` : Traefik y est branché, notre `nginx` aussi, et les labels Traefik sont déclarés ici (`docker-compose.prod.yaml`). Rien d'Idées Cadeaux ne figure dans la config de Frigologie.

## Fichiers

- `docker-compose.prod.yaml` : la stack (postgres, backup, php, worker, nginx). Aucun port publié, logs Docker limités à 5 × 10 Mo par service.
- `docker/php/Dockerfile.prod` : images `php` (code + dépendances, `APP_ENV=prod`) et `nginx` (dossier `public/`).
- `docker/backup/backup.sh` : sauvegarde quotidienne de la base (service `backup`).
- `.env.prod` : secrets et réglages, sur le serveur uniquement (modèle : `.env.prod.example`).
- `deploy-prod.sh` : contrôle de `.env.prod`, build, clés JWT, migrations, démarrage, contrôle de `/api/health`.

## Stockage des images

Les images sont dans un **bucket S3 hébergé dans l'UE** (Scaleway ou OVH Object Storage), pas sur le serveur : MinIO ne publie plus d'images Docker (spec §11 décision 52).

1. Créer un bucket **privé** (aucun accès public : le back sert les images par des URL signées).
2. Créer une clé d'API limitée à ce bucket (lecture, écriture, suppression).
3. Renseigner `STORAGE_ENDPOINT`, `STORAGE_REGION`, `STORAGE_BUCKET`, `STORAGE_KEY`, `STORAGE_SECRET` dans `.env.prod`.

La durabilité du stockage est assurée par l'hébergeur. Si le versionnage du bucket est activé, ajouter une règle de cycle de vie qui supprime les versions non courantes après 30 jours (spec §5.13 : une image supprimée ne doit pas survivre plus de 30 jours).

## Première installation

1. DNS : enregistrement `ideescadeaux.frigologie.fr` vers le serveur.
2. Frigologie déployé avec le réseau `proxy` (créé par sa CI, sinon `docker network create proxy`).
3. Bucket S3 et clé (ci-dessus).
4. Sur le serveur : cloner le dépôt, copier `.env.prod.example` en `.env.prod`, remplir les valeurs (`openssl rand -hex 32` pour `APP_SECRET` et les mots de passe). Le script refuse de déployer si un secret est vide ou si `APP_SECRET` fait moins de 32 caractères.
5. Dossier de données : `DATA_DIR` (par défaut `/home/deploy/ideescadeaux-data`, créé par le script, sans droits administrateur).
6. `./deploy-prod.sh`

Le certificat Let's Encrypt est demandé par Traefik à la première requête.

## Mise à jour

**Automatique, par tag** (`.github/workflows/deploy.yml`) : pousser un tag de version sur un commit de `main`.

```
git tag v1.2.0
git push origin v1.2.0
```

GitHub Actions relance toute la CI, vérifie que le tag est sur `main`, puis sur le serveur : récupère le tag, le place (`git checkout --detach`) et lance `./deploy-prod.sh`. Un seul déploiement à la fois ; le suivi est dans l'onglet Actions du dépôt (environnement `production`).

Mise en place, une seule fois : une clé SSH dédiée autorisée pour `deploy` sur le serveur (`~/.ssh/authorized_keys`), et deux secrets du dépôt GitHub (Settings → Secrets and variables → Actions) : `DEPLOY_SSH_KEY` (la clé privée) et `DEPLOY_SSH_KNOWN_HOSTS` (sortie de `ssh-keyscan -p 1138 frigologie.fr`).

**À la main**, si besoin :

```
ssh -p 1138 deploy@frigologie.fr 'cd ~/ideescadeaux && git fetch --tags --force origin && git checkout --force --detach origin/main && ./deploy-prod.sh'
```

Les migrations passent **avant** le redémarrage : le nouveau code ne tourne jamais sur un schéma ancien.

## Sauvegardes

- Le service `backup` écrit chaque jour `DATA_DIR/backups/db-AAAA-MM-JJ.dump` (`pg_dump`, format custom) et supprime ceux de plus de 30 jours (spec §5.13).
- Les dumps restent sur le serveur : les copier aussi ailleurs (autre machine ou bucket), avec la même rétention de 30 jours.
- `DATA_DIR/jwt` (clés JWT) : à sauvegarder une fois ; sans elles, tout le monde doit se reconnecter.
- Ne pas copier `DATA_DIR/postgres` à chaud : ce n'est pas une sauvegarde cohérente.

**Restaurer** un dump (la base est remplacée) :

```
P="docker compose -p ideescadeaux -f docker-compose.prod.yaml --env-file .env.prod"
$P stop php worker
$P exec backup sh -c 'dropdb --if-exists "$PGDATABASE" && createdb "$PGDATABASE" && pg_restore --dbname="$PGDATABASE" --no-owner /backups/db-AAAA-MM-JJ.dump'
$P start php worker
```

## Appli web

Rien à faire en plus : `./deploy-prod.sh` la construit et la publie avec le reste. La connexion y est gardée dans un cookie `httpOnly` (jamais lisible par le JavaScript), l'appli ne garde aucune copie locale et fonctionne en ligne uniquement.

## Appli mobile (Android / iOS)

Les builds natifs utilisent l'adresse complète de l'API, dans `mobile/.env.native` (déjà réglée sur ce serveur) :

```
npm run build -- --mode native && npx cap sync
``` Pour que les liens `https://ideescadeaux.frigologie.fr/u/…` ouvrent l'appli :

- Android : propriété Gradle `shareLinkHost=ideescadeaux.frigologie.fr` (`mobile/android/gradle.properties`), et l'empreinte SHA-256 du certificat de signature dans `ANDROID_CERT_FINGERPRINTS` (`.env.prod`).
- iOS : capacité *Associated Domains* `applinks:ideescadeaux.frigologie.fr` sur la cible `App`, et `APPLE_TEAM_ID` dans `.env.prod`.

## À savoir

- Les noms de routeurs Traefik sont partagés par tout le serveur : garder le préfixe `ideescadeaux`.
- Traefik expose par défaut tous les conteneurs du serveur : tout nouveau service interne doit porter `traefik.enable=false`.
- `CORS_ALLOW_ORIGIN` garde sa valeur par défaut (`backend/.env`), qui autorise les WebViews natives (`https://localhost`, `capacitor://localhost`).
- Logs : JSON sur la sortie standard des conteneurs (`docker compose … logs php worker`), avec un `request_id` par requête ; les erreurs remontées par l'appli sont sur le canal `client`.
