import { useI18n } from 'vue-i18n';
import { ApiError } from '../services/api';

/**
 * Maps a backend problem+json `code` (CLAUDE.md règle 7: stable,
 * translatable codes) to a localized message, via auth.errors.* /
 * auth.validation.* keys mirroring the code's own dot-namespacing.
 */
export function useErrorMessage() {
  const { t, te } = useI18n();

  function describe(error: unknown): string {
    if (error instanceof ApiError) {
      const key = `errors.${error.code}`;
      if (te(key)) {
        return t(key);
      }
    }

    return t('errors.generic');
  }

  return { describe };
}
