import { api } from './api';
import type { AdminMode, AdminUserCard, AdminUserFilter, AdminUserPage } from '../types/admin';

/** Never on behalf of a child: no X-Acting-As (spec §5.17). Online only. */
const OWN = { acting: false } as const;

export interface AdminUserQuery {
  q?: string;
  type?: AdminUserFilter;
  sort?: 'createdAt' | 'displayName';
  page?: number;
  relativeTo?: string;
}

export const adminApi = {
  users(query: AdminUserQuery = {}): Promise<AdminUserPage> {
    const params = new URLSearchParams();
    if (query.q?.trim()) params.set('q', query.q.trim());
    if (query.type && 'all' !== query.type) params.set('type', query.type);
    if (query.sort) params.set('sort', query.sort);
    if (query.page && query.page > 1) params.set('page', String(query.page));
    if (query.relativeTo) params.set('relativeTo', query.relativeTo);
    const search = params.toString();

    return api.get<AdminUserPage>(`/admin/users${search ? `?${search}` : ''}`, OWN);
  },

  user(id: string): Promise<AdminUserCard> {
    return api.get<AdminUserCard>(`/admin/users/${id}`, OWN);
  },

  /** Every pair among `userIds`. */
  connect(userIds: string[], mode: AdminMode): Promise<{ done: number; unavailable: number }> {
    return api.post('/admin/connections', { json: { users: userIds, mode }, ...OWN });
  },

  async removeFriendship(id: string): Promise<void> {
    await api.delete(`/admin/friendships/${id}`, OWN);
  },

  async cancelIntroduction(id: string): Promise<void> {
    await api.delete(`/admin/introductions/${id}`, OWN);
  },
};
