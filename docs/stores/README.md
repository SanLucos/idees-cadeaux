# Préparation des stores (spec §9)

Brouillons à relire avant la première soumission. Tout ce qui dépend du nom définitif, du domaine ou des comptes développeur est marqué **[À COMPLÉTER]**.

- [Étiquettes de confidentialité App Store](app-store-privacy.md)
- [Section « Sécurité des données » Google Play](google-play-data-safety.md)
- [Textes de justification des permissions](permissions.md)

## Liste de contrôle avant soumission

1. **Nom, identifiant et domaine définitifs** (spec §2, §11 décision 11) : `APP_NAME` / `APP_ID` (`mobile/.env`, `backend/.env`), clé i18n `app.name`, `namespace` et `applicationId` (`mobile/android/app/build.gradle`), bundle id des cibles `App` et `ShareExtension` (Xcode), `SHARE_LINK_BASE_URL`, `MEDIA_BASE_URL`, `DEFAULT_URI`.
2. **Liens universels** (spec §11 décision 43) : propriété Gradle `shareLinkHost` ; sur iOS, capacité *Associated Domains* `applinks:<domaine>` sur la cible `App` ; `ANDROID_CERT_FINGERPRINTS` et `APPLE_TEAM_ID` côté back.
3. **Connexion** : `GOOGLE_CLIENT_ID`, `APPLE_CLIENT_ID` ; *Sign in with Apple* activé sur l'App ID (obligatoire dès qu'une connexion Google est proposée).
4. **Push** : projet Firebase, `FCM_PROJECT_ID` / `FCM_SERVICE_ACCOUNT_JSON`, clé APNs téléversée dans Firebase, `google-services.json` / `GoogleService-Info.plist`.
5. **Politique de confidentialité** : compléter les champs `[À COMPLÉTER]` de `backend/templates/legal/privacy.*.html.twig`, faire relire, puis renseigner son URL (`<domaine>/privacy`) dans les deux stores.
6. **Catégorie** : pas de catégorie « Enfants » (spec §9) ; classification d'âge selon le questionnaire de chaque store (contenu généré par les utilisateurs, pas de chat : partage limité aux amis).
7. **Stockage** : bucket privé (`mc anonymous set none`), hébergement dans l'UE, sauvegardes avec rotation ≤ 30 jours (spec §5.13).
8. **Suivi d'erreurs** : brancher le service choisi sur les logs JSON (`php://stderr`, canaux `app` et `client`), durée de conservation des logs à reporter dans la politique.
