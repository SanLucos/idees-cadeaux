# CLAUDE.md — Application « Idées Cadeaux »

Application mobile (iOS/Android) de listes d'idées cadeaux entre amis. Back : Symfony + API Platform. Front : Ionic Vue + Capacitor.

## Source de vérité

**`docs/SPECIFICATIONS.md`** (version 0.5). Lis-le en entier avant de coder. La section 11 liste les décisions prises : ne pas y déroger. Si un point est ambigu ou contredit une décision, **pose la question** plutôt que d'inventer, puis consigne la réponse dans la section 11.

## Règles non négociables

1. **Règle d'or de la surprise** : le propriétaire d'une liste ne doit jamais voir ni déduire les suggestions, réservations, commentaires, réactions et cotisations faites par ses amis sur ses idées. Filtrage **côté serveur**, sur tous les canaux (API, compteurs, notifications, `/sync`, cache local, export RGPD, erreurs). Un objet caché renvoie **404 / collection vide**, jamais 403. Seule exception : le gestionnaire d'un profil enfant (section 5.15).
2. **Idées privées** : visibles de leur seul auteur, partout (API, `/sync`, notifications, compteurs, listes d'amis).
3. **Montants individuels d'une cotisation** : visibles de leur auteur et de l'initiateur uniquement, jamais dans une notification.
4. **Refus d'ami silencieux** : le statut `declined` n'est jamais exposé au demandeur.
5. **Suite de tests de visibilité** (section 4) : obligatoire, verte à chaque lot. Toute nouvelle ressource cachée ou privée y ajoute ses cas.
6. **Aucun paiement réel**, aucune donnée bancaire, aucun contact du téléphone stocké côté serveur (correspondance par emails hachés uniquement).
7. **i18n dès le départ** : aucun texte en dur, `vue-i18n`, français par défaut. Emails et push traduits selon `User.locale`. L'API renvoie des codes d'erreur stables (`problem+json`).
8. **Nom de l'appli et identifiant d'app en configuration** (`capacitor.config.ts`, variables d'environnement, clé i18n `app.name`) : jamais en dur. Le nom est provisoire.
9. **Conventions de données** : UUID v7 pouvant être générés par le client, `createdAt` / `updatedAt` / `deletedAt` (suppression logique) sur toutes les entités, écritures idempotentes. Ce sont les fondations du hors-ligne.
10. **Aperçu de lien** : protection SSRF obligatoire (section 5.5).
11. **Vue invité** (lien de partage, section 5.16) : publique et en lecture seule, uniquement pseudo, avatar et idées personnelles publiées. Jamais d'interaction, de donnée privée ni de donnée de profil. Un utilisateur retiré par le propriétaire ne peut pas rejoindre par le lien.

## Stack

- **Back** (`/backend`) : PHP, Symfony (dernière LTS), API Platform (dernière stable), PostgreSQL, Doctrine + migrations, Messenger, Mailer, FCM pour le push, stockage objet compatible S3, JWT court + refresh token à rotation.
- **Front** (`/mobile`) : Ionic Vue + Capacitor, Vue 3, TypeScript, Pinia, Vue Router, `vue-i18n`, SQLite locale pour le hors-ligne.
- **Docs** (`/docs`) : `SPECIFICATIONS.md`.
- Dev local via Docker Compose. CI : lint + tests des deux parties.

## Méthode de travail

- Avance **lot par lot**, dans l'ordre de la section 12 : 0, 1, 2, 3, 4, 4 bis, 5, 6, 7, 7 bis, 8.
- Ne passe pas au lot suivant si la CI ou la suite de visibilité est rouge.
- Avant chaque lot : relis les sections concernées, propose un plan court, puis implémente.
- Écris les tests avec le code (PHPUnit et client de test API Platform côté back, Vitest côté front).
- Commits atomiques, messages clairs. Migrations Doctrine versionnées.
- N'ajoute une dépendance que si elle est nécessaire, et dis pourquoi.
- Le lot 0 doit déjà prévoir le modèle des profils gérés (`User.type`, `managedBy`, en-tête `X-Acting-As`), même si la fonction arrive au lot 4 bis.

## Commandes

Copier `.env.example` → `.env` à la racine (et `mobile/.env.example` → `mobile/.env`) avant de démarrer.

**Stack Docker (Postgres, PHP-FPM, Nginx, Mailpit, MinIO, worker Messenger)**
```
docker compose up -d          # démarre tous les services
docker compose down           # arrête tout
```
Backend sur `http://localhost:8000`, Mailpit sur `http://localhost:8026`, console MinIO sur `http://localhost:9001` (ports configurables via `.env`). Le service `worker` consomme la file `async` (emails, etc.) : sans lui, rien n'est envoyé.

**Backend (`/backend`, toutes les commandes via le conteneur `php`)**
```
docker compose run --rm php composer install
docker compose run --rm php bin/console lexik:jwt:generate-keypair --skip-if-exists
docker compose run --rm php bin/console doctrine:database:create --if-not-exists
docker compose run --rm php bin/console doctrine:migrations:migrate --no-interaction
docker compose run --rm php composer lint     # lint:yaml + lint:container + composer validate
docker compose run --rm php composer test     # PHPUnit (dont la suite de visibilité tests/Visibility)
docker compose run --rm php bin/console doctrine:migrations:diff --no-interaction   # nouvelle migration après une entité modifiée
```
Connexion Google/Apple (lot 1) : `GOOGLE_CLIENT_ID` / `APPLE_CLIENT_ID` dans `backend/.env` sont des placeholders. À remplacer par les vrais identifiants OAuth une fois créés (Google Cloud Console / Apple Developer) pour que `/api/auth/social/*` fonctionne.

**Mobile (`/mobile`, sur l'hôte, Node ≥ 20)**
```
npm install
npm run dev          # serveur de dev Vite
npm run lint          # ESLint
npm run test:unit -- --run   # Vitest
npm run build          # vue-tsc + build de prod
npx cypress run        # tests e2e (nécessite `npm run dev` ou le build servi en parallèle)
```

**CI** : `.github/workflows/ci.yml`, deux jobs (`backend`, `mobile`), lint + tests, déclenchés sur push/PR vers `main`.

## Sécurité

Aucun secret dans le dépôt (fournir un `.env.example`). Limitation de débit sur connexion, demandes d'ami et aperçu de lien. Uploads validés (type MIME, 5 Mo max, EXIF supprimé). Tokens en stockage sécurisé (Keychain / Keystore).

## Hors périmètre de la v1 (ne pas implémenter)

Paiements réels, blocage et signalement, groupes d'amis, chat, invitation par QR code, recherche par pseudo, invitation de non-inscrits, affiliation, version web complète (seule la page invité en lecture seule existe, 5.16), modération, révélation du donateur après réception, amitiés entre profils enfants, plusieurs gestionnaires par enfant, transfert de gestion, connexion autonome d'un enfant avant rattachement d'un email.

## Design
- Les maquettes sont dans `docs/design/`. Avant de coder ou de modifier un écran, lire `docs/design/DESIGN.md`, puis la capture (`captures/`) et le HTML de référence (`maquettes/`) de cet écran.
- Implémenter avec les composants Ionic Vue, les textes via vue-i18n et les couleurs via `src/theme/variables.css` (point de départ : `docs/design/theme/variables.css`). Ne pas recopier le HTML des maquettes.
- La spécification (`docs/SPECIFICATIONS.md`) prime sur les maquettes. En cas d'écart, poser la question.
- Zone secrète (prune) : uniquement en vue ami ou gestionnaire, et seulement si l'API renvoie les données. La vue propriétaire n'en montre aucune trace.
