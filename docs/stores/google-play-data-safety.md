# Google Play — « Sécurité des données » (brouillon)

- **Chiffrement en transit** : oui (HTTPS partout).
- **Suppression des données** : oui, depuis l'app (Profil → Supprimer mon compte, 14 jours de grâce) ; URL de demande de suppression hors app : **[À COMPLÉTER]** (Google l'exige : page web expliquant la démarche, par ex. `https://<domaine>/privacy#droits`).
- **Partage avec des tiers** : non (les sous-traitants — hébergeur, email, FCM — ne comptent pas comme partage).

| Type Google | Donnée | Collectée | Obligatoire | Finalité |
|---|---|---|---|---|
| Informations personnelles | Adresse email | Oui | Oui | Gestion du compte |
| Informations personnelles | Nom (pseudo) | Oui | Oui | Fonctionnalités de l'app |
| Informations personnelles | Autres infos (anniversaire, tailles, préférences) | Oui | Non | Fonctionnalités de l'app |
| Photos et vidéos | Photos | Oui | Non | Fonctionnalités de l'app |
| Messages | Autres messages (commentaires) | Oui | Non | Fonctionnalités de l'app |
| Contacts | Contacts | **Non** (traités sur l'appareil, seules des empreintes éphémères sont envoyées : à déclarer « traitées de façon éphémère » si Google le demande) | — | — |
| Infos et performances de l'app | Journaux de plantage, diagnostics | Oui | Oui | Analyse (correction d'erreurs) |
| Identifiants de l'appareil | Jeton de notification push | Oui | Non | Fonctionnalités de l'app (notifications) |

- **Public cible** : adultes ; l'app n'est pas conçue pour les enfants (profils enfants gérés par un adulte, spec §5.15). Répondre au questionnaire « Public cible et contenu » en conséquence.
claude