import { useI18n } from 'vue-i18n';
import { ideaSource } from '../utils/ideaTable';
import type { Idea } from '../types/idea';

/**
 * Texts of the idea tables (spec §11 décision 72), shared by the table
 * and by the pages that sort it, so a column sorts as it reads.
 */
export function useIdeaLabels() {
  const { t, te } = useI18n();

  function occasionLabel(idea: Idea): string {
    const code = idea.occasion;
    if (!code) return t('desktop.noOccasion');

    return te(`occasions.${code}`) ? t(`occasions.${code}`) : code;
  }

  /** `ownerName`: whose list it is, for « Idée de X ». */
  function sourceLabel(idea: Idea, ownerName: string | null): string {
    switch (ideaSource(idea)) {
      case 'draft':
        return t('desktop.source.myDraft');
      case 'suggestion':
        return idea.isMine ? t('ideas.suggestedByMe') : t('ideas.suggestedBy', { name: idea.author?.displayName ?? '' });
      default:
        return t('desktop.source.owner', { name: ownerName ?? idea.author?.displayName ?? '' });
    }
  }

  return { occasionLabel, sourceLabel };
}
