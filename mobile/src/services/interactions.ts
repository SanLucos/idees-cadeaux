import { api } from './api';
import { repo } from '../offline/repo';
import { interactionMutations } from '../offline/mutations';
import type { Comment, Contribution, Idea } from '../types/idea';

/**
 * Lot 4 interactions, offline-capable (spec §8): read from the device,
 * written through the outbox with client ids and Idempotency-Keys.
 * Always the adult's own (never X-Acting-As, spec §11 décision 25):
 * the outbox records no acting profile for these.
 */
export const interactionsApi = {
  reserve: (ideaId: string): Promise<Idea> => interactionMutations.reserve(ideaId),
  cancelReservation: (reservationId: string): Promise<Idea> => interactionMutations.cancelReservation(reservationId),
  convertToContribution: (reservationId: string): Promise<Contribution> => interactionMutations.convertToContribution(reservationId),
  openContribution: (ideaId: string): Promise<Contribution> => interactionMutations.openContribution(ideaId),

  async contribution(id: string): Promise<Contribution> {
    return repo.contribution(id) ?? api.get<Contribution>(`/contributions/${id}`, { acting: false });
  },

  setTarget: (id: string, targetAmount: string | null): Promise<Contribution> => interactionMutations.setTarget(id, targetAmount),
  closeContribution: (id: string): Promise<Contribution> => interactionMutations.closeContribution(id),
  pledge: (id: string, amount: string): Promise<Contribution> => interactionMutations.pledge(id, amount),
  withdrawPledge: (id: string): Promise<Contribution> => interactionMutations.withdrawPledge(id),

  async comments(ideaId: string): Promise<Comment[]> {
    return repo.comments(ideaId);
  },

  addComment: (ideaId: string, body: string): Promise<Comment> => interactionMutations.addComment(ideaId, body),
  editComment: (id: string, body: string): Promise<Comment> => interactionMutations.editComment(id, body),
  deleteComment: (id: string): Promise<void> => interactionMutations.deleteComment(id),
  like: (ideaId: string): Promise<Idea> => interactionMutations.like(ideaId),
  unlike: (ideaId: string): Promise<Idea> => interactionMutations.unlike(ideaId),
};
