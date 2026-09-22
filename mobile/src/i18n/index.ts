import { createI18n } from 'vue-i18n';
import fr from '../locales/fr.json';
import en from '../locales/en.json';

// Français par défaut (spec §5.14 / CLAUDE.md règle 7) ; l'architecture
// est prête pour d'autres langues sans texte en dur dans les composants.
export const i18n = createI18n({
  legacy: false,
  locale: 'fr',
  fallbackLocale: 'fr',
  messages: { fr, en },
});
