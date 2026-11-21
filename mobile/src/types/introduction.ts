import type { SuggestedPerson } from './friendSuggestion';

/**
 * A team introduction I still have to answer (spec §5.17, §11 décision
 * 71): from « l'équipe », with the other person's pseudo and avatar only.
 */
export interface Introduction {
  id: string;
  createdAt: string;
  user: SuggestedPerson;
}
