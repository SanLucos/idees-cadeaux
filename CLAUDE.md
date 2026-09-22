# Idées Cadeaux — Contexte projet pour Claude Code

## Objectif
Application mobile (iOS/Android) permettant à un groupe d'amis de partager des idées cadeaux
entre eux, avec réservation, commentaires et cagnottes, sans jamais révéler ces informations
à la personne concernée par ses propres idées.

## Stack technique
- **Back-end** : API Platform (PHP/Symfony)
- **Front-end** : Vue.js
- **App native** : Ionic Vue + Capacitor (build iOS/Android à partir du même code Vue)
- **Mode hors-ligne** : l'app doit fonctionner intégralement hors-ligne, avec synchronisation
  au retour du réseau
- **i18n** : prévue dès le départ, langue de démarrage = français

## Authentification & réseau social
- Connexion obligatoire pour utiliser l'app (pas de mode invité)
- Deux moyens de connexion : email + mot de passe, et connexion sociale (à préciser :
  Google / Apple / Facebook selon besoin)
- Le réseau d'amis se construit par demande puis acceptation (comme un système de contacts
  mutuels, pas un suivi à sens unique)

## Règle métier centrale — Confidentialité des idées cadeaux
C'est LE principe fondateur de l'app, à respecter dans toute la conception technique
(permissions API, requêtes, UI) :

- Un utilisateur peut ajouter des idées cadeaux **pour lui-même** (des choses qu'il aimerait
  recevoir) : ces idées personnelles sont visibles par **tous ses amis**.
- Quand un ami **suggère** une idée cadeau pour quelqu'un, **le destinataire ne doit jamais
  la voir**.
- Le destinataire ne doit également jamais voir :
  - les réservations faites sur ses idées (qui a dit "je m'en occupe")
  - les commentaires laissés par les amis sur ses idées
  - les cagnottes/cotisations organisées autour de ses idées
- Autrement dit : chaque utilisateur voit les idées et l'activité (réservations, commentaires,
  cagnottes) concernant **ses amis**, mais jamais celles qui le concernent lui-même.

Cette règle doit être appliquée au niveau de l'API (filtrage des réponses, pas seulement
côté UI) pour éviter toute fuite d'information via les requêtes réseau.

## Conventions de travail avec Claude Code
- Avancer par petits lots livrables (ex. squelette API → auth → modèle de données →
  endpoint idées → endpoint réservations/commentaires/cagnottes → front).
- Les spécifications détaillées sont dans `docs/specs.md` — s'y référer avant toute
  implémentation de fonctionnalité.
- Toujours vérifier qu'une nouvelle fonctionnalité respecte la règle de confidentialité
  ci-dessus avant de l'implémenter.
