# Déploiement en production

Le back (API, page invité `/u/…`, `/privacy`, images `/media/…`) tourne sur le serveur de Frigologie, sous `https://ideescadeaux.frigologie.fr`, derrière le Traefik de la stack Frigologie.

Les deux stacks sont indépendantes. Leur seul point commun est le réseau Docker externe `proxy` : Traefik y est branché, notre `nginx` aussi, et les labels Traefik sont déclarés ici (`docker-compose.prod.yaml`). Rien d'Idées Cadeaux ne figure dans la config de Frigologie.

## Fichiers

- `docker-compose.prod.yaml` : la stack (postgres, minio, php, worker, nginx). Aucun port publié.
- `docker/php/Dockerfile.prod` : images `php` (code + dépendances, `APP_ENV=prod`) et `nginx` (dossier `public/`).
- `.env.prod` : secrets et réglages, sur le serveur uniquement (modèle : `.env.prod.example`).
- `deploy-prod.sh` : build, démarrage, clés JWT, migrations, contrôle de `/api/health`.

## Première installation

1. DNS : enregistrement `ideescadeaux.frigologie.fr` vers le serveur.
2. Frigologie déployé avec le réseau `proxy` (créé par sa CI, sinon `docker network create proxy`).
3. Sur le serveur : cloner le dépôt, copier `.env.prod.example` en `.env.prod`, remplir les valeurs.
4. Créer le dossier de données (`DATA_DIR`, par défaut `/srv/ideescadeaux`) avec les droits d'écriture pour l'utilisateur de déploiement.
5. `./deploy-prod.sh`

Le certificat Let's Encrypt est demandé par Traefik à la première requête.

## Mise à jour

```
git pull && ./deploy-prod.sh
```

## Données

Postgres, MinIO et les clés JWT sont dans `DATA_DIR`, en dossiers de l'hôte : ils ne dépendent d'aucun volume Docker et survivent à un `docker volume prune`. C'est ce dossier qu'il faut sauvegarder.

## À savoir

- Les noms de routeurs Traefik sont partagés par tout le serveur : garder le préfixe `ideescadeaux`.
- Traefik expose par défaut tous les conteneurs du serveur : tout nouveau service interne doit porter `traefik.enable=false`.
- `CORS_ALLOW_ORIGIN` garde sa valeur par défaut (`backend/.env`), qui autorise les WebViews natives (`https://localhost`, `capacitor://localhost`).
