import type { CapacitorConfig } from '@capacitor/cli';

// L'identifiant et le nom d'app sont provisoires (spec §2 / CLAUDE.md §8) :
// centralisés ici, jamais en dur ailleurs. Surchargeables via .env (non
// versionné) ou variables d'environnement CI, à remplacer avant publication.
try {
  process.loadEnvFile();
} catch {
  // .env absent : on retombe sur les valeurs par défaut ci-dessous.
}

const config: CapacitorConfig = {
  appId: process.env.APP_ID ?? 'fr.frigologie.ideescadeaux',
  appName: process.env.APP_NAME ?? 'Idées Cadeaux',
  webDir: 'dist',
  // Emulator against the local backend (.env.native.local, http://10.0.2.2:8000/api):
  // CAP_LOCAL_BACKEND=1 npx cap sync android. Never for a release build.
  ...('1' === process.env.CAP_LOCAL_BACKEND ? { server: { cleartext: true }, android: { allowMixedContent: true } } : {}),
};

export default config;
