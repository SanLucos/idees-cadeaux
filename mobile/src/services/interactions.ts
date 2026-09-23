import { api } from './api';
import { uuidv7 } from '../utils/uuidv7';
import type { Comment, Contribution, Idea } from '../types/idea';

/**
 * Lot 4 endpoints (spec §7). Creates carry a client UUID v7 so a retry
 * never doubles a reservation, contribution or comment (spec §8).
 */
export const interactionsApi = {
  reserve(ideaId: string): Promise<Idea> {
    return api.post<Idea>('/reservations', { json: { id: uuidv7(), ideaId } });
  },

  cancelReservation(reservationId: string): Promise<Idea> {
    return api.delete<Idea>(`/reservations/${reservationId}`);
  },

  convertToContribution(reservationId: string): Promise<Contribution> {
    return api.post<Contribution>(`/reservations/${reservationId}/convert-to-contribution`, { json: { id: uuidv7() } });
  },

  openContribution(ideaId: string): Promise<Contribution> {
    return api.post<Contribution>('/contributions', { json: { id: uuidv7(), ideaId } });
  },

  contribution(id: string): Promise<Contribution> {
    return api.get<Contribution>(`/contributions/${id}`);
  },

  setTarget(id: string, targetAmount: string | null): Promise<Contribution> {
    return api.patch<Contribution>(`/contributions/${id}`, { json: { targetAmount } });
  },

  closeContribution(id: string): Promise<Contribution> {
    return api.post<Contribution>(`/contributions/${id}/close`);
  },

  pledge(id: string, amount: string): Promise<Contribution> {
    return api.put<Contribution>(`/contributions/${id}/pledge`, { json: { amount } });
  },

  withdrawPledge(id: string): Promise<Contribution> {
    return api.delete<Contribution>(`/contributions/${id}/pledge`);
  },

  comments(ideaId: string): Promise<Comment[]> {
    return api.get<Comment[]>(`/ideas/${ideaId}/comments`);
  },

  addComment(ideaId: string, body: string): Promise<Comment> {
    return api.post<Comment>('/comments', { json: { id: uuidv7(), ideaId, body } });
  },

  editComment(id: string, body: string): Promise<Comment> {
    return api.patch<Comment>(`/comments/${id}`, { json: { body } });
  },

  deleteComment(id: string): Promise<void> {
    return api.delete(`/comments/${id}`);
  },

  like(ideaId: string): Promise<Idea> {
    return api.put<Idea>(`/ideas/${ideaId}/reaction`);
  },

  unlike(ideaId: string): Promise<Idea> {
    return api.delete<Idea>(`/ideas/${ideaId}/reaction`);
  },
};
