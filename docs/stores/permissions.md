# Textes de justification des permissions (spec §9)

| Permission | Plateforme | Quand elle est demandée | Texte (fr) | Texte (en) |
|---|---|---|---|---|
| Contacts | iOS `NSContactsUsageDescription`, Android `READ_CONTACTS` | Au clic sur « Mes contacts » (Amis) | Pour retrouver vos amis déjà inscrits. Seules des empreintes anonymes des adresses email sont comparées ; aucun contact n'est envoyé ni conservé. | To find your friends who already use the app. Only anonymous fingerprints of email addresses are compared; no contact is sent or kept. |
| Photos | iOS `NSPhotoLibraryUsageDescription` (Android : sélecteur système, sans permission) | Au choix d'une image (idée, avatar) | Pour ajouter une photo à une idée cadeau ou à votre profil. | To add a photo to a gift idea or to your profile. |
| Appareil photo | iOS `NSCameraUsageDescription` | Si l'utilisateur choisit « Prendre une photo » dans le sélecteur | Pour prendre en photo une idée cadeau ou pour votre profil. | To take a photo of a gift idea or for your profile. |
| Notifications | iOS (demande système), Android 13+ `POST_NOTIFICATIONS` | Après le consentement in-app (spec §5.11, décision 28) | Texte de l'écran de consentement de l'app (`consent.*`) | idem |

Les textes iOS sont dans `mobile/ios/App/App/Info.plist` (anglais, langue de développement) et `mobile/ios/App/App/{en,fr}.lproj/InfoPlist.strings`.
