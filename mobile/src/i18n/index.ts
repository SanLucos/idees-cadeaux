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

export const SUPPORTED_LOCALES = ['fr', 'en'] as const;
export type SupportedLocale = (typeof SUPPORTED_LOCALES)[number];

/** Follows User.locale (spec §5.14); unknown values keep the current language. */
export function applyLocale(locale: string | null | undefined): void {
  if (!locale || !(SUPPORTED_LOCALES as readonly string[]).includes(locale)) return;
  i18n.global.locale.value = locale as SupportedLocale;
  document.documentElement.lang = locale;
}
