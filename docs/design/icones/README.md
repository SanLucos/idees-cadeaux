# Icône Idées Cadeaux : fichiers d'export

Cube-cadeau bleu, ruban rouge en croix, ampoule jaune allumée, sur fond prune.

## iOS
- `ios/AppIcon.appiconset/` : à glisser dans le catalogue d'assets Xcode (icône unique 1024 x 1024, sans transparence).
- Les coins arrondis sont appliqués par le système : ne les ajoute pas.

## Android
- `android/res/` : à fusionner avec le dossier `res/` du projet.
  - `mipmap-*/ic_launcher_foreground.png` et `ic_launcher_background.png` : les deux calques de l'icône adaptative (108 dp).
  - `mipmap-anydpi-v26/ic_launcher.xml` : déclare l'icône adaptative.
  - `mipmap-*/ic_launcher.png` : icône de repli pour Android < 8.
- `android/play-store-512.png` : icône pour la fiche Google Play (512 x 512).
- Dans le manifeste : `android:icon="@mipmap/ic_launcher"`.

## Web
- `web/` : à placer à la racine du site, puis coller `head-snippet.html` dans le `<head>`.
- `favicon.svg` (navigateurs récents), `favicon.ico` (16/32/48 px), `apple-touch-icon.png` (180 px).
- `icon-192.png`, `icon-512.png` : icônes d'application web (coins arrondis).
- `icon-maskable-192.png`, `icon-maskable-512.png` : version « maskable », avec le dessin réduit pour rester dans la zone sûre.
- `site.webmanifest` : adapte `name` et `short_name` si besoin.

## Sources
- `source/*.svg` : versions vectorielles (icône, maskable, calques Android).

## Non inclus
- Variantes sombre / teintée d'iOS 18, icône monochrome d'Android 13 (thèmes).
- Les petites tailles (16 et 32 px) perdent les rayons et le filament de l'ampoule : seule la silhouette reste.
