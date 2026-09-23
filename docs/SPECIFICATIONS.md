# Spécifications — Application « Idées Cadeaux »

**Version :** 0.5 (spécifications validées) — 18 septembre 2026
**Destinataire :** Claude Code (développement) — à lire en entier avant de coder.
**Statut :** tous les points ouverts ont été traités et toutes les hypothèses validées ; la section 11 garde la trace des décisions prises.

---

## 1. Vision

Application mobile (iOS et Android) qui permet à des amis de :

- publier leurs propres idées cadeaux, visibles de leurs amis ;
- se **suggérer** mutuellement des idées cadeaux ;
- se coordonner **sans gâcher la surprise** : réserver un cadeau, commenter, cotiser à plusieurs, le tout invisible pour le destinataire.

### Règle d'or de la surprise (prioritaire sur tout le reste)

Le propriétaire d'une liste ne doit **jamais**, par aucun canal, pouvoir constater ou déduire l'existence de suggestions, réservations, commentaires, cotisations ou réactions faites par ses amis sur ses idées. Canaux concernés : API, compteurs, notifications (push, email, in-app), synchronisation hors-ligne, cache local, export RGPD, messages d'erreur. **Seule exception** : le gestionnaire d'un profil enfant voit tout sur la liste de l'enfant (voir 5.15).

Le filtrage se fait **côté serveur**, jamais côté client.

---

## 2. Stack technique

