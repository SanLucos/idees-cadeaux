# Spécifications — Application « Idées Cadeaux »

**Version :** 0.6 — 14 octobre 2026 (0.5 validée le 18 septembre 2026 ; lots 10 à 12 ajoutés)
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

### Nom de l'application
- Nom : **« Idées Cadeaux »** (définitif, décision 55). L'identité visuelle pourra encore évoluer.
- Pour que le changement soit indolore : nom d'affichage, identifiant d'app (bundle ID iOS / `applicationId` Android) et `appId` Capacitor **centralisés en configuration** (`capacitor.config.ts`, variables d'environnement, clé i18n `app.name`), jamais écrits en dur dans le code ou les traductions.
- Identifiant d'app : `fr.frigologie.ideescadeaux` (décision 55). Il ne peut plus être modifié une fois l'app publiée.
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
| Historique des tailles (valeurs passées) | Voit, **lui seul** (pour un profil enfant : ses **gestionnaires** seuls) | **Rien** : uniquement la valeur actuelle | Rien |
| Idée privée (brouillon) | Voit et gère ses propres brouillons, **jamais** ceux d'un ami | **Rien** (seul l'auteur la voit) | Rien |
| Liste d'un profil enfant | L'enfant n'a pas de connexion : ses **gestionnaires** (deux au plus) voient tout, y compris les éléments cachés (voir 5.15) | Voient comme pour tout propriétaire, y compris les amis d'un gestionnaire quand « Partager avec mes amis » est actif (5.15) | Rien |
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
- **Tailles** : liste **illimitée** d'entrées « libellé + valeur + unité » (+ note optionnelle), par exemple « Pointure : 42 EU », « Tour de taille : 80 cm », « T-shirt : M », « Jean : W32 L32 », « Bague : 54 ». L'**unité** est choisie dans une liste fixe (cm, mm, m, g, kg, ml, L, EU, US, UK) ; « pas d'unité » est la valeur par défaut et celle des tailles saisies avant le lot 11 (détails : section 11, décision 57). Ajout, modification, suppression et réordonnancement libres. Des libellés courants sont proposés à la saisie (Pointure, T-shirt, Pull, Chemise, Pantalon, Jean, Robe, Veste, Bague, Gants, Bonnet…) mais le texte libre est accepté. **Visibles de tous les amis**, jamais des autres utilisateurs. Plafond technique de 100 entrées par utilisateur contre les abus.
- **Historique des tailles** : chaque valeur prise par une taille est conservée avec sa date. **Visible du seul utilisateur** ; pour un profil enfant, de ses **seuls gestionnaires** (5.15). Les amis ne voient que la valeur actuelle. Le propriétaire peut retirer une valeur passée de l'historique (détails : section 11, décision 51).
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
- L'utilisateur partage une URL vers l'appli (l'appli Android/iOS figure dans la liste des applis de partage du téléphone), qui ouvre l'écran de création pré-rempli ; le destinataire est **soi-même par défaut**, un ami restant possible (suggestion). Uniquement dans les applis natives : sur le web, ouvrir `/share?url=…` (décision 56).
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
| Nouvel ami via votre lien de partage | Propriétaire du lien (les gestionnaires pour un profil enfant) | in-app, push |
| Lien de partage suspendu (plafond atteint) | Propriétaire du lien (les gestionnaires pour un profil enfant) | in-app, push |
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
- **Profils enfants** : les notifications destinées à un enfant sont envoyées à chacun de ses gestionnaires, avec le nom de l'enfant. Invitation à cogérer un enfant (5.15) : in-app et email à l'invité ; acceptation : in-app à l'autre gestionnaire.
- Les notifications ouvrent l'écran concerné (deep link).
- **Consentement explicite** : écran d'explication avant la demande de permission OS, consentement horodaté par canal (push, email). Sans consentement, aucune notification non essentielle. Désinscription email en un clic. Les emails transactionnels (vérification, mot de passe, export) sont exclus de ce périmètre.
- Push : enregistrement du token à la connexion, suppression à la déconnexion, nettoyage des tokens invalides.

### 5.12 Hors-ligne (utilisation complète avec synchronisation)
Voir section 8.

### 5.13 RGPD
- **Export de mes données** : demande depuis les paramètres, ré-authentification, génération asynchrone d'une archive ZIP (JSON + images téléversées), lien de téléchargement temporaire (48 h) envoyé par email. Contenu : profil, tailles (avec leur historique), préférences, amitiés (pseudos), idées créées (y compris suggestions faites à d'autres), commentaires, réactions, participations, réservations effectuées, consentements. **Exclut** tout élément caché créé par des amis sur ses propres idées (règle d'or).
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

**Principe** : un utilisateur adulte, le **gestionnaire**, crée et gère des profils pour ses enfants. Un profil enfant n'a ni email, ni mot de passe, ni connexion : seuls ses gestionnaires l'utilisent. Un profil enfant a **un ou deux gestionnaires**, aux droits égaux (deuxième gestionnaire : voir plus bas et décision 60). Dans cette section, « le gestionnaire » désigne chacun d'eux.

- **Création** : depuis « Mes enfants » : pseudo (obligatoire), avatar, date d'anniversaire (optionnelle), case de consentement explicite (« je suis titulaire de l'autorité parentale »). Limite de 10 profils enfants par gestionnaire.
- **Contenu du profil** : identique à celui d'un utilisateur (anniversaire, tailles avec unités, préférences), visible des amis de l'enfant. Le gestionnaire tient les tailles et préférences de l'enfant **directement depuis la fiche de l'enfant** (« Mes enfants » → l'enfant), sans avoir à changer de profil actif (décision 58). L'**historique des tailles** de l'enfant n'est visible que de ses gestionnaires, jamais de ses amis (5.2).
- **Agir au nom de l'enfant** : sélecteur de profil dans l'appli ; côté API, en-tête `X-Acting-As: <id>` vérifié par le serveur. Le gestionnaire crée et gère ainsi les idées de l'enfant (publiées ou privées), les marque « reçues », tient son profil (tailles, préférences) et gère ses amis. Ces actions sont attribuées à l'enfant.
- **Le gestionnaire voit tout sur la liste de l'enfant** (« vue gestionnaire ») : idées de l'enfant, suggestions des amis, réservations, commentaires, réactions, cotisations avec participants et total. Les **montants individuels** restent visibles de leur seul auteur et de l'initiateur : le gestionnaire n'en voit que s'il est initiateur ou participant. Il peut aussi réserver, commenter, suggérer, réagir et cotiser sur la liste de son enfant en son propre nom, comme un ami. C'est l'exception à la règle d'or.
- **Amis d'un enfant** : **adultes uniquement** en v1 (comptes avec email). Toute amitié est **initiée par le gestionnaire au nom de l'enfant** (email de l'adulte, contacts, « inviter mes amis » ou lien de partage du profil de l'enfant, voir 5.16). L'adulte reçoit une demande « au nom de [enfant] », avec la mention « profil géré par [gestionnaire] » ; acceptation, refus silencieux et expiration comme en 5.3. Un adulte ne peut pas demander un enfant en ami : les profils enfants sont **exclus de la recherche par email et de la correspondance de contacts**. Le gestionnaire peut retirer un ami de l'enfant, et l'adulte peut retirer l'enfant de ses amis.
- **« Partager avec mes amis »** (option de la fiche de l'enfant, décision 59) : tant qu'elle est active, **tout ami d'un gestionnaire** (actuel ou futur) a accès à l'enfant comme un ami de l'enfant, sans demande à accepter : vue ami de sa liste, tailles, préférences, anniversaire et rappels, réservations, commentaires, cotisations et suggestions comme pour tout ami. Le serveur crée et retire lui-même ces amitiés (origine `family`), sans demande, et l'accès prend fin dès que l'option est désactivée ou que l'amitié avec le gestionnaire prend fin, sauf amitié directe avec l'enfant. L'ami peut retirer l'enfant de ses amis : l'enfant reste alors masqué pour lui, même si l'option reste active. Cochée par défaut à la création d'un enfant, désactivée pour les enfants existants.
- **Deuxième gestionnaire** (décision 60) : depuis la fiche de l'enfant, « Ajouter un gestionnaire » invite un de ses **amis adultes** (compte autonome). L'invité reçoit une notification et un email, et accepte en cochant la même case de consentement qu'à la création (« je suis titulaire de l'autorité parentale ») ; l'invitation expire après 7 jours. Un refus ou une expiration n'est jamais distingué (« en attente », puis disparition). **Deux gestionnaires au plus**, aux **droits égaux** : vue gestionnaire, agir au nom de l'enfant, inviter ou retirer l'autre gestionnaire, rattacher un email, exporter, supprimer le profil. Chacun peut se retirer, sauf le dernier gestionnaire. Un gestionnaire retiré perd tout accès immédiatement (ses appareils effacent les données de l'enfant à la synchronisation suivante). La limite de 10 enfants par gestionnaire compte les enfants cogérés.
- **Rattacher un email (compte autonome)** : un gestionnaire saisit un email ; celui-ci reçoit une invitation valable 7 jours pour définir un mot de passe ou se connecter avec Google/Apple. À la validation, le profil devient un **compte autonome** et conserve tout (idées, amis, tailles, préférences). L'email ne doit appartenir à aucun compte existant (pas de fusion en v1). Effets : l'accès des gestionnaires **prend fin**, l'ancien enfant devient un propriétaire soumis à la règle d'or, et une amitié est créée automatiquement entre chaque ancien gestionnaire et le compte converti (que ce dernier peut retirer). Les accès dérivés de « Partager avec mes amis » prennent fin aussi.
- **Suppression et export** : le gestionnaire exporte les données d'un enfant (le lien va à celui qui le demande) et peut supprimer son profil (14 jours de grâce, comme en 5.13 ; l'autre gestionnaire est prévenu et chacun peut annuler). Si un gestionnaire supprime son propre compte, l'enfant reste à l'autre ; s'il était le seul, la règle de 5.13 s'applique.
- **Hors-ligne** : chaque gestionnaire synchronise aussi les données de ses profils enfants (vue gestionnaire), uniquement sur ses appareils.

### 5.16 Partage du profil par lien

**Principe** : un utilisateur (ou le gestionnaire, pour un profil enfant) crée un **lien de partage**. Quiconque l'ouvre voit les idées publiées de son propriétaire, sans compte et en lecture seule. Pour interagir ou devenir ami, il faut un compte.

**Gestion du lien (propriétaire)**
- Depuis « Mon profil » → « Partager mon profil » (et depuis la fiche d'un enfant pour le gestionnaire). Le partage est **désactivé tant qu'aucun lien n'existe**. Un seul lien actif par propriétaire.
- Actions : créer, copier, partager (feuille de partage du système), **régénérer** (l'ancien lien devient invalide, les amis déjà ajoutés restent amis), **désactiver**.
- À la création, une confirmation avertit : « Toute personne ayant ce lien pourra voir vos idées publiées sans compte et devenir votre ami. » Pour un profil enfant, le texte vise l'enfant et le gestionnaire doit confirmer explicitement.
- Format : `https://ideescadeaux.frigologie.fr/u/<token>`, jeton aléatoire de 128 bits (domaine : décision 53).
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
| **User** | type (regular/managed), managedBy et coManagedBy (profil enfant : un ou deux gestionnaires, décision 60), shareWithManagerFriends (« Partager avec mes amis »), email (nullable pour un profil géré), passwordHash (nullable), displayName, avatarPath, birthDay, birthMonth, birthYear (nullable), locale, timezone, emailVerifiedAt, deletionScheduledAt (nullable) |
| **ManagerInvitation** | child, invitee, invitedBy, expiresAt (création + 7 jours). Une à la fois par enfant, supprimée une fois répondue ou annulée (lot 12) |
| **SocialIdentity** | user, provider (google/apple), providerUserId |
| **Friendship** | requester, addressee, status (pending/accepted/declined/expired), respondedAt, expiresAt (création + 30 jours), onBehalfOfManager (nullable : gestionnaire ayant agi au nom d'un enfant), origin (request/link/conversion/family), shareLink (nullable), removedBy (nullable). Une seule demande active par paire |
| **ShareLink** | owner (utilisateur ou profil enfant), token (aléatoire 128 bits, unique), status (active/revoked), revokedAt, lastUsedAt. Un seul lien actif par propriétaire |
| **ProfileSize** | user, label, value, unit (nullable : pas d'unité ; cm, mm, m, g, kg, ml, l, eu, us, uk), note (nullable), sortOrder |
| **ProfileSizeHistory** | size, value, unit ; `createdAt` = date depuis laquelle la taille a cette valeur. Jamais exposé aux amis |
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
- `ProfileSize` et `ProfilePreference` : CRUD sur mon profil, lecture sur celui d'un ami ; `DELETE /profile_sizes/{id}/history/{entryId}` (retirer une valeur passée de mon historique)
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
- **Idées privées** : synchronisées uniquement vers les appareils de leur auteur. Les données des profils enfants (vue gestionnaire) ne sont synchronisées que vers les appareils de ses gestionnaires.
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

Paiements réels, blocage et signalement d'utilisateurs, groupes d'amis ou listes partagées, chat privé, invitation par QR code, recherche d'ami par pseudo, invitation de non-inscrits par email/SMS, affiliation ou liens marchands, modération de contenu, révélation du donateur après réception, amitiés entre profils enfants, plus de deux gestionnaires par enfant (deux depuis la décision 60), transfert de gestion d'un enfant, connexion autonome d'un enfant avant rattachement d'un email.

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
20. ✅ **Tranché (lot 4) — réaction sur sa propre suggestion** : impossible. « Ses propres idées » (5.9) couvre les idées qu'on a écrites, suggestions comprises (code `reaction.own_idea`).
21. ✅ **Tranché (lot 4) — historique de cotisation** : sans cotisation ouverte, la fiche et la liste (vue ami) montrent la dernière cotisation clôturée (total, participants). Elle n'empêche ni réservation ni nouvelle cotisation (5.7).
22. ✅ **Tranché (lot 4) — suppression « définitive » des interactions** : suppression logique immédiate (irrécupérable pour les utilisateurs, propagée aux appareils par tombstones), puis effacement physique par une tâche quotidienne 30 jours après, aligné sur la rotation des sauvegardes. Concerne réservations, commentaires, réactions, cotisations et participations, quelle que soit la cause (retour en privé, annulation, retrait d'ami). (Précision lot 6 : avec l'inventaire de la décision 32, un curseur ancien ne pose pas de problème.)
23. ✅ **Tranché (lot 4) — routes « à moi »** : `PUT|DELETE /ideas/{id}/reaction` (mon j'aime) et `PUT|DELETE /contributions/{id}/pledge` (ma participation), bascules idempotentes sans identifiant à suivre côté client. Les autres ressources du lot 4 suivent le style du §7 (`/reservations`, `/contributions`, `/comments`).
24. ✅ **Tranché (lot 4 bis) — export et suppression d'un profil enfant** : la suppression (14 jours de grâce, annulation, suppression définitive planifiée) arrive au lot 4 bis ; l'export (archive ZIP asynchrone, lien 48 h) est construit une seule fois au lot 8, pour les adultes comme pour les enfants.
25. ✅ **Tranché (lot 4 bis) — périmètre de `X-Acting-As`** : au nom de l'enfant, le gestionnaire gère sa liste (idées publiées ou privées, « reçue »), son profil (pseudo, avatar, anniversaire, tailles, préférences) et ses amis (demandes, réponses, retraits, contacts), et consulte les listes de ses amis. Pas de réservation, commentaire, réaction, cotisation ni suggestion au nom de l'enfant (`acting_as.not_allowed`) ; le gestionnaire peut les faire en son propre nom sur la liste de l'enfant (5.15). Liste blanche côté serveur : tout nouvel endpoint est refusé par défaut en mode « au nom de ».
26. ✅ **Tranché (lot 4 bis) — validation du rattachement d'email** : code à 6 chiffres envoyé à l'adresse invitée (valable 7 jours, 5 essais), saisi dans l'écran « J'ai une invitation » avec un mot de passe, ou avec une connexion Google/Apple sur la même adresse. La réponse au gestionnaire ne dit jamais si l'adresse a déjà un compte ; c'est l'invité qui l'apprend en validant.
27. ✅ **Tranché (lot 5) — push** : vrai client FCM HTTP v1 (compte de service, nettoyage des tokens invalides), inactif tant que `FCM_PROJECT_ID` / `FCM_SERVICE_ACCOUNT_JSON` sont vides ; le reste du circuit (in-app, email, préférences) fonctionne sans projet Firebase.
28. ✅ **Tranché (lot 5) — consentement in-app** : pas de consentement pour le centre de notifications (toujours actif, réglable par type) ; le consentement horodaté concerne push et email.
29. ✅ **Tranché (lot 5) — délais des rappels d'anniversaire** : de 0 à 3 délais, chacun de 0 à 30 jours (0 = le jour même), [14, 2] par défaut, liste vide = désactivés ; réglage global.
30. ✅ **Tranché (lot 5) — désinscription email en un clic** : le lien coupe l'email pour ce type de notification ; la page propose aussi « tous les emails de notification » (retrait du consentement email). Les emails transactionnels ne sont jamais concernés. L'ouverture du lien (GET) ne change rien ; seul l'envoi (POST, dont RFC 8058) désinscrit.
31. ✅ **Tranché (lot 5) — notifications au gestionnaire** : la spécification prime sur la maquette Notifications. Le gestionnaire n'est pas prévenu de l'activité secrète sur la liste de son enfant (le propriétaire, l'enfant, étant exclu) ; il l'est comme tout participant s'il a lui-même réservé, commenté ou cotisé. Les notifications destinées à l'enfant (demande acceptée, rappels…) lui parviennent, marquées « Pour X ».
32. ✅ **Tranché (lot 6) — protocole de synchronisation** : `POST /sync` renvoie le delta depuis le curseur (documents dans leur vue API) et, quand l'empreinte d'un type diffère de celle de l'appareil, l'inventaire complet ids + versions de ce type ; l'appareil purge ce qui n'y figure plus et récupère ce qui manque (`POST /sync/fetch`). Remplace les tombstones du §8 : le serveur ne nomme jamais un objet que l'utilisateur ne peut pas voir, ce qui couvre sans fuite les retraits de visibilité (repassage en privé, retrait d'ami, rattachement d'un email). Écritures rejouables avec `Idempotency-Key` (réponse conservée 48 h).
33. ✅ **Tranché (lot 6) — hors-ligne des profils enfants** : sur les appareils du gestionnaire, la liste de l'enfant (vue gestionnaire), ses tailles et préférences, ses amitiés. Consulter les listes des amis de l'enfant en son nom reste en ligne.
34. ✅ **Tranché (lot 6) — photo créée hors-ligne** : gardée sur l'appareil et envoyée au retour du réseau, après la création de l'idée.
35. ✅ **Tranché (lot 6) — actions hors-ligne** : idées (créer, modifier, publier, repasser en privé, archiver, supprimer), réservations, cotisations et participations, commentaires, j'aime, pseudo et anniversaire, tailles et préférences, lecture des notifications. En ligne seulement, avec un message clair : amis (demandes, réponses, retrait, contacts), profils enfants, compte, réglages de notifications.
36. ✅ **Tranché (lot 7) — aperçu de lien** : `POST /link-previews` répond de façon synchrone (5 s maximum) plutôt que par une tâche Messenger (§2), pour que « Pré-remplir » réponde tout de suite. L'image est renvoyée redimensionnée (data URL JPEG) et n'est copiée sur notre stockage que si l'utilisateur la garde, par l'envoi d'image ordinaire (hors-ligne compris). Même réponse `link_preview.unavailable` pour une adresse interne refusée et un site injoignable. Les valeurs trouvées ne remplissent que les champs encore vides.
37. ✅ **Tranché (lot 7) — partage reçu hors-ligne** (§5.6) : l'idée est enregistrée en **brouillon privé** par défaut (modifiable), avec pour titre provisoire le nom du site ; après la synchronisation suivante, l'aperçu complète titre (s'il est resté provisoire), prix et image s'ils sont vides. Les amis ne voient donc rien avant relecture.
38. ✅ **Tranché (lot 7) — mécanisme de partage natif** : un seul point d'entrée, le lien `<identifiant d'app>://share?url=…&text=…&title=…`. Android : filtre d'intent `ACTION_SEND` (texte), réécrit par `MainActivity`. iOS : Share Extension sans interface propre qui ouvre l'appli via ce lien (passage par la chaîne de répondeurs, API non officielle pour une extension : à surveiller à la revue App Store ; repli possible par App Group).
39. ✅ **Tranché (lot 7 bis) — lien suspendu** (§5.16, plafond anti-abus) : le plafond compte les amitiés créées par les liens du propriétaire sur les dernières 24 h glissantes, régénérations et retraits compris. La 51ᵉ tentative suspend le lien : il ne fonctionne plus du tout (vue invité comprise, réponse générique « lien invalide ou expiré ») jusqu'à ce que le propriétaire le **régénère** ; le propriétaire est notifié une fois par lien.
40. ✅ **Tranché (lot 7 bis) — invitation en attente** (§5.16) : conservée **sur l'appareil** (7 jours), pas sur le serveur, qui ne voit le jeton qu'à l'ouverture de l'écran de confirmation. Après connexion (et onboarding), l'écran de confirmation s'affiche ; « Pas maintenant » l'oublie. Hors ligne, elle est mise de côté et rouverte au retour du réseau.
41. ✅ **Tranché (lot 7 bis) — images de la vue invité** : servies par le back à `/u/<jeton>/media/…`, URL signée (HMAC) valable 1 h, qui revérifie à chaque requête que le lien est actif et que l'idée fait partie de la vue invité ; désactiver ou régénérer le lien coupe aussi les images. Les fichiers restent lisibles publiquement dans le stockage pour l'appli (URL non devinables) : le passage du stockage en privé (URL signées aussi dans l'appli) est planifié au lot 8, avant la production.
42. ✅ **Tranché (lot 7 bis) — cas particuliers du lien** : un retrait par le propriétaire est définitif pour le lien (régénérations comprises) ; le gestionnaire qui ouvre le lien de son enfant est renvoyé vers la fiche de l'enfant ; un lien est refusé tant que le compte du propriétaire est en période de grâce avant suppression. API : `GET|POST|DELETE /share-link` et `POST /share-link/regenerate` (propriétaire, `X-Acting-As` autorisé), `GET /share-links/{jeton}` (vue invité, public), `GET /share-links/{jeton}/invitation` et `POST /share-links/{jeton}/join` (adultes connectés).
43. ✅ **Tranché (lot 7 bis) — ouverture dans l'appli** : « Ouvrir dans l'appli » de la page web utilise `<identifiant d'app>://u/<jeton>` (fonctionne sans domaine). Les liens `https://<domaine>/u/…` ouvrent l'appli par App Links (Android : filtre prêt, hôte en propriété Gradle `shareLinkHost`) et Universal Links (iOS : entitlement Associated Domains à ajouter avec le domaine définitif et l'équipe Apple, lot 8) ; le back sert `/.well-known/assetlinks.json` et `apple-app-site-association` dès que `ANDROID_CERT_FINGERPRINTS` / `APPLE_TEAM_ID` sont renseignés.
44. ✅ **Tranché (lot 8) — compte en période de grâce** (§5.13) : la connexion (mot de passe ou Google/Apple) reste possible, mais le compte n'atteint plus que son profil, l'annulation, l'export et la déconnexion (sinon 403 `account.deletion_scheduled`) ; l'appli n'affiche que l'écran « Votre compte sera supprimé le … ». Une inscription avec le même email répond `auth.account_deletion_scheduled` (« connectez-vous pour annuler »). Le lien de l'email demande une confirmation (GET) avant d'annuler (POST) et ne vaut que pour cette demande-là.
45. ✅ **Tranché (lot 8) — ré-authentification** (§5.13, export et suppression) : mot de passe si le compte en a un, sinon code à 6 chiffres par email (15 min, 5 essais) ; 10 tentatives par 15 minutes.
46. ✅ **Tranché (lot 8) — suppression définitive** : une cotisation initiée sur la liste d'un autre est clôturée et perd son initiateur (affiché « un ancien membre »), les participations des autres restent ; les notifications qui nomment la personne sont retirées de tous les centres de notifications ; ses profils enfants partent avec le compte ; email d'information (au gestionnaire pour un profil enfant supprimé seul).
47. ✅ **Tranché (lot 8) — contenu de l'export** : fichiers JSON (profil, tailles, préférences, amis et demandes, idées écrites dont suggestions, commentaires, réactions, réservations, participations et cotisations initiées, consentements) et images (avatar, images de ses idées). Uniquement ce que la personne a écrit ou fait : jamais le montant d'un autre participant, statut de demande vu comme demandeur (`declined` masqué). Archive privée, lien par email valable 48 h (jeton haché), 3 exports par jour, export encore possible pendant la période de grâce. Pour un enfant, le lien va au gestionnaire.
48. ✅ **Tranché (lot 8) — images privées** (décision 41) : URL signées servies par le back, identiques pendant une semaine (cache) et valables 7 à 14 jours ; la version de synchro des documents qui contiennent des images change chaque semaine, les appareils récupèrent donc de nouvelles URL avant expiration (un re-téléchargement hebdomadaire de ces documents). Une URL qui fuite reste utilisable 14 jours au plus.
49. ✅ **Tranché (lot 8) — observabilité** (§9) : identifiant `X-Request-Id` sur chaque requête (fourni par l'appli ou généré) dans chaque ligne de log JSON ; l'appli remonte ses erreurs non gérées à `POST /client-errors` (message, pile, nom d'écran, plateforme, version ; 20 par session, jamais de chemin ni de donnée utilisateur). Aucun service tiers imposé : celui choisi se branche sur les logs.
50. ✅ **Tranché (lot 8) — politique de confidentialité** : page `/privacy` servie par le back (fr/en), ouverte depuis Profil et l'inscription ; brouillon à compléter (éditeur, contact, hébergeurs, durée des logs) et à faire relire par un juriste. Fiches stores et textes de permissions dans `docs/stores/`.
51. ✅ **Tranché — historique des tailles** (§5.2, §5.15) : chaque valeur prise par une taille est conservée avec sa date (« depuis le … »), la dernière étant la valeur actuelle. **Tout est conservé** : aucune fusion ni correction automatique, même pour deux changements rapprochés. **Visible du seul propriétaire** ; pour un profil enfant, de son **seul gestionnaire**, qu'il agisse au nom de l'enfant ou non. Les amis ne lisent que la valeur actuelle : le champ `history` est absent pour eux de l'API comme de `/sync`, et ne figure que dans l'export du propriétaire (celui d'un enfant va à son gestionnaire). L'historique fait partie du document de la taille (donc disponible hors-ligne) et disparaît avec elle. Seul un changement de **valeur** l'alimente (pas le libellé, la note ni l'ordre). Le propriétaire peut **retirer une valeur passée** de l'historique, définitivement (`DELETE /profile_sizes/{id}/history/{entryId}`, `X-Acting-As` autorisé ; 404 pour toute autre personne, ami compris) ; la valeur actuelle ne se retire pas (`profile_size.history_current`) et une entrée ne se modifie pas. Hors-ligne, le retrait est mis en file comme les autres écritures ; une valeur changée hors-ligne ne peut être retirée qu'après synchronisation. Les tailles existantes démarrent leur historique avec leur valeur actuelle, datée de leur création. Au rattachement d'un email, l'historique suit le profil : l'ancien enfant le voit, l'ancien gestionnaire, devenu ami, ne le voit plus (ses appareils le retirent à la synchronisation suivante). Dans l'appli : une taille devient modifiable (libellé, valeur, note), et l'action « Historique » apparaît dès qu'elle a changé de valeur, avec la mention « Visible de vous seul ».
52. ✅ **Tranché — stockage des images** (§2) : MinIO ne publie plus d'images Docker. En production, un stockage objet S3 hébergé dans l'UE (Scaleway ou OVH, bucket privé, durabilité assurée par l'hébergeur) ; en développement, Garage (serveur S3 open source) dans la stack Docker. Sauvegarde quotidienne de la base (`pg_dump`, rétention 30 jours) dans la stack de production (docs/DEPLOIEMENT.md).
53. ✅ **Tranché — domaine** : `ideescadeaux.frigologie.fr` est le domaine définitif (liens de partage `https://ideescadeaux.frigologie.fr/u/…`, App Links / Universal Links, page `/privacy`, API). Il ne dépend pas du nom définitif de l'appli : le changer casserait les liens déjà partagés.
54. ✅ **Tranché — accessibilité** (§9) : tailles de texte en `rem` (elles suivent la taille de texte du système ; sur Android, la WebView applique déjà l'échelle système) ; mises en page qui passent à la ligne ou défilent au lieu de tronquer, vérifiées à 200 %. Libellés pour lecteurs d'écran : icônes décoratives masquées, boutons à icône nommés, puces filtres utilisables au clavier, zoom autorisé. Contrastes : textes ≥ 4,5:1 ; contours des champs à 3:1 (WCAG 1.4.11), plus foncés que sur les maquettes (`--ic-border-field`), comme la bordure de la zone secrète. Contrôle automatique axe-core (WCAG 2.1 AA) sur les écrans principaux dans les tests e2e.
55. ✅ **Tranché — nom et identifiant** : « Idées Cadeaux » est le nom définitif ; identifiant d'app `fr.frigologie.ideescadeaux` (bundle ID iOS, `applicationId` Android, schéma `fr.frigologie.ideescadeaux://`), tiré du domaine de la décision 53. Complète la décision 11.
56. ✅ **Tranché (lot 9) — appli web, point d'entrée principal** : l'appli Ionic Vue existante est aussi publiée en version web, à la racine de `https://ideescadeaux.frigologie.fr/` (l'API `/api`, `/privacy`, `/media` et les liens des emails restent servis par le back) ; création de compte et toutes les fonctions comprises. Remplace l'exclusion « version web complète » du §10. Sur le web : connexion conservée dans un cookie `httpOnly` (jeton de rafraîchissement, illisible par le JavaScript ; jeton d'accès en mémoire), en ligne uniquement (aucune copie locale dans le navigateur ; une écriture hors ligne est refusée avec un message clair), notifications dans l'onglet Activité et par email (pas de push web), connexion Google/Apple reportée, fonctions propres au téléphone masquées (contacts, partage depuis une autre appli), mise en page mobile centrée sur grand écran. Un lien `/u/…` ouvert dans un navigateur affiche la vue invité de l'appli web (création de compte et « Devenir ami » sur place) ; l'aperçu dans les messageries (pseudo seul) reste produit par le serveur. Ordre des lots : … 8, puis 9 (appli web).

57. ✅ **Tranché (lot 11) — unités des tailles** (§5.2) : chaque taille a une unité choisie dans une liste fixe : **pas d'unité** (par défaut), cm, mm, m, g, kg, ml, L, et les systèmes de pointure et de vêtement EU, US, UK. Codes stables côté API (`null`, `cm`, `mm`, `m`, `g`, `kg`, `ml`, `l`, `eu`, `us`, `uk`, erreur `profile_size.unit_invalid` sinon), libellés traduits dans l'appli. Affichage après la valeur (« 80 cm », « 42 EU »). Les tailles existantes passent à « pas d'unité » sans perdre leur valeur : l'utilisateur peut en choisir une plus tard. Aucune unité n'est déduite du libellé. L'historique garde l'unité de chaque valeur ; changer **seulement l'unité** ne crée pas d'entrée d'historique (correction de la valeur actuelle). Unité modifiable hors-ligne, présente dans `/sync`, l'export et la vue ami ; jamais dans la vue invité (pas de tailles).
58. ✅ **Tranché (lot 11) — tailles des enfants** (§5.15) : les tailles d'un enfant existaient déjà, mais n'étaient accessibles qu'en changeant de profil actif dans l'onglet Profil. La fiche de l'enfant affiche désormais ses tailles et préférences, modifiables directement (mêmes écrans et mêmes règles, `X-Acting-As`, historique compris). Rien ne change côté serveur.
59. ✅ **Tranché (lot 12) — « Partager avec mes amis »** (§5.15) : option de la fiche d'un enfant. Active, elle donne aux **amis acceptés de chaque gestionnaire** (actuels et futurs) l'accès d'un ami à l'enfant, sans demande : le serveur crée lui-même une amitié acceptée d'origine `family` entre l'enfant et chacun d'eux, et la retire quand elle n'a plus lieu d'être (option désactivée, amitié avec le gestionnaire terminée, gestionnaire retiré, enfant en suppression ou rattaché à un email). Toutes les règles existantes s'appliquent donc telles quelles (listes, `/sync`, notifications, rappels d'anniversaire). Une amitié ou une demande déjà active entre eux prime. Dans « Amis », l'enfant apparaît avec la mention « enfant de [gestionnaire] ». Le gestionnaire voit ces personnes dans la liste des amis de l'enfant, marquées « via vos amis », sans pouvoir les retirer une à une (désactiver l'option, ou retirer l'amitié avec lui). L'option désactivée ou l'amitié avec le gestionnaire terminée, l'accès prend fin (404 ensuite), sauf amitié directe avec l'enfant ; réservations, cotisations et commentaires déjà faits suivent la règle d'un ami retiré (5.3). Un ami qui retire l'enfant de ses amis le garde masqué tant qu'il ne l'ajoute pas à nouveau. Cochée par défaut à la création, désactivée pour les enfants existants (choix explicite du gestionnaire). Suite de visibilité : ami d'un gestionnaire sans l'option → 404 ; avec → vue ami (jamais la vue gestionnaire, ni l'historique des tailles) ; après désactivation ou fin de l'amitié → 404 ; jamais pour un ami d'un ami ni pour un invité.
60. ✅ **Tranché (lot 12) — deuxième gestionnaire** (§5.15) : remplace « un seul gestionnaire » (décision 15) et l'exclusion « plusieurs gestionnaires par enfant » du §10. Deux gestionnaires au plus, aux droits égaux (y compris retirer l'autre, rattacher un email, supprimer le profil). Invitation réservée aux **amis adultes** du gestionnaire qui invite, acceptée avec la case de consentement « titulaire de l'autorité parentale », valable 7 jours, une seule invitation en cours par enfant ; refus et expiration non distingués pour l'inviteur. Les deux ont la vue gestionnaire (exception à la règle d'or), l'historique des tailles et `X-Acting-As` ; les **montants individuels** d'une cotisation restent visibles du seul auteur et de l'initiateur. Notifications destinées à l'enfant : à chacun des gestionnaires. Les demandes d'ami au nom de l'enfant portent « profil géré par [A] et [B] ». Gestionnaire retiré ou qui se retire : perte d'accès immédiate, données de l'enfant retirées de ses appareils à la synchronisation ; s'il est ami de l'autre gestionnaire et que l'option « Partager avec mes amis » est active, il garde la vue ami de l'enfant, comme tout ami de ce gestionnaire. Modèle : `User.managedBy` (premier gestionnaire) et `User.coManagedBy` (second), invitations dans `ManagerInvitation` ; si le premier part, le second devient premier. API : `POST|DELETE /managed-profiles/{id}/manager-invitation`, `DELETE /managed-profiles/{id}/managers/{managerId}`, `GET /manager-invitations`, `POST /manager-invitations/{id}/accept|decline` ; notifications `manager_invitation_received` (in-app, push, email) et `manager_invitation_accepted`. Suite de visibilité : invité en attente → rien de la vue gestionnaire ; deuxième gestionnaire actif → vue gestionnaire complète sauf montants des autres ; gestionnaire retiré → 404.
61. ✅ **Tranché (lot 10) — icônes de l'appli** : les icônes définitives (cube-cadeau bleu, ruban rouge, ampoule jaune sur fond prune) sont versionnées dans `docs/design/icones/` (sources SVG et exports). Elles remplacent les icônes par défaut de Capacitor : icône adaptative Android (`mipmap-*`) et icône de repli, `AppIcon` iOS (1024 px, sans transparence), favicon (SVG, ICO), `apple-touch-icon`, icônes et manifeste de l'appli web (`site.webmanifest`, icônes « maskable »), icône 512 px de la fiche Google Play (`docs/stores/`). Non inclus : variantes sombre/teintée d'iOS 18 et icône monochrome d'Android 13. Ordre des lots : … 9, puis 10 (icônes et vérification native), 11 (tailles), 12 (famille).

---

## 12. Plan de livraison (lots)

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
| **9. Appli web** | L'appli Ionic servie à la racine du domaine, session en cookie `httpOnly`, en ligne uniquement, fonctions du téléphone masquées (décision 56) | Parcours complet dans un navigateur, e2e verts |
| **10. Icônes et vérification native** | Icônes définitives sur Android, iOS et le web, manifeste web (décision 61) ; build Android de production installé sur un téléphone ou un émulateur : l'appli figure dans la liste de partage, un lien partagé ouvre la création d'idée pour soi-même, pré-remplie (§5.6) | Icônes visibles sur le lanceur Android, l'onglet du navigateur et l'écran d'accueil ; idée créée depuis un partage Android |
| **11. Tailles** | Unités des tailles avec « pas d'unité » par défaut et migration des tailles existantes (décision 57) ; tailles et préférences de l'enfant sur sa fiche (décision 58) | Tailles existantes intactes, unité choisie après coup ; tests back et front, hors-ligne compris |
| **12. Famille** | « Partager avec mes amis » sur un profil enfant (décision 59) ; deuxième gestionnaire : invitation, acceptation, droits égaux, retrait, notifications (décision 60) | Suite de visibilité verte avec les nouveaux cas (accès dérivé, deuxième gestionnaire, gestionnaire retiré) ; parcours e2e à deux parents |

---

## 13. Instructions pour Claude Code

- Un `CLAUDE.md` est fourni avec ce document, à la racine du dépôt ; ce document est `docs/SPECIFICATIONS.md`.
- Avancer **lot par lot**, en mettant à jour les tests à chaque étape. Ne pas passer au lot suivant si la suite de visibilité est rouge.
- En cas d'ambiguïté ou de conflit avec une décision de la section 11, **poser la question** plutôt que d'inventer.
- Ne jamais implémenter de paiement réel ni stocker de contacts du téléphone.
