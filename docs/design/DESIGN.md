# Maquettes — Application « Idées Cadeaux »

**Statut :** maquettes de référence (v1, écrans clés), 22 septembre 2026.
**Pour :** Claude Code. À lire avec `docs/SPECIFICATIONS.md`, qui reste la référence fonctionnelle.

Ces maquettes donnent la **direction visuelle, la hiérarchie et le contenu** de chaque écran.
Ce ne sont **pas** du code à recopier : on implémente avec les composants **Ionic Vue**
(`ion-header`, `ion-tabs`, `ion-list`, `ion-item`, `ion-segment`, `ion-chip`, `ion-button`, `ion-input`…),
les textes via **vue-i18n** (aucun texte en dur) et les couleurs via le fichier de thème.

En cas de conflit entre une maquette et la spécification, **la spécification gagne** : signaler l'écart plutôt que d'inventer.

---

## 1. Contenu du dossier

| Dossier | Contenu | Usage |
|---|---|---|
| `captures/` | Une image PNG par écran (390 px de large, @2x) | Rendu attendu |
| `maquettes/` | Le même écran en HTML statique, styles en ligne | Valeurs exactes : couleurs, tailles, espacements, rayons |
| `sources/` | Fichiers `.dc.html` d'origine (canevas de design) | Archive, pour retoucher les maquettes |
| `theme/variables.css` | Proposition de variables Ionic tirées des maquettes | Point de départ de `src/theme/variables.css` |

> Les captures ont été générées sans accès à Google Fonts : les titres y apparaissent en police de repli
> (Georgia / sans-serif). Le rendu réel utilise **Young Serif** et **Figtree** (voir § 2).

---

## 2. Identité visuelle (provisoire)

Direction « papeterie chaleureuse » : fond crème, serif éditoriale pour les titres, accent terracotta.
Le nom et l'identité définitifs seront décidés plus tard (spec § 2) : **tout doit passer par les variables du thème**.

### Couleurs

| Rôle | Hex | Usage |
|---|---|---|
| Fond | `#F6F0E6` | Fond des écrans |
| Surface | `#FFFCF7` | Cartes, barres, champs |
| Surface atténuée | `#EDE4D6` | Segments, pastilles neutres, encarts |
| Texte | `#2B211C` | Texte principal, boutons sombres |
| Texte secondaire | `#6A5D53` | Métadonnées, légendes |
| Bordure | `#E4D8C6` | Bordures de cartes et de champs |
| **Primaire (terracotta)** | `#A94A2A` | Actions principales, onglet actif, bouton « + » |
| Primaire clair | `#F5E1D6` | Encarts d'information |
| Succès (sauge) | `#3D6A4C` / `#DEEADF` | « Réservé », « Synchronisé », confirmations |
| **Secret (prune)** | `#5E3F6E` / `#EFE6F2` / bordure `#A488B2` / fond de zone `#FBF7FC` | Tout ce qui est invisible du propriétaire (§ 3) |
| Danger | `#9E2F2F` | Suppression, retrait |

### Typographie

- **Titres :** Young Serif (400), repli Georgia. Titre d'écran 34 px, titre de détail 22–28 px, grands montants 30–48 px.
- **Texte :** Figtree (400 à 700), repli sans-serif système. Corps 15–16 px, métadonnées 13–14 px, titres de section 13 px en capitales avec espacement de 0,06em.
- Respecter les tailles de texte dynamiques (spec § 9) : ces valeurs sont une base, pas des tailles figées.

### Formes et espacements

- Marge latérale : 20 px. Espacement entre cartes : 10 px. Entre sections : 16 à 20 px.
- Rayons : cartes 16 px, grands blocs 20 px, champs 12 px, boutons 14 px, pastilles et puces arrondies à 999 px.
- Zone tactile minimale : **44 px** ; boutons pleine largeur : 48 px de haut.
- Icônes : trait fin (style Lucide, épaisseur 1,75 à 2), 18 à 22 px. `lucide-vue-next` ou `ionicons` en contour conviennent.
- Avatars : initiales sur une pastille colorée quand il n'y a pas de photo.

---

## 3. Convention clé : la « zone secrète » (règle d'or)

Tout élément **invisible du propriétaire** (réservation, suggestion, cotisation, commentaire, réaction) est signalé
en **prune**, avec l'icône « œil barré » :

- **Bandeau secret** (`secret_band`) : fond `#EFE6F2`, texte `#5E3F6E`, icône œil barré. Exemple : « Réservations, suggestions… restent invisibles pour Camille ».
- **Zone secrète** : bloc à bordure pointillée `#A488B2`, fond `#FBF7FC`, titré « Entre amis · invisible pour X ». Il regroupe la cotisation, les réactions et les commentaires de la fiche d'une idée.
- **Pastilles d'état** en prune : « Cotisation · 110 / 180 € », « Suggérée par Léa » (bordure pointillée).

Règles d'implémentation :

1. Cette convention visuelle ne sert **qu'aux amis** et au **gestionnaire** d'un profil enfant.
   **La vue propriétaire n'affiche aucun de ces éléments**, ni bandeau, ni zone vide, ni compteur (voir `Main.png`).
