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
  appId: process.env.APP_ID ?? 'com.example.ideescadeaux',
  appName: process.env.APP_NAME ?? 'Idées Cadeaux',
  webDir: 'dist',
};

export default config;