### Back-end
- PHP (dernière version stable supportée), Symfony (dernière LTS), **API Platform** (dernière version stable).
- Base de données : PostgreSQL, Doctrine ORM et migrations.
- Authentification : JWT courts + refresh token à rotation (ex. LexikJWT + bundle de refresh token). Validation **côté serveur** des ID tokens Google et Apple.
- Tâches asynchrones : Symfony Messenger (emails, push, export RGPD, aperçus de liens, rappels d'anniversaire via scheduler).
- Email : Symfony Mailer. Push : Firebase Cloud Messaging (FCM, qui relaie APNs pour iOS).
- Stockage des images : stockage objet compatible S3, redimensionnement serveur.
- Page web publique de la vue invité (5.16) : rendu serveur (Twig), sans SPA, sans connexion sur le web.
- Environnement de dev : Docker Compose. Tests : PHPUnit + client de test API Platform.

### Front-end
- **Ionic Vue + Capacitor** (Vue 3, TypeScript, Composition API), Pinia, Vue Router, **vue-i18n**.
- Base locale hors-ligne : SQLite via Capacitor (ex. `@capacitor-community/sqlite`).
- Stockage sécurisé des tokens : Keychain (iOS) / Keystore (Android) via plugin Capacitor.
- Push : `@capacitor/push-notifications`. Connexion sociale et réception de partage : plugins Capacitor à choisir et valider.
- Tests : Vitest (unitaires), tests e2e à définir.

### Dépôt
Monorepo : `/backend` (Symfony/API Platform) et `/mobile` (Ionic Vue). CI qui exécute lint + tests des deux parties.

### Nom de l'application (provisoire)
- Nom de travail : **« Idées Cadeaux »**. Le nom définitif et l'identité visuelle seront décidés plus tard.
- Pour que le changement soit indolore : nom d'affichage, identifiant d'app (bundle ID iOS / `applicationId` Android) et `appId` Capacitor **centralisés en configuration** (`capacitor.config.ts`, variables d'environnement, clé i18n `app.name`), jamais écrits en dur dans le code ou les traductions.
- Identifiant provisoire de type `com.example.ideescadeaux`. **À remplacer avant la première soumission aux stores** : il ne peut plus être modifié une fois l'app publiée.
- Thème Ionic par défaut, avec couleurs et polices regroupées dans un seul fichier de variables pour pouvoir changer l'identité visuelle facilement.

---

## 3. Glossaire

| Terme | Définition |
|---|---|
| **Propriétaire** | La personne pour qui les idées sont destinées (« la liste de X »). |
| **Idée** | Une idée cadeau, rattachée à un propriétaire. |
| **Idée personnelle** | Idée créée par le propriétaire lui-même pour lui-même. |
| **Suggestion** | Idée créée par un ami pour le propriétaire. Invisible du propriétaire. |
| **Ami** | Utilisateur lié par une amitié **acceptée** (réciproque). |
| **Réservation** | Un ami déclare « je l'offre » sur une idée. |
| **Cotisation** | Participation à plusieurs à un même cadeau, **déclarative** (aucun paiement réel). |
| **Occasion** | Étiquette optionnelle et prédéfinie (Anniversaire, Noël…). |

---

## 4. Règles de visibilité

| Élément | Propriétaire | Amis du propriétaire | Autres utilisateurs |
|---|---|---|---|
| Idée personnelle | Voit, modifie, supprime, archive (« reçu ») | Lecture seule | Rien |
| Suggestion | **Ne voit rien** (ni l'existence, ni les compteurs) | Voient tous. L'auteur modifie/supprime la sienne ; l'auteur, le réservant ou l'initiateur de la cotisation la marque « offert » | Rien |
| Réservation | **Rien** | Voient qui a réservé. Seul le réservant l'annule | Rien |
| Commentaire | **Rien** | Voient. L'auteur modifie/supprime le sien | Rien |
| Cotisation et participations | **Rien** | Voient le **total**, le reste à couvrir et les **noms** des participants. **Le montant de chaque participation n'est visible que par son auteur et par l'initiateur de la cotisation.** L'initiateur gère la cotisation, chacun gère sa participation | Rien |
| Réaction (« j'aime ») | **Rien** | Voient | Rien |
| Profil (pseudo, avatar, anniversaire, tailles, préférences) | — | Voient | Pseudo + avatar seulement dans une demande d'ami |
| Idée privée (brouillon) | Voit et gère ses propres brouillons, **jamais** ceux d'un ami | **Rien** (seul l'auteur la voit) | Rien |
| Liste d'un profil enfant | L'enfant n'a pas de connexion : son **gestionnaire** voit tout, y compris les éléments cachés (voir 5.15) | Voient comme pour tout propriétaire | Rien |
| Vue invité (lien de partage, sans compte) | — | — | Voient **uniquement** pseudo, avatar et idées personnelles publiées, si le propriétaire a créé un lien (5.16) |

### Conséquences techniques obligatoires
1. Quatre vues de sérialisation distinctes pour une idée : vue **propriétaire** (sans `reservation`, `comments`, `contribution`, `reactions`, ni aucun compteur associé), vue **ami**, vue **gestionnaire** (propriétaire + éléments cachés, réservée aux gestionnaires de profils enfants) et vue **invité** (publique : idées personnelles publiées uniquement, sans donnée d'interaction ni de profil). Idem pour une participation à une cotisation : seuls son auteur et l'initiateur de la cotisation reçoivent le champ `amount` ; pour tous les autres utilisateurs il est absent (API, `/sync` et notifications compris).
2. Les collections et ressources cachées renvoient **404 / collection vide** pour le propriétaire, jamais 403 (un 403 révélerait leur existence).
3. Le même filtrage s'applique aux notifications, au endpoint de synchronisation et à l'export RGPD.
4. **Suite de tests de non-régression obligatoire** : pour chaque ressource cachée, un test vérifie qu'un propriétaire ne la voit par aucun endpoint, ni via `/sync`, ni via notification, ni via export. Cette suite doit rester verte à chaque lot.
5. **Idées privées, vue gestionnaire et vue invité** : une idée privée n'apparaît que pour son auteur dans toute réponse d'API, `/sync`, notification et compteur (profil, liste d'un ami). La vue gestionnaire n'est accordée qu'au gestionnaire d'un profil enfant, jamais à un autre utilisateur. La vue invité ne contient que des idées personnelles publiées, sans donnée d'interaction, privée ou de profil. La suite de tests couvre ces trois cas.

---

## 5. Fonctionnalités

### 5.1 Comptes et authentification
- Inscription email + mot de passe : email unique, mot de passe d'au moins 10 caractères, **vérification de l'email** (lien ou code).
- Connexion email + mot de passe. Mot de passe oublié (email de réinitialisation).
- Connexion **Google** et **Apple** (Apple obligatoire sur iOS dès que le login Google est proposé). Rattachement automatique à un compte existant si l'email est vérifié et identique. Gérer le relais d'email privé d'Apple.
- Sessions : access token ~15 min, refresh token ~30 jours avec rotation, révocation à la déconnexion.
- Toute route de l'API, hors authentification, exige un utilisateur connecté. Exception : les endpoints publics de la vue invité (5.16), en lecture seule et limités en débit.
- Une invitation par lien en attente (5.16) est conservée à travers l'inscription, la vérification d'email et la connexion.
- Onboarding : pseudo, avatar (optionnel), anniversaire (optionnel), écran de consentement aux notifications (voir 5.11).

### 5.2 Profil
- **Pseudo** (2–30 caractères, non unique, affiché aux amis), **avatar** (upload + recadrage).
- **Date d'anniversaire** (optionnelle) : jour et mois obligatoires si renseignée, année optionnelle. Sert aux rappels aux amis.
- **Tailles** : liste **illimitée** d'entrées « libellé + valeur » (+ note optionnelle), par exemple « Pointure : 42 », « T-shirt : M », « Jean : W32 L32 », « Bague : 54 ». Ajout, modification, suppression et réordonnancement libres. Des libellés courants sont proposés à la saisie (Pointure, T-shirt, Pull, Chemise, Pantalon, Jean, Robe, Veste, Bague, Gants, Bonnet…) mais le texte libre est accepté. **Visibles de tous les amis**, jamais des autres utilisateurs. Plafond technique de 100 entrées par utilisateur contre les abus.
- **Préférences** : liste d'entrées libres classées par catégorie (goût, marque, autre). Visibles des amis.
- Paramètres : langue, notifications, export des données, suppression du compte.

### 5.3 Amis
- Ajout par **email exact** (pas de recherche partielle, pour éviter l'énumération). La réponse est identique que le compte existe ou non (« si un compte existe, la demande a été envoyée »).
- Ajout via **contacts du téléphone** : permission OS avec explication préalable. Le téléphone envoie des **emails normalisés et hachés (SHA-256)**, le serveur renvoie les correspondances. **Aucun contact n'est stocké** côté serveur.
- Ajout par **lien de partage** : voir 5.16 (amitié créée directement, sans demande ni acceptation, après confirmation du visiteur connecté).
- Flux : demande → acceptation ou refus. Le demandeur peut annuler. Un **refus est silencieux** : le demandeur n'est pas notifié et voit sa demande « en attente », puis « expirée » ; le statut `declined` n'est jamais exposé au demandeur (ni par l'API, ni par `/sync`). Toute demande non acceptée **expire 30 jours après sa création** (tâche planifiée quotidienne). Délai de 30 jours entre deux demandes vers la même personne, compté depuis la création de la précédente (annulation comprise), sauf après un retrait d'ami : cela empêche de relancer en boucle. Une seule demande active par paire. Limitation de débit sur l'envoi de demandes.
- Amitié réciproque. **Retirer un ami** : bilatéral, sans notification, avec les mêmes effets dans les deux sens sur ce que chacun a créé sur les idées de l'autre :
  - **Actions annulées** : réservations, participations à des cotisations (totaux recalculés) et réactions.
  - **Contenus conservés** : suggestions et commentaires restent en place, visibles des amis restants du propriétaire (toujours invisibles de lui). Leur auteur n'a plus accès à la liste : il ne peut plus les modifier, les supprimer ni les marquer « offert » ; le réservant ou l'initiateur d'une cotisation d'une autre personne peut toujours archiver la suggestion.
  - **Cotisation initiée par le retiré** : clôturée automatiquement, participations des autres conservées ; sans initiateur, les montants individuels ne sont visibles que de leur auteur.
  - Aucune notification n'est envoyée pour ces annulations. Les deux personnes perdent l'accès à la liste de l'autre : purge du cache local à la prochaine synchronisation.
  - Une nouvelle demande d'ami est possible ; les actions annulées ne sont pas restaurées.
- Liste d'amis avec accès à la liste d'idées de chacun.

### 5.4 Idées
- **Champs** : titre (obligatoire, 120 caractères max), lien (URL http/https), prix (montant décimal + devise, EUR par défaut), image (upload ou récupérée depuis un lien), note/description (2000 caractères max), occasion (optionnelle).
- **Occasions prédéfinies** (table de référence gérée côté serveur, libellés traduisibles, ordre via `sortOrder`) : Anniversaire, Noël, Fête de fin d'année, Naissance, Baptême / communion, Fiançailles, Mariage, Pendaison de crémaillère, Saint-Valentin, Fête des mères, Fête des pères, Diplôme, Départ / retraite, Remerciement. **Jamais obligatoire** (pas de valeur « Autre » : ne rien choisir suffit). Pas de gestion des occasions par les utilisateurs en v1.
- **Modes de création** : saisie manuelle ; depuis un lien avec pré-remplissage (5.5) ; depuis le partage d'une autre app (5.6).
- **Consultation** : ma liste, liste d'un ami (idées personnelles + suggestions, avec état réservé/cotisation), filtre par occasion, tri (date, prix), recherche texte, section Archives.
- **Suggérer** : depuis le profil d'un ami, mêmes champs. Le propriétaire n'en voit rien.
- **Idées privées (brouillons)** : chaque idée est **privée** ou **publiée** (par défaut : **publiée**). Une idée privée, pour soi-même ou pour un ami, n'est visible que de son **auteur** : elle n'apparaît dans aucune liste, notification, compteur ni synchronisation d'un autre utilisateur, et ne peut être ni réservée, ni commentée, ni aimée, ni faire l'objet d'une cotisation.
  - **Publication** : l'auteur publie ou repasse une idée en privé à tout moment. Publier une idée personnelle la rend visible des amis ; publier une suggestion la rend visible des amis du propriétaire (jamais du propriétaire). Les notifications « nouvelle idée » et « nouvelle suggestion » partent à la **publication**, pas à la création du brouillon. Publier suppose une amitié active avec le destinataire ; après un retrait d'ami, le brouillon reste consultable par son auteur mais ne peut plus être publié.
  - **Repasser en privé** : possible **à tout moment**, même si des amis ont déjà interagi ; réservation, commentaires, réactions, cotisation et participations sont alors **définitivement supprimés**, après confirmation. Pour respecter la règle d'or, l'avertissement montré à un **propriétaire** est **générique** (« les éventuelles interactions de vos amis seront supprimées »), sans nombre ni détail ; l'auteur d'une suggestion voit le détail. Les personnes ayant interagi sont notifiées.
  - **Consultation** : un écran « Privées » liste tous mes brouillons, groupés par destinataire ; sur la liste d'un ami, une section « Mes brouillons pour X » n'est visible que de moi.
- **Modification/suppression** : le propriétaire pour ses idées, l'auteur pour ses suggestions.
- **Archivage** :
  - *Idée personnelle* : le propriétaire la marque « reçue » → elle passe en archive.
  - *Suggestion* : le propriétaire ne la voit pas. Son **auteur** ou son **réservant** la marque « offert » → elle passe en archive. Si l'idée est en cotisation (donc sans réservant), l'**initiateur** de la cotisation peut aussi la marquer « offert ».
  - Seul celui qui a archivé peut annuler l'archivage.
  - Une idée archivée n'accepte plus de réservation, de commentaire ni de participation ; une cotisation en cours est clôturée automatiquement.
  - Les suggestions archivées restent **invisibles du propriétaire** et consultables par les amis dans les archives de la liste.

### 5.5 Pré-remplissage depuis un lien
- Endpoint serveur qui récupère titre, image et prix (balises OpenGraph, Twitter Cards, JSON-LD `Product`).
- **Protection SSRF obligatoire** : http/https seulement, ports 80/443, résolution DNS puis blocage des IP privées, locales et link-local, 3 redirections max, timeout ~5 s, taille de réponse plafonnée (~2 Mo).
- Les valeurs trouvées sont **proposées**, jamais imposées. L'image est copiée et redimensionnée sur notre stockage.
- Si rien n'est trouvé, saisie manuelle.

### 5.6 Partage depuis le navigateur ou une autre app
- L'utilisateur partage une URL vers l'appli, qui ouvre l'écran de création pré-rempli avec choix du destinataire (soi-même ou un ami, donc suggestion).
- Android : intent de partage. iOS : **Share Extension** (développement natif partiel, tâche dédiée du lot 7).
- Hors-ligne : le brouillon est enregistré, le pré-remplissage se complète au retour du réseau.

### 5.7 Réservation (« je l'offre »)
- Un ami (différent du propriétaire) réserve une idée. **Un seul réservant actif** par idée. Il peut annuler.
- Une idée est soit **réservée**, soit **en cotisation ouverte**, jamais les deux. L'exclusivité ne concerne que les cotisations **ouvertes** : une cotisation clôturée n'empêche pas une nouvelle réservation ou cotisation.
- **Conversion** : le réservant peut **convertir sa réservation en cotisation** (« ouvrir à plusieurs »). La réservation est supprimée, il devient l'initiateur, et l'application lui propose de déclarer sa propre participation. La conversion inverse n'existe pas en v1 : l'initiateur peut seulement clôturer la cotisation.
- Conflit hors-ligne : la première synchronisation gagne ; la seconde reçoit un message clair (« déjà réservé par X »).

### 5.8 Commentaires
- Sur toute idée visible d'un ami (personnelle ou suggestion). Texte seul, 1000 caractères max, fil plat, modification/suppression par l'auteur.
- **Jamais visibles du propriétaire.**

### 5.9 Réactions
- « J'aime » à bascule, un par utilisateur et par idée, avec compteur. Un utilisateur ne réagit pas à ses propres idées.
- **Toujours masquées au propriétaire** (réactions et compteur), sur ses idées personnelles comme sur les suggestions.

### 5.10 Cotisation à plusieurs (déclarative)
- Un ami (différent du propriétaire) ouvre une cotisation sur une idée : montant cible optionnel (par défaut le prix de l'idée), devise.
- Chaque ami déclare, modifie ou retire sa participation (montant > 0).
- Affichage : total déclaré, reste à couvrir si cible, liste des **noms** des participants, indicateur « objectif atteint ». Le **montant individuel n'est visible que par son auteur et par l'initiateur** de la cotisation : l'API ne l'expose pas aux autres amis. Le total étant visible, la variation du total lors d'une participation permet d'en déduire le montant ; c'est accepté, la cotisation étant déclarative entre amis.
- L'initiateur peut clôturer la cotisation. Une seule cotisation ouverte par idée. Impossible d'en ouvrir une sur une idée réservée : le réservant doit la convertir (5.7). Conflit hors-ligne (deux cotisations ouvertes en parallèle) : la première synchronisation gagne, la seconde reçoit « une cotisation existe déjà » et peut y déclarer une participation.
- **Aucun paiement, aucune donnée bancaire.** L'interface indique clairement que les montants sont déclaratifs.
- Jamais visible du propriétaire.

### 5.11 Notifications
Canaux : **in-app** (centre de notifications + badge), **email**, **push**. Préférences réglables **par type et par canal**.

| Événement | Destinataires | Canaux par défaut |
|---|---|---|
| Demande d'ami reçue | Destinataire de la demande | in-app, push, email |
| Demande d'ami acceptée | Demandeur | in-app, push |
| Nouvel ami via votre lien de partage | Propriétaire du lien (le gestionnaire pour un profil enfant) | in-app, push |
| Lien de partage suspendu (plafond atteint) | Propriétaire du lien (le gestionnaire pour un profil enfant) | in-app, push |
| Nouvelle idée personnelle **publiée** par un ami | Amis du propriétaire | in-app |
| Nouvelle suggestion **publiée** pour X | Amis de X, **sauf X** et l'auteur | in-app |
| Idée réservée | Auteur de la suggestion, commentateurs, participants, **sauf propriétaire** et acteur | in-app, push |
| Nouveau commentaire | Commentateurs précédents, réservant, participants, auteur de la suggestion, **sauf propriétaire** et auteur du commentaire | in-app, push |
| Cotisation créée (y compris par conversion d'une réservation), nouvelle participation (sans montant), objectif atteint | Même groupe | in-app, push |
| Suggestion marquée « offert » | Même groupe, **sauf propriétaire** et acteur | in-app |
| Idée repassée en privé | Réservant, commentateurs, participants, **sauf propriétaire** et acteur | in-app |
| Rappel d'anniversaire d'un ami | Amis de la personne (pas la personne elle-même) | in-app, push (J-14 et J-2 par défaut, réglables) |

- Aucune notification ne doit révéler au propriétaire une activité sur ses idées (règle d'or).
- **Rappels d'anniversaire** : J-14 et J-2 par défaut. Chaque utilisateur règle ses délais (en jours) et peut les désactiver ; réglage global en v1, pas par ami. Envoi vers 9 h dans le fuseau du destinataire. Un anniversaire au 29 février est fêté le 28 février les années non bissextiles.
- **Profils enfants** : les notifications destinées à un enfant sont envoyées à son gestionnaire, avec le nom de l'enfant.
- Les notifications ouvrent l'écran concerné (deep link).
- **Consentement explicite** : écran d'explication avant la demande de permission OS, consentement horodaté par canal (push, email). Sans consentement, aucune notification non essentielle. Désinscription email en un clic. Les emails transactionnels (vérification, mot de passe, export) sont exclus de ce périmètre.
- Push : enregistrement du token à la connexion, suppression à la déconnexion, nettoyage des tokens invalides.

### 5.12 Hors-ligne (utilisation complète avec synchronisation)
Voir section 8.

### 5.13 RGPD
- **Export de mes données** : demande depuis les paramètres, ré-authentification, génération asynchrone d'une archive ZIP (JSON + images téléversées), lien de téléchargement temporaire (48 h) envoyé par email. Contenu : profil, tailles, préférences, amitiés (pseudos), idées créées (y compris suggestions faites à d'autres), commentaires, réactions, participations, réservations effectuées, consentements. **Exclut** tout élément caché créé par des amis sur ses propres idées (règle d'or).
- **Suppression du compte et des données**, avec **période de grâce de 14 jours** :
  - **Demande** : ré-authentification + confirmation explicite. L'appli propose d'abord d'exporter ses données. Email de confirmation avec lien d'annulation.
  - **Pendant 14 jours** : compte suspendu. Toutes les sessions et tokens push sont révoqués, la connexion est impossible sauf pour **annuler la suppression** (écran « Votre compte sera supprimé le … »), aucune notification n'est envoyée ni générée à son sujet (rappels d'anniversaire compris), aucune nouvelle demande d'ami ne peut lui être adressée, et son email ne peut pas servir à une nouvelle inscription (message invitant à se connecter pour annuler). Ses contenus restent en l'état.
  - **Annulation** : par connexion ou via le lien de l'email ; le compte retrouve son fonctionnement normal.
  - **À l'échéance** : suppression définitive et automatique (tâche planifiée), email d'information. Sont supprimés : le compte, ses idées avec tout ce qui s'y rattache (suggestions, commentaires, réservations, cotisations, réactions d'autres utilisateurs), ses images, et ce qu'il a créé sur les listes d'autres personnes (suggestions, commentaires, réservations, réactions, participations ; totaux de cotisations recalculés). Une cotisation qu'il avait initiée est clôturée automatiquement, les participations des autres étant conservées.
  - **Sauvegardes** : purgées par rotation sous 30 jours après la suppression définitive.
  - **Synchronisation** : la suppression se propage aux appareils des amis via les tombstones (purge locale).
  - La période de grâce doit être mentionnée dans la politique de confidentialité.
  - **Gestionnaire** : si le compte a des profils enfants, l'appli l'en avertit et propose, pour chacun, de rattacher un email (compte autonome) ou d'exporter ses données ; sans action, les profils enfants sont supprimés avec le compte. La suppression d'un profil enfant seul suit la même règle (14 jours de grâce, à l'initiative du gestionnaire).
- **Consentement explicite aux notifications** : voir 5.11.
- Politique de confidentialité accessible dans l'appli. Hébergement dans l'UE. Minimisation : aucun contact du téléphone conservé. Profils enfants : consentement explicite du gestionnaire (titulaire de l'autorité parentale) à la création, données limitées au minimum, profils exclus de la recherche par email et de la correspondance de contacts. La politique de confidentialité mentionne la vue publique des idées par lien de partage.

### 5.14 Internationalisation (i18n)
- **Français par défaut**, architecture prête pour d'autres langues : `vue-i18n`, **aucun texte en dur**, formats de dates, nombres et devises via `Intl`.
- L'API renvoie des **codes d'erreur stables** (`problem+json` avec champ `code`), traduits côté client.
- Emails et push traduits selon `User.locale`. Occasions référencées par code + clé de traduction.

### 5.15 Profils enfants gérés (sans email)

**Principe** : un utilisateur adulte, le **gestionnaire**, crée et gère des profils pour ses enfants. Un profil enfant n'a ni email, ni mot de passe, ni connexion : seul son gestionnaire l'utilise. Un profil enfant a **un seul gestionnaire**.

- **Création** : depuis « Mes enfants » : pseudo (obligatoire), avatar, date d'anniversaire (optionnelle), case de consentement explicite (« je suis titulaire de l'autorité parentale »). Limite de 10 profils enfants par gestionnaire.
- **Contenu du profil** : identique à celui d'un utilisateur (anniversaire, tailles, préférences), visible des amis de l'enfant.
- **Agir au nom de l'enfant** : sélecteur de profil dans l'appli ; côté API, en-tête `X-Acting-As: <id>` vérifié par le serveur. Le gestionnaire crée et gère ainsi les idées de l'enfant (publiées ou privées), les marque « reçues », tient son profil (tailles, préférences) et gère ses amis. Ces actions sont attribuées à l'enfant.
- **Le gestionnaire voit tout sur la liste de l'enfant** (« vue gestionnaire ») : idées de l'enfant, suggestions des amis, réservations, commentaires, réactions, cotisations avec participants et total. Les **montants individuels** restent visibles de leur seul auteur et de l'initiateur : le gestionnaire n'en voit que s'il est initiateur ou participant. Il peut aussi réserver, commenter, suggérer, réagir et cotiser sur la liste de son enfant en son propre nom, comme un ami. C'est l'exception à la règle d'or.
- **Amis d'un enfant** : **adultes uniquement** en v1 (comptes avec email). Toute amitié est **initiée par le gestionnaire au nom de l'enfant** (email de l'adulte, contacts, « inviter mes amis » ou lien de partage du profil de l'enfant, voir 5.16). L'adulte reçoit une demande « au nom de [enfant] », avec la mention « profil géré par [gestionnaire] » ; acceptation, refus silencieux et expiration comme en 5.3. Un adulte ne peut pas demander un enfant en ami : les profils enfants sont **exclus de la recherche par email et de la correspondance de contacts**. Le gestionnaire peut retirer un ami de l'enfant, et l'adulte peut retirer l'enfant de ses amis.
- **Rattacher un email (compte autonome)** : le gestionnaire saisit un email ; celui-ci reçoit une invitation valable 7 jours pour définir un mot de passe ou se connecter avec Google/Apple. À la validation, le profil devient un **compte autonome** et conserve tout (idées, amis, tailles, préférences). L'email ne doit appartenir à aucun compte existant (pas de fusion en v1). Effets : l'accès du gestionnaire **prend fin**, l'ancien enfant devient un propriétaire soumis à la règle d'or, et une amitié est créée automatiquement entre l'ancien gestionnaire et le compte converti (que ce dernier peut retirer).
- **Suppression et export** : le gestionnaire exporte les données d'un enfant et peut supprimer son profil (14 jours de grâce, comme en 5.13).
- **Hors-ligne** : le gestionnaire synchronise aussi les données de ses profils enfants (vue gestionnaire), uniquement sur ses appareils.

### 5.16 Partage du profil par lien

**Principe** : un utilisateur (ou le gestionnaire, pour un profil enfant) crée un **lien de partage**. Quiconque l'ouvre voit les idées publiées de son propriétaire, sans compte et en lecture seule. Pour interagir ou devenir ami, il faut un compte.

**Gestion du lien (propriétaire)**
- Depuis « Mon profil » → « Partager mon profil » (et depuis la fiche d'un enfant pour le gestionnaire). Le partage est **désactivé tant qu'aucun lien n'existe**. Un seul lien actif par propriétaire.
- Actions : créer, copier, partager (feuille de partage du système), **régénérer** (l'ancien lien devient invalide, les amis déjà ajoutés restent amis), **désactiver**.
- À la création, une confirmation avertit : « Toute personne ayant ce lien pourra voir vos idées publiées sans compte et devenir votre ami. » Pour un profil enfant, le texte vise l'enfant et le gestionnaire doit confirmer explicitement.
- Format : `https://<domaine>/u/<token>`, jeton aléatoire de 128 bits. Le domaine sera défini avec le nom définitif de l'appli.
- Un lien invalide, désactivé ou régénéré renvoie la même réponse générique (« lien invalide ou expiré »).

**Ce que voit un visiteur sans compte (« vue invité »)**
- Pseudo, avatar et **idées personnelles publiées** du propriétaire (titre, image, prix, lien, note, occasion), avec filtre par occasion et tri.
- **Rien d'autre** : ni suggestions, ni réservations, commentaires, réactions, cotisations ou compteurs, ni idées privées ou archivées, ni tailles, préférences, anniversaire ou liste d'amis. C'est la vue propriétaire, pour qu'un propriétaire déconnecté ne découvre rien via son propre lien.
- Les actions d'interaction (réserver, commenter, réagir, cotiser, suggérer, devenir ami) sont visibles mais mènent à « Créer un compte pour interagir ».
- Les URL d'images de la vue invité sont signées et de courte durée.

**Deux surfaces pour la vue invité**
1. **Page web légère**, en lecture seule, rendue côté serveur (aucune connexion sur le web) : vue invité, bouton « Ouvrir dans l'appli » et liens vers les stores. Non indexée (`noindex`), non mise en cache. L'aperçu du lien dans les messageries (Open Graph) n'affiche que le pseudo et un texte générique, jamais d'idées.
2. **Mode invité dans l'appli** : si l'appli est installée sans compte connecté, le lien ouvre la vue invité dans l'appli (liens universels iOS, App Links Android).

**Devenir ami via le lien**
- Un utilisateur **connecté** qui ouvre le lien voit un **écran de confirmation** (« Devenir ami avec [pseudo] ? Vous verrez mutuellement vos idées »). À la confirmation, l'amitié est créée **immédiatement et dans les deux sens**, sans demande ni acceptation, et le propriétaire est notifié. Sans confirmation, rien ne se passe.
- Un visiteur **sans compte** : après inscription ou connexion (email ou Google/Apple, vérification d'email comprise), l'invitation en attente est conservée (7 jours) et l'écran de confirmation s'affiche.
- Si l'appli n'est pas installée, la page web propose l'installation ; ensuite l'utilisateur **rouvre le lien** ou le colle dans « J'ai un lien d'invitation » (écran d'accueil de l'appli). Pas de lien différé : Firebase Dynamic Links est arrêté, et un service tiers de lien différé reste une amélioration possible.
- Cas particuliers : lien du propriétaire lui-même → renvoi vers son profil ; déjà amis → renvoi vers sa liste ; demande d'ami en attente entre les deux → résolue en amitié ; demande refusée ou expirée auparavant → sans effet, le lien prévaut.
- **Retrait par le propriétaire** : un utilisateur retiré par le propriétaire ne peut plus rejoindre via le lien (réponse générique, sans explication) et doit passer par une demande d'ami classique. Un utilisateur qui s'est retiré lui-même peut rejoindre à nouveau.
- **Profil enfant** : le lien d'un profil enfant fonctionne comme celui d'un adulte, vue invité comprise. Seuls des comptes adultes peuvent rejoindre (les profils enfants n'ont pas de connexion). L'écran de confirmation précise « profil géré par [gestionnaire] » et que le gestionnaire verra les idées publiées de l'utilisateur. Le gestionnaire crée, révoque et régénère le lien et reçoit les notifications.
- **Plafond anti-abus** : au plus 50 amitiés créées par lien par période de 24 h ; au-delà, le lien est suspendu et le propriétaire notifié.

**Sécurité** : endpoints publics limités en débit, réponses uniformes (pas d'énumération), jeton comparé en temps constant, en-têtes de sécurité et CSP stricte sur la page web, tests de visibilité (section 4) incluant la vue invité.

**Hors-ligne** : rejoindre via un lien et la vue invité nécessitent le réseau ; un lien ouvert hors-ligne est conservé et traité au retour du réseau.

---

## 6. Modèle de données (proposition)

Toutes les entités : `id` **UUID (v7) pouvant être généré par le client**, `createdAt`, `updatedAt`, `deletedAt` (suppression logique pour la synchronisation).

| Entité | Champs principaux |
|---|---|
| **User** | type (regular/managed), managedBy (nullable, un seul gestionnaire), email (nullable pour un profil géré), passwordHash (nullable), displayName, avatarPath, birthDay, birthMonth, birthYear (nullable), locale, timezone, emailVerifiedAt, deletionScheduledAt (nullable) |
| **SocialIdentity** | user, provider (google/apple), providerUserId |
| **Friendship** | requester, addressee, status (pending/accepted/declined/expired), respondedAt, expiresAt (création + 30 jours), onBehalfOfManager (nullable : gestionnaire ayant agi au nom d'un enfant), origin (request/link/conversion), shareLink (nullable), removedBy (nullable). Une seule demande active par paire |
| **ShareLink** | owner (utilisateur ou profil enfant), token (aléatoire 128 bits, unique), status (active/revoked), revokedAt, lastUsedAt. Un seul lien actif par propriétaire |
| **ProfileSize** | user, label, value, note (nullable), sortOrder |
| **ProfilePreference** | user, category (goût/marque/autre), label, value |
| **Occasion** | code, translationKey, sortOrder (table de référence) |
| **Idea** | owner, author, title, url, priceAmount, priceCurrency, imagePath, note, occasion (nullable), visibility (private/published, défaut published), publishedAt (nullable), status (active/archived), archivedAt, archivedBy (nullable), archiveKind (received/gifted). *Suggestion = author ≠ owner* |
| **Reservation** | idea, user. Un seul actif par idée |
| **Contribution** (cotisation) | idea, initiator, targetAmount (nullable), currency, status (open/closed) |
| **ContributionPledge** | contribution, user, amount |
| **Comment** | idea, author, body, editedAt |
| **Reaction** | idea, user, type (like). Unicité (idea, user, type) |
| **Notification** | user, type, payload, readAt |
| **NotificationPreference** | user, channel, type, enabled, consentedAt |
| **DeviceToken** | user, platform, token, lastSeenAt |

---

## 7. API (API Platform)

- Format par défaut d'API Platform, documentation OpenAPI générée, pagination par défaut (20 éléments).
- Visibilité implémentée par **voters + extensions Doctrine + groupes de sérialisation dépendant du contexte** (propriétaire vs ami).
- Écritures **idempotentes** (identifiants générés client, en-tête `Idempotency-Key` accepté).

Ressources et endpoints principaux :
- `POST /auth/register`, `/auth/login`, `/auth/refresh`, `/auth/logout`, `/auth/social/google`, `/auth/social/apple`, `/auth/verify-email`, `/auth/forgot-password`, `/auth/reset-password`
- `GET|PATCH /users/me`, `DELETE /users/me`, `POST /users/me/export`
- `ProfileSize` et `ProfilePreference` : CRUD sur mon profil, lecture sur celui d'un ami
- Profils enfants : `POST|GET /managed-profiles`, `PATCH|DELETE /managed-profiles/{id}`, `POST /managed-profiles/{id}/attach-email`. En-tête `X-Acting-As: <id>` pour agir au nom d'un enfant (vérifié côté serveur)
- Partage par lien : `GET|POST|DELETE /share-link` (le mien, ou celui d'un enfant via `X-Acting-As`), `GET /public/share-links/{token}` (vue invité JSON, sans authentification, limité en débit), `POST /share-links/{token}/join` (authentifié, après confirmation), page web `GET /u/{token}` (HTML)
- `Friendship` : envoi (par email), liste, acceptation, refus, annulation, retrait. `POST /contacts/match` (emails hachés)
- `GET /users/{id}/ideas` (liste d'un ami), `Idea` CRUD, `POST /ideas/{id}/archive` (`kind` : `received` par le propriétaire, `gifted` par l'auteur, le réservant ou l'initiateur) et `POST /ideas/{id}/unarchive`, `POST /ideas/{id}/publish`, `POST /ideas/{id}/unpublish`
- `Reservation` (dont `POST /reservations/{id}/convert-to-contribution`), `Comment`, `Reaction`, `Contribution`, `ContributionPledge`
- `POST /link-previews` (aperçu de lien)
- `Notification` (liste, marquer lu), `NotificationPreference`, `DeviceToken`
- `Occasion` (lecture seule)
- `GET /sync?since=<curseur>` (voir section 8)

---

## 8. Hors-ligne et synchronisation

**Objectif :** toutes les actions courantes (consulter, créer, modifier, réserver, commenter, cotiser, réagir) fonctionnent sans réseau et se synchronisent ensuite.

Principes :
- **Base SQLite locale** = source de lecture de l'UI. Les écritures vont dans une **file d'attente (outbox)** rejouée dans l'ordre à la reconnexion, avec retries et backoff.
- **Identifiants UUID générés côté client** pour créer hors-ligne sans collision.
- **Synchronisation delta** : `GET /sync?since=<curseur>` renvoie créations, modifications et suppressions (tombstones via `deletedAt`) depuis le curseur, **avec le filtrage de visibilité de la section 4**.
- **Conflits** : dernier écrit gagne, champ par champ, sur l'horodatage serveur. Cas particuliers : réservation concurrente (première synchronisation gagne, voir 5.7), cotisation clôturée entre-temps (participation refusée avec message).
- **Retrait d'ami ou suppression d'un contenu** : à la synchro suivante, purge locale des données concernées.
- **Idées privées** : synchronisées uniquement vers les appareils de leur auteur. Les données des profils enfants (vue gestionnaire) ne sont synchronisées que vers les appareils du gestionnaire.
- **Lien de partage** : rejoindre via un lien nécessite le réseau ; le jeton reçu hors-ligne est conservé et traité au retour du réseau.
- Authentification : tokens en stockage sécurisé, renouvellement au retour du réseau. Connexion sociale et pré-remplissage de lien demandent le réseau (fallback : saisie manuelle / différé).
- Indicateurs d'UI : état de synchronisation, actions en attente, erreurs de sync avec possibilité de réessayer.
- **Contrainte dès le premier lot** : UUID client, `updatedAt`, `deletedAt` et tests de visibilité doivent exister avant d'implémenter la synchro complète.

---

## 9. Exigences non fonctionnelles

- **Sécurité** : HTTPS partout, limitation de débit (connexion, demandes d'ami, aperçu de lien), tokens en stockage sécurisé, validation MIME et taille des uploads (5 Mo max), suppression des métadonnées EXIF, protection SSRF (5.5), pas de secret dans le dépôt.
- **Performance** : pagination systématique, images redimensionnées (vignette + grande taille), chargement progressif dans l'app.
- **Accessibilité** : libellés pour VoiceOver/TalkBack, contrastes suffisants, tailles de texte dynamiques.
- **Qualité** : tests automatisés (unitaires, API, suite de visibilité 4.4), CI obligatoire, environnements dev / staging / prod.
- **Observabilité** : logs applicatifs structurés, suivi d'erreurs (back et app).
- **Publication stores** : Sign in with Apple, étiquettes de confidentialité (App Store) et section « Sécurité des données » (Google Play), textes de justification des permissions (contacts, notifications, photos). L'application n'est pas destinée aux enfants (pas de catégorie « Enfants ») : les profils enfants sont créés et gérés par un adulte ; à vérifier avec les règles des stores.

---

## 10. Hors périmètre de la v1

Paiements réels, blocage et signalement d'utilisateurs, groupes d'amis ou listes partagées, chat privé, invitation par QR code, recherche d'ami par pseudo, invitation de non-inscrits par email/SMS, affiliation ou liens marchands, version web complète (seule la page invité en lecture seule existe, 5.16), modération de contenu, révélation du donateur après réception, amitiés entre profils enfants, plusieurs gestionnaires par enfant, transfert de gestion d'un enfant, connexion autonome d'un enfant avant rattachement d'un email.

---

## 11. Décisions prises

Tous les points ouverts ont été traités et toutes les hypothèses validées.

1. ✅ **Tranché — suggestions et « reçu »** : l'auteur, le réservant ou l'initiateur de la cotisation marque la suggestion « offert », ce qui l'archive.
2. ✅ **Tranché — réactions** : toujours masquées au propriétaire, sur ses idées personnelles comme sur les suggestions.
3. ✅ **Tranché — cotisation** : tous les amis du propriétaire voient les noms des participants, le total et le reste à couvrir ; les montants individuels sont visibles uniquement de leur auteur et de l'initiateur de la cotisation.
4. ✅ **Tranché — retrait d'un ami** : actions annulées (réservations, participations, réactions), contenus conservés (suggestions, commentaires). Les règles dérivées (droits sur les suggestions orphelines, cotisation du retiré) sont détaillées en 5.3 et validées.
5. ✅ **Tranché — réservation vs cotisation** : exclusives (cotisations ouvertes), avec conversion possible de la réservation en cotisation par le réservant (5.7).
6. ✅ **Tranché — rappels d'anniversaire** : J-14 et J-2 par défaut, réglables (détails en 5.11).
7. ✅ **Tranché — refus d'ami** : refus silencieux, demande « en attente » puis « expirée » après 30 jours (détails en 5.3).
8. ✅ **Tranché — occasions** : 14 occasions prédéfinies, facultatives (liste en 5.4).
9. ✅ **Tranché — suppression de compte** : période de grâce de 14 jours pour annuler, puis suppression définitive (détails en 5.13). Hébergement dans l'UE : validé.
10. ✅ **Tranché — partage vers l'appli** : iOS et Android dans la v1 (lot 7) ; l'extension iOS (développement natif partiel) est une tâche dédiée du lot 7.
11. ✅ **Tranché — nom** : nom de travail provisoire « Idées Cadeaux » ; nom définitif, identifiant d'app et identité visuelle à décider avant la première soumission aux stores (voir section 2).
12. ✅ **Tranché — après réception** : le propriétaire ne découvre pas qui a offert (hors périmètre v1).
13. ✅ **Tranché — cotisation dont l'initiateur disparaît** : retrait d'ami (5.3) ou suppression de compte (5.13), la cotisation est clôturée automatiquement et les participations des autres sont conservées.
14. ✅ **Tranché — idées privées** : brouillons visibles de leur seul auteur, publiés par défaut, repassage en privé possible à tout moment avec suppression des interactions (détails en 5.4).
15. ✅ **Tranché — profils enfants gérés** : sans email ni connexion, un seul gestionnaire qui voit tout, amis adultes uniquement en v1, rattachement possible d'un email plus tard (détails en 5.15). Le modèle de comptes est à prévoir dès le lot 0.
16. ✅ **Tranché — partage du profil par lien** : lien révocable créé par le propriétaire, vue invité en lecture seule (pseudo, avatar, idées personnelles publiées) sur page web et dans l'appli, amitié directe et réciproque après confirmation d'un utilisateur connecté, lien également disponible pour les profils enfants, vue invité comprise (détails en 5.16).
17. ✅ **Tranché (lot 3) — suggestion publiée après un retrait d'ami** : son auteur n'y a plus accès du tout, lecture comprise (404) ; elle reste visible des amis restants du propriétaire, et toujours invisible de lui. Ses brouillons pour l'ex-ami restent lisibles par lui mais ne peuvent plus être publiés (5.3, 5.4).
18. ✅ **Tranché (lot 3) — compteur « N idées » de la liste d'amis** : idées personnelles publiées et suggestions publiées des amis, actives uniquement, jamais de brouillon. Sans fuite : un propriétaire ne voit jamais son propre compteur.
19. ✅ **Tranché (lot 3) — codes des occasions** : codes techniques en anglais (`birthday`, `christmas`, `new_year`…), libellés traduits côté client (`occasions.<code>`).

---

## 12. Plan de livraison proposé (lots)

| Lot | Contenu | Critère de fin |
|---|---|---|
| **0. Fondations** | Monorepo, Docker, CI, squelettes API Platform et Ionic Vue, i18n branchée, conventions (UUID client, timestamps, soft delete), harnais de tests de visibilité, modèle de comptes prévoyant les profils gérés (type, gestionnaire, `X-Acting-As`) | CI verte, app vide qui parle à l'API |
| **1. Comptes** | Inscription/connexion email, vérification, mot de passe oublié, Google/Apple, sessions, profil, onboarding | Un utilisateur crée un compte, se connecte, édite son profil |
| **2. Amis** | Recherche par email, contacts hachés, demandes, acceptation, retrait | Deux utilisateurs deviennent amis et se retirent |
| **3. Idées** | CRUD, occasions, suggestions, idées privées et publication, listes, filtres, archives, règles de visibilité | Suite de visibilité verte sur idées et suggestions |
| **4. Interactions** | Réservation, réactions, commentaires, cotisation, marquage « offert » et archivage des suggestions | Suite de visibilité verte sur tous les objets cachés |
| **4 bis. Profils enfants** | Création de profils gérés, agir au nom de l'enfant (`X-Acting-As`), vue gestionnaire, amitiés au nom de l'enfant, rattachement d'un email, export et suppression | Suite de visibilité verte avec la vue gestionnaire ; conversion en compte autonome validée |
| **5. Notifications** | In-app, email, push, préférences, consentement, rappels d'anniversaire | Aucune fuite vers le propriétaire, tests à l'appui |
| **6. Hors-ligne** | SQLite, outbox, `/sync`, gestion des conflits, UI de synchro | Scénarios hors-ligne complets validés |
| **7. Confort** | Aperçu de lien, partage Android, Share Extension iOS (tâche dédiée) | Idée créée depuis un partage sur iOS et Android |
| **7 bis. Partage par lien** | Création, révocation et régénération du lien, page web invité, mode invité dans l'appli, liens universels / App Links, jointure avec confirmation, lien de profil enfant, plafond anti-abus | Tests de visibilité verts avec la vue invité ; un visiteur sans compte voit la liste, s'inscrit, puis devient ami |
| **8. RGPD et publication** | Export, suppression de compte, finitions i18n, accessibilité, nom et identité définitifs (identifiant d'app, icône, écran de démarrage), préparation stores | Application publiable |

---

## 13. Instructions pour Claude Code

- Un `CLAUDE.md` prêt à l'emploi est fourni avec ce document : le placer à la racine du dépôt, et ce document dans `docs/SPECIFICATIONS.md`. Compléter sa section « Commandes » au lot 0.
- Avancer **lot par lot**, en mettant à jour les tests à chaque étape. Ne pas passer au lot suivant si la suite de visibilité est rouge.
- En cas d'ambiguïté ou de conflit avec une décision de la section 11, **poser la question** plutôt que d'inventer.
- Ne jamais implémenter de paiement réel ni stocker de contacts du téléphone.