2. Le masquage vient **du serveur** (spec § 4). Le client ne masque rien lui-même : il affiche ce que l'API renvoie.
   Un composant `SecretZone` ne s'affiche donc que si des données cachées sont présentes dans la réponse.
3. Les **brouillons privés** utilisent une autre convention : bordure pointillée neutre `#E4D8C6`, icône cadenas, mention « Visible de vous seul ».

---

## 4. Écrans et correspondance avec la spécification

Navigation principale : barre d'onglets **Ma liste · Amis · [+] · Activité · Profil**. Le bouton central « + » ouvre la création d'une idée.

| Capture | Écran | Spec | Points d'attention |
|---|---|---|---|
| `Main.png` | Ma liste (vue **propriétaire**) | 5.4, 4 | Segments Publiées / Brouillons / Archives, filtre par occasion, tri. **Aucun** statut, compteur ni réaction. Encart pédagogique « La surprise est préservée », masquable. Badge de synchro (§ 8). |
| `ListeAmi.png` | Liste d'un ami (vue **ami**) | 5.4, 5.7, 5.10 | En-tête avec anniversaire (J-x), « Tailles et goûts », « Suggérer ». Sections : ses idées (statut réservé / cotisation + j'aime + commentaires), suggestions des amis, **Mes brouillons pour X** (visible de moi seul), archives. |
| `FicheIdee.png` | Détail d'une idée (vue ami) | 5.4, 5.8, 5.9, 5.10 | Infos publiques en haut, **zone secrète** en dessous : cotisation, « J'aime », « Marquer offert », fil de commentaires plat, saisie. Idée non réservée : afficher « Je l'offre » et « Cotiser à plusieurs ». |
| `Cotisation.png` | Cotisation | 5.10, 5.7 | Total, cible, reste à couvrir, participants par nom. **Le montant individuel n'est visible que pour soi** (et pour l'initiateur) : les autres voient « montant privé ». Mention « montants déclaratifs, aucun paiement ». |
| `NouvelleIdee.png` | Nouvelle idée / suggestion | 5.4, 5.5, 5.6 | Choix du destinataire (Moi ou un ami ; un ami = suggestion, avec bandeau secret). Lien et bouton « Pré-remplir » : les valeurs sont **proposées**. Titre (120 max), prix + devise, image, note (2000 max), occasion facultative (« Aucune » par défaut), visibilité Publiée / Brouillon privé. |
| `Amis.png` | Amis | 5.3 | Ajout par email, contacts ou lien. Demandes reçues (Accepter / Refuser). Liste avec pastille d'anniversaire proche. Demandes envoyées « En attente », avec Annuler (jamais « refusée »). |
| `Notifications.png` | Activité | 5.11, 5.15 | Groupes Aujourd'hui / Cette semaine, pastille non lue, deep link vers l'écran concerné. Notifications d'un profil enfant marquées « Pour Jules ». Accès aux préférences. |
| `Profil.png` | Profil | 5.2, 5.15, 5.16, 5.13 | Sélecteur **Profil actif** (moi ou un enfant, `X-Acting-As`). Accès au partage du profil. Tailles réordonnables par glisser, préférences par catégorie, « Mes enfants », paramètres (notifications, langue, export, confidentialité, déconnexion, suppression du compte). |
| `PartageLien.png` | Partager mon profil | 5.16 | Statut du lien, URL (`[DOMAINE]` provisoire), Copier / Partager, ce que voit un visiteur, Régénérer (avec explication), Désactiver. |
| `VueInvite.png` | Vue invité dans l'appli | 5.16 | Pseudo, avatar, idées personnelles publiées **uniquement**. Les actions (Je l'offre, J'aime, Commenter) mènent à « Créer un compte pour interagir ». Aucun statut ni compteur. La page web Twig reprend la même présentation. |
| `ConfirmationAmi.png` | Devenir ami via un lien | 5.16 | Confirmation explicite obligatoire. Pour un profil enfant, ajouter « profil géré par [gestionnaire] ». |

---

## 5. Écrans non maquettés (à dériver de ce style)

Inscription, connexion, vérification d'email, mot de passe oublié, onboarding et consentement aux notifications,
vue gestionnaire d'un profil enfant, création d'un profil enfant et rattachement d'un email, écran « Privées » (tous
mes brouillons groupés par destinataire), archives, tailles et préférences d'un ami, préférences de notifications,
export RGPD, suppression du compte (écran « Votre compte sera supprimé le … »), états hors-ligne (actions en attente,
erreurs de synchro, conflit « déjà réservé par X »), états vides et chargements.

Réutiliser les mêmes composants, couleurs et conventions. Pour un écran important, proposer d'abord une structure
avant de l'implémenter.

---

## 6. Composants à extraire

`IdeaCard` (variantes propriétaire, ami et invité), `StatusPill` (réservé, cotisation, suggestion, brouillon),
`SecretBand`, `SecretZone`, `ContributionProgress`, `Avatar` (photo ou initiales), `OccasionChips`,
`SectionTitle`, `SyncBadge`, `TabBar` avec bouton central.
