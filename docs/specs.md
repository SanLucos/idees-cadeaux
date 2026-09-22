# Spécifications — Idées Cadeaux

## 1. Présentation
Application mobile (iOS/Android) d'idées cadeaux entre amis. Chaque utilisateur peut
proposer des idées de cadeaux pour ses amis et suivre l'organisation autour de ces idées
(réservation, commentaires, cagnotte), sans que la personne concernée ne puisse jamais
voir ce qui se prépare pour elle.

## 2. Stack technique
| Couche | Choix |
|---|---|
| Back-end | API Platform (PHP/Symfony) |
| Front-end web/logique | Vue.js |
| App native | Ionic Vue + Capacitor |
| Mode hors-ligne | Complet, avec synchronisation au retour réseau |
| i18n | Prévue dès le départ, langue initiale : français |

## 3. Comptes & authentification
- Connexion obligatoire pour accéder à l'app.
- Deux méthodes de connexion :
  - Email + mot de passe
  - Connexion sociale (fournisseur à préciser)
- Gestion classique : inscription, mot de passe oublié, session persistante.

## 4. Réseau d'amis
- Ajout d'un ami par demande envoyée à un autre utilisateur.
- L'ami doit accepter la demande pour que la relation soit établie (relation mutuelle,
  pas un suivi à sens unique).
- Un utilisateur ne voit les idées cadeaux, réservations, commentaires et cagnottes que
  de ses amis confirmés (cf. règle de confidentialité ci-dessous).

## 5. Idées cadeaux
- Un utilisateur peut créer des idées cadeaux pour lui-même (souhaits personnels).
- Un ami peut créer une idée cadeau à destination d'un autre ami (suggestion de cadeau).
- Chaque idée peut inclure : titre, description, lien/prix éventuel, image.

### Règle de confidentialité (essentielle)
- Idées personnelles d'un utilisateur → visibles par **tous ses amis**.
- Idées suggérées par des amis **pour** un utilisateur → **jamais visibles** par
  l'utilisateur concerné.
- Réservations, commentaires et cagnottes sur une idée destinée à un utilisateur →
  **jamais visibles** par cet utilisateur.
- Résumé : chaque utilisateur voit tout ce qui concerne ses amis, rien de ce qui le
  concerne lui-même (hormis ses propres idées personnelles, visibles par ses amis).
- Cette règle doit être garantie côté API (filtrage des données servies), pas uniquement
  par l'interface.

## 6. Réservation
- Un ami peut "réserver" une idée cadeau pour signaler qu'il s'en occupe.
- Une idée réservée reste invisible pour le destinataire, comme le reste des idées qui le
  concernent.
- Les autres amis (hors destinataire) voient qui a réservé, pour éviter les doublons.

## 7. Commentaires
- Les amis peuvent commenter une idée cadeau (coordination, avis, etc.).
- Les commentaires sur une idée destinée à un utilisateur restent invisibles pour lui.

## 8. Cagnottes / cotisations
- Possibilité d'organiser une cagnotte autour d'une idée cadeau (cadeau à plusieurs).
- Suivi des participations/cotisations.
- Invisible pour le destinataire de l'idée concernée.

## 9. Mode hors-ligne
- L'app doit être utilisable intégralement sans connexion réseau.
- Synchronisation automatique des données au retour de la connexion.
- Gestion des conflits de synchronisation à définir lors de l'implémentation.

## 10. Internationalisation
- Architecture i18n mise en place dès le départ.
- Contenu initial en français uniquement, structure prête pour ajouter d'autres langues.

## 11. Points à préciser (au fil du développement)
- Fournisseur(s) de connexion sociale à retenir.
- Stratégie de résolution de conflits pour la synchronisation hors-ligne.
- Modalités précises de la cagnotte (paiement réel ou suivi déclaratif uniquement).
