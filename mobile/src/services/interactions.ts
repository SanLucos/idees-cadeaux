import { api } from './api';
import { uuidv7 } from '../utils/uuidv7';
import type { Comment, Contribution, Idea } from '../types/idea';

/** Interactions are always the manager's own, never the child's (spec §11 décision 25). */
const NO_ACTING = { acting: false } as const;

/**
 * Lot 4 endpoints (spec §7). Creates carry a client UUID v7 so a retry
 * never doubles a reservation, contribution or comment (spec §8).
 */
export const interactionsApi = {
  reserve(ideaId: string): Promise<Idea> {
    return api.post<Idea>('/reservations', { json: { id: uuidv7(), ideaId }, acting: false });
  },

  cancelReservation(reservationId: string): Promise<Idea> {
    return api.delete<Idea>(`/reservations/${reservationId}`, NO_ACTING);
  },

  convertToContribution(reservationId: string): Promise<Contribution> {
    return api.post<Contribution>(`/reservations/${reservationId}/convert-to-contribution`, { json: { id: uuidv7() }, acting: false });
  },

  openContribution(ideaId: string): Promise<Contribution> {
    return api.post<Contribution>('/contributions', { json: { id: uuidv7(), ideaId }, acting: false });
  },

  contribution(id: string): Promise<Contribution> {
    return api.get<Contribution>(`/contributions/${id}`, NO_ACTING);
  },

  setTarget(id: string, targetAmount: string | null): Promise<Contribution> {
    return api.patch<Contribution>(`/contributions/${id}`, { json: { targetAmount }, acting: false });
  },

  closeContribution(id: string): Promise<Contribution> {
    return api.post<Contribution>(`/contributions/${id}/close`, NO_ACTING);
  },

  pledge(id: string, amount: string): Promise<Contribution> {
    return api.put<Contribution>(`/contributions/${id}/pledge`, { json: { amount }, acting: false });
  },

  withdrawPledge(id: string): Promise<Contribution> {
    return api.delete<Contribution>(`/contributions/${id}/pledge`, NO_ACTING);
  },

  comments(ideaId: string): Promise<Comment[]> {
    return api.get<Comment[]>(`/ideas/${ideaId}/comments`, NO_ACTING);
  },

  addComment(ideaId: string, body: string): Promise<Comment> {
    return api.post<Comment>('/comments', { json: { id: uuidv7(), ideaId, body }, acting: false });
  },

  editComment(id: string, body: string): Promise<Comment> {
    return api.patch<Comment>(`/comments/${id}`, { json: { body }, acting: false });
  },

  deleteComment(id: string): Promise<void> {
    return api.delete(`/comments/${id}`, NO_ACTING);
  },

  like(ideaId: string): Promise<Idea> {
    return api.put<Idea>(`/ideas/${ideaId}/reaction`, NO_ACTING);
  },

  unlike(ideaId: string): Promise<Idea> {
    return api.delete<Idea>(`/ideas/${ideaId}/reaction`, NO_ACTING);
  },
};
