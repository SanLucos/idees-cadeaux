# Lot 15 — Idées communes à plusieurs destinataires

Référence : spec §11 décision 69 (et §4, §5.4, §5.16, §6). Ce document est le plan de travail du lot ; la spec prime.

## Principe technique

`Idea.owner` (un seul destinataire) est remplacé par une collection `Idea.recipients` (entité `IdeaRecipient`). Toute règle qui disait « est le propriétaire » devient « est l'un des destinataires ». Supprimer `owner` plutôt que le garder comme « destinataire principal » oblige à revoir chaque usage : aucun oubli silencieux ne peut laisser fuiter une suggestion.

### Vue d'une idée pour un lecteur (`IdeaAccess::viewOf`)

`human` = l'adulte derrière la requête (le gestionnaire quand il agit au nom d'un enfant). `R` = destinataires.

1. `human` ∈ R ou lecteur ∈ R sans agir au nom d'un enfant → **propriétaire** si l'idée est personnelle (auteur ∈ R) et publiée, ou si c'est son brouillon (auteur = lecteur, ou enfant géré par `human`) ; sinon **rien** (suggestion : règle d'or).
2. Un destinataire est un enfant géré par `human` → **gestionnaire** (idée publiée, brouillon de l'enfant, ou écrit par `human`).
3. Lecteur = auteur → **ami** (son brouillon, ou sa suggestion publiée tant qu'il est ami d'un destinataire).
4. Idée publiée et lecteur ami d'au moins un destinataire → **ami**.
5. Sinon **rien** (404).

Une seule fonction, utilisée par les endpoints, la sérialisation, `/sync`, les notifications et les compteurs. Les requêtes SQL des listes en reprennent les règles, vérifiées par la suite de visibilité.

### Droits

- Modifier les champs, supprimer, marquer « reçue » : tout destinataire d'une idée personnelle (au nom d'un enfant : en agissant comme lui) ; l'auteur seul pour une suggestion.
- Publier, repasser en privé, changer les destinataires : l'auteur seul. Les destinataires ne changent que sur une idée **privée** (personne d'autre ne la voit encore).
- Interactions (réserver, cotiser, commenter, réagir) : quiconque a la vue ami ou gestionnaire ; jamais un destinataire.

## Étapes (un commit chacune, suite verte à chaque fois)

1. **Spec et plan** — décision 69, lot 15, ce document.
2. **Modèle** — entité `IdeaRecipient` (UUID, horodatage, suppression logique, unicité (idée, utilisateur) active) ; `Idea::getRecipients()`, `hasRecipient()`, `isSuggestion()` = auteur hors des destinataires ; migration : table créée, une ligne par idée existante depuis `owner_id`, puis `owner_id` supprimé.
3. **Accès et listes** — `IdeaAccess::viewOf` et droits ci-dessus ; `IdeaRepository` (ma liste, liste d'un ami, vue gestionnaire, compteurs « N idées » calculés pour le lecteur) ; une suggestion qui me vise n'apparaît jamais sur la liste d'un autre destinataire, ni dans son compteur.
4. **API idées** — création et modification avec `recipientIds` (`ownerId` accepté comme raccourci d'un seul destinataire), contrôles (amitié ou gestion avec chaque destinataire, l'enfant parmi les destinataires quand on agit en son nom, changement de destinataires seulement en privé et par l'auteur) ; sortie `recipients` (id, pseudo, avatar) à la place de `ownerId`.
5. **Interactions, notifications, effets** — interactions refusées (404) à tout destinataire ; notifications « nouvelle idée » envoyées une fois à l'union des amis des destinataires, destinataires exclus, citant tous les destinataires ; retrait d'ami : actions annulées seulement si plus aucun accès ; suppression de compte : retrait du destinataire, idée publiée qu'il avait écrite passée à un autre destinataire, idée supprimée s'il n'en reste aucun.
6. **Sync, export, vue invité** — `/sync` (versions qui bougent avec chaque destinataire), export RGPD (« pour » listant les destinataires), vue invité sans autres destinataires.
7. **Suite de visibilité** — chaque destinataire, ami d'un seul destinataire, non-ami de tous, gestionnaire destinataire, enfant co-destinataire, compteurs, notifications, `/sync`, export, vue invité.
8. **Appli** — types (`recipients`), base locale et `repo` (une idée sur la liste de chacun de ses destinataires), formulaire (choix multiple : moi, mes enfants, mes amis ; figé une fois publiée), mention « pour Anne et Luc » sur les cartes et le détail, « Mes cadeaux » (groupe des destinataires), brouillons groupés, i18n.
9. **Tests front et e2e** — Vitest (repo, myGifts, formulaire) ; parcours Cypress à trois.

## Hors périmètre

Couple déclaré, plus de règles de famille, transfert d'auteur, changement de destinataires d'une idée publiée.
