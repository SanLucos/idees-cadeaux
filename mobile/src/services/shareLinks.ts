import { api } from './api';
import type { GuestView, ShareLink, ShareLinkInvitation } from '../types/shareLink';

/**
 * Spec §5.16. The owner's side follows the active profile (a child's
 * link through X-Acting-As) unless `actingAs` names one; joining is
 * always done as the signed-in adult.
 */
export const shareLinksApi = {
  async mine(actingAs?: string | null): Promise<ShareLink | null> {
    return (await api.get<{ link: ShareLink | null }>('/share-link', { actingAs })).link;
  },
  async create(actingAs?: string | null): Promise<ShareLink> {
    return (await api.post<{ link: ShareLink }>('/share-link', { json: { confirmed: true }, actingAs })).link;
  },
  async regenerate(actingAs?: string | null): Promise<ShareLink> {
    return (await api.post<{ link: ShareLink }>('/share-link/regenerate', { actingAs })).link;
  },
  async disable(actingAs?: string | null): Promise<void> {
    await api.delete('/share-link', { actingAs });
  },

  guestView(token: string, query: { occasion?: string | null; sort?: string; page?: number } = {}): Promise<GuestView> {
    const params = new URLSearchParams();
    if (query.occasion) params.set('occasion', query.occasion);
    if (query.sort && 'recent' !== query.sort) params.set('sort', query.sort);
    if (query.page && query.page > 1) params.set('page', String(query.page));
    const search = params.toString();

    return api.get<GuestView>(`/share-links/${encodeURIComponent(token)}${search ? `?${search}` : ''}`, { auth: false });
  },
  invitation(token: string): Promise<ShareLinkInvitation> {
    return api.get<ShareLinkInvitation>(`/share-links/${encodeURIComponent(token)}/invitation`, { acting: false });
  },
  join(token: string): Promise<ShareLinkInvitation> {
    return api.post<ShareLinkInvitation>(`/share-links/${encodeURIComponent(token)}/join`, { acting: false });
  },
};
